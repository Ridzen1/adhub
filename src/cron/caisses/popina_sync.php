<?php
/**
 * Synchronisation Popina (back-office Jalia) -> MariaDB
 *
 * Récupère, jour par jour, pour chaque établissement du compte :
 *   - l'export « Produits »      : chaque ligne de ticket (articles, paiements, rendu monnaie, total)
 *   - l'export « Comptabilité »  : le Z de caisse (totaux, TVA, modes de paiement, couverts…)
 *
 * Puis reporte le résumé de chaque journée dans daily_entries (dashboard ADHUB),
 * pour les établissements reliés à une société dans config/caisses.local.php.
 *
 * Usage :
 *   php cron/caisses/popina_sync.php                          -> la veille
 *   php cron/caisses/popina_sync.php 2026-10-01 2026-10-22    -> une période (traitée jour par jour)
 *   ajouter --force pour écraser aussi les journées saisies à la main
 *
 * Cron (tous les jours à 6h) :
 *   0 6 * * * php ~/adhub/cron/caisses/popina_sync.php >> ~/logs/caisses.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require __DIR__ . '/xlsx_reader.php';
require_once __DIR__ . '/../../inc/caisses.php';
$config = require (getenv('CAISSES_CONFIG') ?: __DIR__ . '/../../config/caisses.local.php');

define('JALIA_URL', rtrim($config['popina_base_url'] ?? 'https://backoffice.jalia.fr', '/'));
const JALIA_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

// ------------------------------------------------------------------ utilitaires

function plog(string $msg): void
{
    global $config;
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line;
    if (!empty($config['log_file'])) {
        file_put_contents($config['log_file'], $line, FILE_APPEND);
    }
}

/** Requête HTTP avec cookies de session. Renvoie [code, corps, url finale, content-type]. */
function jalia(string $method, string $path, string $jar, ?array $form = null, array $headers = []): array
{
    $ch = curl_init(JALIA_URL . $path);
    $opts = [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS      => 5,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_COOKIEJAR      => $jar,
        CURLOPT_COOKIEFILE     => $jar,
        CURLOPT_HTTPHEADER     => array_merge(['User-Agent: ' . JALIA_UA], $headers),
    ];
    if ($form !== null) {
        $opts[CURLOPT_POSTFIELDS] = formEncode($form);
        $opts[CURLOPT_HTTPHEADER][] = 'Content-Type: application/x-www-form-urlencoded';
        $opts[CURLOPT_HTTPHEADER][] = 'Origin: ' . JALIA_URL;
    }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $url  = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $ct   = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($body === false) throw new RuntimeException("Erreur réseau sur $path : $err");
    return [$code, $body, $url, $ct];
}

/** Encode un formulaire en gardant les champs répétés (champ[] = plusieurs valeurs). */
function formEncode(array $form): string
{
    $parts = [];
    foreach ($form as [$k, $v]) $parts[] = rawurlencode($k) . '=' . rawurlencode((string) $v);
    return implode('&', $parts);
}

function csrfFrom(string $html): string
{
    if (!preg_match('/name="authenticity_token" value="([^"]+)"/', $html, $m)) {
        throw new RuntimeException('Jeton CSRF introuvable (page inattendue)');
    }
    return html_entity_decode($m[1], ENT_QUOTES);
}

function isLoginPage(string $html, string $url): bool
{
    return str_contains($url, '/session/') || str_contains($html, 'action="/fr/session/login"');
}

// --------------------------------------------------------------- authentification

function jaliaLogin(string $email, string $password, string $jar): void
{
    [, $html] = jalia('GET', '/fr/session/login', $jar);
    $csrf = csrfFrom($html);
    [$code, $html, $url] = jalia('POST', '/fr/session/login', $jar, [
        ['utf8', '✓'], ['authenticity_token', $csrf], ['email', $email], ['password', $password], ['commit', 'Connexion'],
    ]);
    if ($code >= 500 || isLoginPage($html, $url)) {
        throw new RuntimeException("Échec de connexion à Popina (HTTP $code) : identifiants refusés ?");
    }
}

function jaliaLogout(string $jar): void
{
    try { jalia('GET', '/fr/session/logout', $jar); } catch (Throwable $e) { /* pas bloquant */ }
}

