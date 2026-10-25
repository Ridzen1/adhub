<?php

/**
 * Synchronisation quotidienne des caisses et de la comptabilité.
 *
 *   Popina     → daily_entries (source 'api') : CA, couverts, HT, remises, solide / liquide
 *   L'Addition → daily_entries (source 'api') : en attente de la documentation partenaire
 *   Pennylane  → financial_entries du mois : revenus = factures clients HT,
 *                charges = factures fournisseurs HT
 *
 * Lancé par une tâche cron, jamais depuis le navigateur :
 *   php cron/sync_api.php                     → la veille
 *   php cron/sync_api.php --date=2026-10-17   → un jour précis
 *   php cron/sync_api.php --days=30           → rattrapage des 30 derniers jours
 *   php cron/sync_api.php --only=popina       → une seule source
 *   php cron/sync_api.php --dry-run           → affiche sans rien écrire
 *   php cron/sync_api.php --force             → écrase aussi les saisies manuelles
 *
 * Les clés API se trouvent dans config/sync.local.php (voir sync.local.php.exemple).
 *
 * Règle de prudence : une journée déjà saisie à la main ou importée d'un courriel
 * n'est jamais écrasée sans --force. Même chose pour un mois de financial_entries
 * qui n'a pas été écrit par ce script. Un jour sans rapport de caisse n'est pas
 * écrit à 0 : l'outil ne peut pas distinguer « fermé » de « non clôturé ».
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/financials.php';
require_once __DIR__ . '/../inc/rapport_caisse.php';

const MARQUE_PENNYLANE = 'auto:pennylane';

// ---------------------------------------------------------------------------
// Options et configuration
// ---------------------------------------------------------------------------

$opts   = getopt('', ['date:', 'days:', 'only:', 'dry-run', 'force']);
$dryRun = isset($opts['dry-run']);
$force  = isset($opts['force']);
$only   = isset($opts['only']) ? array_map('trim', explode(',', $opts['only'])) : null;

$dernier = new DateTimeImmutable($opts['date'] ?? 'yesterday');
$nbJours = max(1, (int) ($opts['days'] ?? 1));
$jours   = [];
for ($i = $nbJours - 1; $i >= 0; $i--) {
    $jours[] = $dernier->modify("-{$i} day")->format('Y-m-d');
}

$fichierConf = __DIR__ . '/../config/sync.local.php';
if (!is_readable($fichierConf)) {
    journal("ERREUR config/sync.local.php introuvable (copier sync.local.php.exemple).");
    exit(1);
}
$conf = require $fichierConf;

$erreurs = 0;

function journal(string $message): void
{
    echo date('Y-m-d H:i:s') . ' ' . $message . PHP_EOL;
}

function source_active(string $nom, array $conf, ?array $only): bool
{
    return !empty($conf[$nom]) && ($only === null || in_array($nom, $only, true));
}

// ---------------------------------------------------------------------------
// HTTP : GET JSON avec reprise sur 429 et erreurs serveur
// ---------------------------------------------------------------------------

function http_get_json(string $url, array $headers, int $essais = 5): array
{
    for ($i = 0; $i < $essais; $i++) {
        $ch = curl_init($url);
        $entetesReponse = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => array_merge(['Accept: application/json'], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, $ligne) use (&$entetesReponse) {
                $p = explode(':', $ligne, 2);
                if (count($p) === 2) {
                    $entetesReponse[strtolower(trim($p[0]))] = trim($p[1]);
                }
                return strlen($ligne);
            },
        ]);
        $corps = curl_exec($ch);
        $code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err   = curl_error($ch);
        curl_close($ch);

        if ($corps === false) {
            throw new RuntimeException("Réseau : {$err}");
        }
        if ($code === 429 || $code >= 500) {
            $attente = (int) ($entetesReponse['retry-after'] ?? 2 ** $i);
            journal("  HTTP {$code}, nouvel essai dans {$attente}s");
            sleep($attente);
            continue;
        }
        if ($code >= 400) {
            throw new RuntimeException("HTTP {$code} sur " . strtok($url, '?') . ' : ' . substr($corps, 0, 200));
        }
        return json_decode($corps, true, 512, JSON_THROW_ON_ERROR);
    }
    throw new RuntimeException("Abandon après {$essais} essais sur " . strtok($url, '?'));
}

// ---------------------------------------------------------------------------
// Écriture d'une journée de caisse (commune à Popina et L'Addition)
// ---------------------------------------------------------------------------

function ecrire_journee(int $projectId, string $jour, array $j, string $origine, bool $dryRun, bool $force): void
{
    $existante = get_daily_entry($projectId, $jour);
    if ($existante && $existante['source'] !== 'api' && !$force) {
        journal("  {$jour} projet {$projectId} : déjà saisi ({$existante['source']}), conservé. --force pour écraser.");
        return;
    }

    $resume = sprintf('CA TTC %.2f, couverts %d', $j['ca_ttc'], $j['couverts']);
    if ($dryRun) {
        journal("  [dry-run] {$jour} projet {$projectId} : {$resume}");
        return;
    }

    upsert_daily_entry($projectId, $jour, $j['ca_ttc'], $j['couverts'], "Import {$origine}", 'api', null, $j['extra']);
    journal("  {$jour} projet {$projectId} : {$resume}");

    if ($code = detecter_anomalie($projectId, $jour, $j['ca_ttc'])) {
        journal("  ATTENTION {$jour} projet {$projectId} : " . libelle_anomalie($code));
    }
}

// ---------------------------------------------------------------------------
// Popina : rapports de clôture (un ou plusieurs par jour et par établissement)
// ---------------------------------------------------------------------------

function popina_rapports(array $c, string $cle, string $jour): array
{
    $base = rtrim($c['base_url'] ?? 'https://api.pragma-project.dev', '/');
    $rapports = [];
    for ($index = 0; ; $index++) {
        $page = http_get_json(
            "{$base}/v1/reports/?" . http_build_query(['since' => $jour, 'until' => $jour, 'index' => $index, 'size' => 100]),
            ["Authorization: Bearer {$cle}"]
        );
        $rapports = array_merge($rapports, $page['data'] ?? []);
        $totalPages = (int) ($page['meta']['totalPage'] ?? 1);
        if (empty($page['data']) || $index + 1 >= $totalPages) {
            return $rapports;
        }
    }
}

/**
 * Additionne les rapports d'une journée. Les montants Popina sont en centimes
 * (diviseur réglable dans la config au cas où).
 */