/** Établissements visibles par le compte : [id => nom]. */
function jaliaEstablishments(string $jar): array
{
    [, $html, $url] = jalia('GET', '/fr/dashboard/account', $jar); // la page compte liste les établissements
    if (isLoginPage($html, $url)) throw new RuntimeException('Session expirée');
    preg_match_all('#/establishment/(\d+)#', $html . ' ' . $url, $m);
    $ids = array_values(array_unique($m[1]));
    $out = [];
    foreach ($ids as $id) $out[(int) $id] = "Établissement $id";
    return $out;
}

// --------------------------------------------------------------------- exports

/** Paramètres communs des filtres (tous jours, toutes heures, tout le monde, toutes salles). */
function filterQuery(string $dFr): string
{
    return http_build_query([
        'date_start' => $dFr, 'date_end' => $dFr, 'days_of_week' => '1,2,3,4,5,6,0',
        'from_hour' => 0, 'to_hour' => 24, 'device_name' => '', 'venue_ids' => '',
        'order_from_hour' => 0, 'order_to_hour' => 24, 'order_by' => 'Tous', 'order_in' => 'Tous',
    ]);
}

/** Catégories (macro) présentes sur la période : obligatoires pour l'export Produits. */
function jaliaCategories(int $etab, string $dFr, string $jar): array
{
    [$code, $html] = jalia('GET', "/fr/dashboard/establishment/$etab/statistics_all_categories_data?" . filterQuery($dFr), $jar,
        null, ['X-Requested-With: XMLHttpRequest']);
    if ($code !== 200) throw new RuntimeException("Catégories : HTTP $code");
    preg_match_all('/name="macro_names\[\]"[^>]*value="([^"]*)"|value="([^"]*)"[^>]*name="macro_names\[\]"/', $html, $m, PREG_SET_ORDER);
    $cats = [];
    foreach ($m as $x) $cats[] = html_entity_decode($x[1] !== '' ? $x[1] : ($x[2] ?? ''), ENT_QUOTES);
    return array_values(array_unique(array_filter($cats, fn($c) => $c !== '')));
}

/** Télécharge un export Excel ($button = export_items | export_accounting) pour une journée. */
function jaliaExport(int $etab, string $dFr, string $button, array $categories, string $jar): array
{
    [, $html, $url] = jalia('GET', "/fr/dashboard/establishment/$etab/statistics", $jar);
    if (isLoginPage($html, $url)) throw new RuntimeException('Session expirée');
    $form = [
        ['utf8', '✓'], ['authenticity_token', csrfFrom($html)], ['establishment_id', $etab],
        ['date_start', $dFr], ['date_end', $dFr],
        ['from_hour', 0], ['to_hour', 24], ['order_from_hour', 0], ['order_to_hour', 24],
        ['order_by', 'Tous'], ['order_in', 'Tous'],
    ];
    foreach ([1, 2, 3, 4, 5, 6, 0] as $d) $form[] = ['day_of_week[]', $d];
    foreach ($categories as $c) $form[] = ['macro_names[]', $c];
    $form[] = [$button, ''];

    [$code, $bin, , $ct] = jalia('POST', '/fr/dashboard/export_tills.xlsx', $jar, $form);
    if ($code !== 200 || substr($bin, 0, 2) !== 'PK') {
        throw new RuntimeException("Export $button : réponse inattendue (HTTP $code, $ct)");
    }
    return xlsxRows($bin);
}

// ------------------------------------------------------------------ conversions

function frDate(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    $d = DateTime::createFromFormat('d/m/Y H:i', trim($v)) ?: DateTime::createFromFormat('d/m/Y H:i:s', trim($v));
    return $d ? $d->format('Y-m-d H:i:s') : null;
}

function f($v): ?float
{
    if ($v === null || $v === '' || is_bool($v)) return null;
    return (float) str_replace([',', ' '], ['.', ''], (string) $v);
}

/** Colonnes de l'export Produits (l'en-tête n'a que 19 noms, les 4 dernières colonnes sont sans titre). */
const ITEM_COLS = [
    'jour' => 0, 'caisse' => 1, 'ticket_id' => 2, 'ticket_date' => 3, 'fiscal' => 4, 'salle' => 5, 'table' => 6,
    'serveur' => 7, 'date' => 8, 'parent' => 9, 'cat' => 10, 'name' => 11, 'unit' => 12, 'tier' => 13, 'tax' => 14,
    'quantity' => 15, 'cancelled' => 16, 'transferred' => 17, 'brut' => 18, 'offert' => 19, 'net' => 20,
    'taxe' => 21, 'ht' => 22,
];