function popina_vers_journee(array $rapports, float $div): array
{
    $ttc = $ht = $remises = $solide = $liquide = 0.0;
    $couverts = $additions = 0;
    $inconnues = [];

    foreach ($rapports as $r) {
        $ttc      += (float) ($r['totalSales'] ?? 0);
        $couverts += (int) ($r['guestsNumber'] ?? 0);
        $additions += count($r['orders'] ?? []);
        foreach ($r['reportTaxes'] ?? [] as $t) {
            $ht += (float) ($t['taxableAmount'] ?? 0);
        }
        foreach ($r['reportDiscounts'] ?? [] as $d) {
            $remises += (float) ($d['discountValue'] ?? 0);
        }
        foreach ($r['reportProducts'] ?? [] as $p) {
            $cat = trim((string) ($p['category'] ?? ''));
            $montant = (float) ($p['productSales'] ?? 0);
            if (in_array($cat, CATEGORIES_SOLIDE, true)) {
                $solide += $montant;
            } elseif (in_array($cat, CATEGORIES_LIQUIDE, true)) {
                $liquide += $montant;
            } else {
                $inconnues[$cat === '' ? '(sans catégorie)' : $cat] = true;
            }
        }
    }

    $caTtc = round($ttc / $div, 2);
    return [
        'ca_ttc'    => $caTtc,
        'couverts'  => $couverts,
        'inconnues' => array_keys($inconnues),
        'extra'     => [
            'ca_ht'        => $ht > 0 ? round($ht / $div, 2) : null,
            'remises'      => $remises > 0 ? round($remises / $div, 2) : null,
            'ticket_moyen' => $additions > 0 ? round($caTtc / $additions, 2) : null,
            'ca_solide'    => $solide > 0 ? round($solide / $div, 2) : null,
            'ca_liquide'   => $liquide > 0 ? round($liquide / $div, 2) : null,
        ],
    ];
}

if (source_active('popina', $conf, $only)) {
    journal('Popina');
    $div = (float) ($conf['popina']['diviseur_montants'] ?? 100);
    foreach ($conf['popina']['etablissements'] ?? [] as $etab) {
        foreach ($jours as $jour) {
            try {
                $rapports = popina_rapports($conf['popina'], $etab['api_key'], $jour);
                if (!$rapports) {
                    journal("  {$jour} projet {$etab['project_id']} : aucun rapport clôturé, rien écrit.");
                    continue;
                }
                $j = popina_vers_journee($rapports, $div);
                if ($j['inconnues']) {
                    journal('  Catégories non classées solide/liquide : ' . implode(', ', $j['inconnues']));
                }
                ecrire_journee((int) $etab['project_id'], $jour, $j, 'Popina', $dryRun, $force);
            } catch (Throwable $e) {
                $erreurs++;
                journal("  ERREUR {$jour} projet {$etab['project_id']} : " . $e->getMessage());
            }
        }
    }
}