function itemType(string $cat): string
{
    return match ($cat) { 'Paiement' => 'paiement', 'Rendu' => 'rendu', 'Total' => 'total', default => 'article' };
}

// ----------------------------------------------------------------- enregistrement

function saveItems(PDO $pdo, int $etab, string $day, array $rows): array
{
    $c = ITEM_COLS;
    $pdo->prepare('DELETE FROM popina_lignes WHERE id_etablissement = ? AND jour_caisse = ?')->execute([$etab, $day]);
    $ins = $pdo->prepare(
        'INSERT INTO popina_lignes
          (id_etablissement, jour_caisse, ouverture_caisse, num_ligne, ticket_id, ticket_date, num_fiscal, salle, table_nom,
           serveur, date_ligne, type_ligne, categorie_parent, categorie, nom, prix_unitaire, tarif, taux_tva, quantite,
           annule, transfere, brut_ttc, offert_ttc, net_ttc, tva, ht)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
    );
    $stats = ['articles' => 0, 'brut' => 0.0, 'offert' => 0.0, 'net' => 0.0, 'paiements' => 0.0];
    $n = 0;
    foreach (array_slice($rows, 1) as $r) {
        if (($r[0] ?? '') === 'Total' || ($r[2] ?? null) === null || $r[2] === '') continue; // ligne de total général / vide
        $type = itemType((string) ($r[$c['cat']] ?? ''));
        $n++;
        $ins->execute([
            $etab, $day, frDate($r[$c['caisse']] ?? null), $n,
            (int) $r[$c['ticket_id']], frDate($r[$c['ticket_date']] ?? null),
            ($r[$c['fiscal']] ?? '') === '' ? null : (string) $r[$c['fiscal']],
            $r[$c['salle']] ?? null, ($r[$c['table']] ?? '') === '' ? null : (string) $r[$c['table']],
            $r[$c['serveur']] ?? null, frDate($r[$c['date']] ?? null), $type,
            ($r[$c['parent']] ?? '') ?: null, ($r[$c['cat']] ?? '') ?: null, ($r[$c['name']] ?? '') ?: null,
            f($r[$c['unit']] ?? null), ($r[$c['tier']] ?? '') ?: null, f($r[$c['tax']] ?? null), f($r[$c['quantity']] ?? null),
            ($r[$c['cancelled']] ?? false) === true ? 1 : 0, ($r[$c['transferred']] ?? false) === true ? 1 : 0,
            f($r[$c['brut']] ?? null), f($r[$c['offert']] ?? null), f($r[$c['net']] ?? null),
            f($r[$c['taxe']] ?? null), f($r[$c['ht']] ?? null),
        ]);
        if ($type === 'article') {
            $stats['articles']++;
            $stats['brut']   += f($r[$c['brut']] ?? null) ?? 0;
            $stats['offert'] += f($r[$c['offert']] ?? null) ?? 0;
            $stats['net']    += f($r[$c['net']] ?? null) ?? 0;
        } elseif ($type === 'paiement' || $type === 'rendu') {
            $stats['paiements'] += f($r[$c['brut']] ?? null) ?? 0;
        }
    }
    return $stats;
}

/**
 * Export Comptabilité : 2 lignes d'en-tête (groupe / sous-colonne), puis 1 ligne par caisse (Z).
 * Les colonnes varient selon le restaurant (taux de TVA, modes de paiement, salles, catégories),
 * d'où une lecture par nom de groupe plutôt que par position.
 */
function saveAccounting(PDO $pdo, int $etab, string $day, array $rows): array
{
    if (count($rows) < 3) return ['caisses' => 0, 'net' => 0.0];
    $groups = [];
    $current = '';
    foreach ($rows[0] as $i => $g) {
        if ($g !== null && $g !== '') $current = trim((string) $g);
        $groups[$i] = $current;
    }
    $subs = array_map(fn($s) => trim((string) $s), $rows[1]);

    $pdo->prepare('DELETE FROM popina_caisses WHERE id_etablissement = ? AND jour_caisse = ?')->execute([$etab, $day]);
    $pdo->prepare('DELETE FROM popina_caisses_paiements WHERE id_etablissement = ? AND jour_caisse = ?')->execute([$etab, $day]);
    $pdo->prepare('DELETE FROM popina_caisses_tva WHERE id_etablissement = ? AND jour_caisse = ?')->execute([$etab, $day]);

    $insC = $pdo->prepare('INSERT INTO popina_caisses (id_etablissement, jour_caisse, ouverture, fermeture, appareil,
        brut_ttc, brut_ht, brut_tva, reductions_ttc, net_ttc, net_ht, net_tva, pourboires, couverts, commandes,
        mouvements, ecart, detail_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insP = $pdo->prepare('INSERT INTO popina_caisses_paiements (id_etablissement, jour_caisse, ouverture, mode, montant) VALUES (?,?,?,?,?)');
    $insT = $pdo->prepare('INSERT INTO popina_caisses_tva (id_etablissement, jour_caisse, ouverture, type, libelle, ttc, ht, tva) VALUES (?,?,?,?,?,?,?,?)');

    $nb = 0;
    $net = 0.0;
    foreach (array_slice($rows, 2) as $r) {
        if (($r[0] ?? null) === null || $r[0] === '' || $r[0] === 'Total') continue;
        $get = function (string $group, string $sub) use ($r, $groups, $subs) {
            foreach ($groups as $i => $g) if ($g === $group && $subs[$i] === $sub) return $r[$i] ?? null;
            return null;
        };
        $detail = [];
        $pay = [];
        $vat = [];
        foreach ($groups as $i => $g) {
            $key = $g . ($subs[$i] !== '' ? " | {$subs[$i]}" : '');
            $detail[$key] = $r[$i] ?? null;
            if ($g === 'Modes de paiement' && $subs[$i] !== '') $pay[$subs[$i]] = f($r[$i] ?? null) ?? 0.0;
            if (preg_match('/^(Remise )?TVA (.+?) \(€\)$/u', $g, $m) && in_array($subs[$i], ['TTC', 'HT', 'Taxe'], true)) {
                $vat[($m[1] ? 'remise' : 'vente') . '|' . $m[2]][$subs[$i]] = f($r[$i] ?? null);
            }
        }
        $ouv = frDate((string) $r[0]);
        $insC->execute([
            $etab, $day, $ouv, frDate((string) ($r[2] ?? '')), $r[4] ?? null,
            f($get('Total Brut', 'TTC')), f($get('Total Brut', 'HT')), f($get('Total Brut', 'Taxe')),
            f($get('Total réductions', 'TTC')),
            f($get('Total Net', 'TTC')), f($get('Total Net', 'HT')), f($get('Total Net', 'Taxe')),
            f($get('Pourboires', 'Total pourboires')),
            ($v = $get('Couverts', 'Nombre')) === null ? null : (int) $v,
            ($v = $get('Commandes', 'Nombre')) === null ? null : (int) $v,
            f($get('Mouvements', 'Montant')), f($get('Ecart', 'Montant')),
            json_encode($detail, JSON_UNESCAPED_UNICODE),
        ]);
        foreach ($pay as $mode => $montant) $insP->execute([$etab, $day, $ouv, $mode, $montant]);
        foreach ($vat as $k => $v) {
            [$type, $lib] = explode('|', $k, 2);
            $insT->execute([$etab, $day, $ouv, $type, $lib, $v['TTC'] ?? null, $v['HT'] ?? null, $v['Taxe'] ?? null]);
        }
        $nb++;
        $net += f($get('Total Net', 'TTC')) ?? 0;
    }
    return ['caisses' => $nb, 'net' => $net];
}

function logRunPopina(PDO $pdo, string $account, string $day, int $n, string $status, ?string $msg): void
{
    $pdo->prepare('INSERT INTO sync_runs (source, account, date_from, date_to, nb_lines, status, message) VALUES (?,?,?,?,?,?,?)')
        ->execute(['popina', $account, $day, $day, $n, $status, $msg]);
}

/** Synchronise une journée pour un établissement. Renvoie le résumé. */
function syncDay(PDO $pdo, int $etab, string $day, string $jar): array
{
    $dFr  = DateTime::createFromFormat('Y-m-d', $day)->format('d.m.Y');
    $cats = jaliaCategories($etab, $dFr, $jar);
    $acc  = jaliaExport($etab, $dFr, 'export_accounting', $cats, $jar);
    $items = $cats ? jaliaExport($etab, $dFr, 'export_items', $cats, $jar) : [];

    $pdo->beginTransaction();
    try {
        $z = saveAccounting($pdo, $etab, $day, $acc);
        $s = $items ? saveItems($pdo, $etab, $day, $items)
                    : ['articles' => 0, 'brut' => 0.0, 'offert' => 0.0, 'net' => 0.0, 'paiements' => 0.0];
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    // Contrôle : la somme des articles doit égaler le Z de caisse
    $ecart = round($s['net'] - $z['net'], 2);
    return $s + ['caisses' => $z['caisses'], 'z_net' => $z['net'], 'ecart' => $ecart];
}

// ------------------------------------------------------------------------ main

if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $args  = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--force'));
    $force = in_array('--force', $argv, true);
    $from = $args[0] ?? date('Y-m-d', strtotime('-1 day'));
    $to   = $args[1] ?? $from;
    foreach ([$from, $to] as $d) {
        $dt = DateTime::createFromFormat('!Y-m-d', $d);
        if (!$dt || $dt->format('Y-m-d') !== $d) { // refuse aussi les dates impossibles (32/10…)
            fwrite(STDERR, "Date invalide : $d (format AAAA-MM-JJ)\n");
            exit(1);
        }
    }
    if ($from > $to) { fwrite(STDERR, "La date de début est après la date de fin\n"); exit(1); }

    try {
        $pdo = get_db();
    } catch (PDOException $e) {
        plog('ERREUR base de données : ' . $e->getMessage());
        exit(1);
    }

    $exit = 0;
    foreach ($config['popina_accounts'] ?? [] as $acc) {
        $label = $acc['label'];
        $jar = tempnam(sys_get_temp_dir(), 'jal');
        $logged = false;
        try {
            jaliaLogin($acc['email'], $acc['password'], $jar);
            $logged = true;
            // 'etablissements' => [id Popina => id société ADHUB] ; sinon tous ceux du compte
            $liens = $acc['etablissements'] ?? [];
            $etabs = $liens ?: jaliaEstablishments($jar);
            if (!$etabs) throw new RuntimeException('Aucun établissement trouvé sur ce compte');
            foreach ($liens as $idPopina => $projectId) {
                caisse_relier('popina', (int) $idPopina, (int) $projectId, $label);
            }

            foreach (array_keys($etabs) as $etab) {
                for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime("$day +1 day"))) {
                    try {
                        $r = syncDay($pdo, (int) $etab, $day, $jar);
                        $msg = sprintf('%s / %d / %s : %d caisse(s), %d articles, net %.2f € (Z %.2f €)%s',
                            $label, $etab, $day, $r['caisses'], $r['articles'], $r['net'], $r['z_net'],
                            $r['ecart'] != 0.0 ? "  ⚠ ÉCART {$r['ecart']} €" : '');
                        plog($msg);
                        logRunPopina($pdo, "$label/$etab", $day, $r['articles'], $r['ecart'] == 0.0 ? 'ok' : 'error',
                            $r['ecart'] == 0.0 ? null : "Écart articles/Z : {$r['ecart']} €");
                        if ($r['ecart'] != 0.0) $exit = 1;
                        $pid = caisse_projet('popina', (int) $etab);
                        plog("   → dashboard : " . ($pid ? "société $pid, " . caisse_maj_saisie($pid, $day, $force)
                                                          : "établissement $etab non relié à une société (config)"));
                    } catch (Throwable $e) {
                        $exit = 1;
                        plog("ERREUR $label / $etab / $day : " . $e->getMessage());
                        logRunPopina($pdo, "$label/$etab", $day, 0, 'error', $e->getMessage());
                        if (str_contains($e->getMessage(), 'Session expirée')) throw $e;
                    }
                    if ($day < $to) sleep(1); // rester discret
                }
            }
        } catch (Throwable $e) {
            $exit = 1;
            plog("ERREUR $label : " . $e->getMessage());
            logRunPopina($pdo, $label, $from, 0, 'error', $e->getMessage());
        } finally {
            if ($logged) jaliaLogout($jar);
            @unlink($jar);
        }
    }
    // Le suivi mensuel du tableau de bord reprend le CA des caisses
    try {
        require_once __DIR__ . '/../../inc/pennylane.php';
        plog('Suivi mensuel : ' . pennylane_alimenter_suivi_mensuel() . ' mois mis à jour');
    } catch (Throwable $e) {
        plog('Suivi mensuel non mis à jour : ' . $e->getMessage());
    }
    exit($exit);
}