// ---------------------------------------------------------------------------
// L'Addition : pas de documentation publique. L'accès se fait avec une clé
// générée dans l'espace Partenaires ; les routes sont à reprendre de la
// documentation fournie par L'Addition. Le connecteur s'arrête proprement
// tant qu'elles ne sont pas renseignées.
// ---------------------------------------------------------------------------

if (source_active('laddition', $conf, $only)) {
    journal("L'Addition");
    if (empty($conf['laddition']['base_url'])) {
        journal("  Non configuré : en attente de la documentation API de L'Addition.");
    } else {
        journal("  Routes à compléter dans cron/sync_api.php d'après la documentation L'Addition.");
    }
}

// ---------------------------------------------------------------------------
// Pennylane : revenus et charges HT du mois, recalculés en entier à chaque passage
// ---------------------------------------------------------------------------

function pennylane_liste(array $soc, string $ressource, array $filtre): array
{
    $base = rtrim($soc['base_url'] ?? 'https://app.pennylane.com/api/external/v2', '/');
    $entetes = ['Authorization: Bearer ' . $soc['token']];
    if (!empty($soc['company_id'])) {
        $entetes[] = 'X-Company-Id: ' . $soc['company_id'];
    }
    $items = [];
    $cursor = null;
    do {
        $params = ['filter' => json_encode($filtre), 'limit' => 100];
        if ($cursor) {
            $params['cursor'] = $cursor;
        }
        $page = http_get_json(
            "{$base}/{$ressource}?" . http_build_query($params),
            $entetes
        );
        $items = array_merge($items, $page['items'] ?? []);
        $cursor = $page['next_cursor'] ?? null;
        usleep(250000); // limite Pennylane : 25 requêtes / 5 s
    } while (!empty($page['has_more']) && $cursor);
    return $items;
}

/** Montant HT en euros, avoirs inclus (montants négatifs). */
function montant_ht(array $facture): float
{
    $taux = (float) ($facture['exchange_rate'] ?? 1) ?: 1.0;
    return (float) ($facture['currency_amount_before_tax'] ?? 0) * $taux;
}

if (source_active('pennylane', $conf, $only)) {
    journal('Pennylane');
    // Les jours demandés peuvent couvrir plusieurs mois (rattrapage)
    $mois = array_unique(array_map(fn($j) => substr($j, 0, 7) . '-01', $jours));

    foreach ($conf['pennylane']['societes'] ?? [] as $soc) {
        $pid = (int) $soc['project_id'];
        foreach ($mois as $debut) {
            $fin = (new DateTimeImmutable($debut))->modify('last day of this month')->format('Y-m-d');
            $filtre = [
                ['field' => 'date', 'operator' => 'gteq', 'value' => $debut],
                ['field' => 'date', 'operator' => 'lteq', 'value' => $fin],
            ];
            try {
                $clients = pennylane_liste($soc, 'customer_invoices',
                    array_merge($filtre, [['field' => 'draft', 'operator' => 'eq', 'value' => false]]));
                $fournisseurs = pennylane_liste($soc, 'supplier_invoices', $filtre);

                $revenus = round(array_sum(array_map('montant_ht', $clients)), 2);
                $charges = round(array_sum(array_map('montant_ht', $fournisseurs)), 2);

                $existant = null;
                foreach (get_financial_entries($pid) as $fe) {
                    if ($fe['period_date'] === $debut) {
                        $existant = $fe;
                    }
                }
                if ($existant && ($existant['notes'] ?? '') !== MARQUE_PENNYLANE && !$force) {
                    journal("  {$debut} projet {$pid} : mois déjà saisi à la main, conservé. --force pour écraser.");
                    continue;
                }

                $resume = sprintf('revenus %.2f (%d factures), charges %.2f (%d factures)',
                    $revenus, count($clients), $charges, count($fournisseurs));
                if ($dryRun) {
                    journal("  [dry-run] {$debut} projet {$pid} : {$resume}");
                    continue;
                }
                upsert_financial_entry($pid, $debut, $revenus, $charges, MARQUE_PENNYLANE);
                journal("  {$debut} projet {$pid} : {$resume}");
            } catch (Throwable $e) {
                $erreurs++;
                journal("  ERREUR {$debut} projet {$pid} : " . $e->getMessage());
            }
        }
    }
}

journal($erreurs ? "Terminé avec {$erreurs} erreur(s)." : 'Terminé.');
exit($erreurs ? 1 : 0);
