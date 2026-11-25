<?php
/**
 * Synchronisation des lignes de vente L'Addition -> MariaDB
 *
 * Puis reporte le résumé de chaque journée dans daily_entries (dashboard ADHUB),
 * pour les établissements reliés à une société dans config/caisses.local.php.
 *
 * Usage :
 *   php cron/caisses/laddition_sync.php                          -> les 3 derniers jours (jusqu'à la veille)
 *   php cron/caisses/laddition_sync.php 2026-10-01 2026-10-22    -> une période (rattrapage)
 *   ajouter --force pour écraser aussi les journées saisies à la main
 *
 * Sans date, les 3 derniers jours sont repris : une nuit où la synchro a échoué
 * (L'Addition indisponible, coupure, cron sauté) se rattrape toute seule la nuit suivante.
 *
 * Cron (tous les jours à 5h30, après la fin de journée de service à 5h) :
 *   30 5 * * * /usr/local/bin/php ~/adhub.fr/cron/caisses/laddition_sync.php >/dev/null 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../../inc/caisses.php';
require_once __DIR__ . '/xlsx_reader.php';
$config = require (getenv('CAISSES_CONFIG') ?: __DIR__ . '/../../config/caisses.local.php');

// URLs surchargeables dans config.php (utile pour tester contre le serveur simulé)
define('AUTH_API',      $config['laddition_auth_api']      ?? 'https://auth.laddition.com/v2/api/');
define('REPORTING_API', $config['laddition_reporting_api'] ?? 'https://reporting-api.laddition.com/api/');
// Exports Excel du nouveau reporting (reporting-pos.laddition.com), depuis le 20/11/2026
define('DATA_URL',      $config['laddition_data_url']      ?? 'https://data.laddition.com/');
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

// ---------------------------------------------------------------- utilitaires

function logMsg(string $msg): void
{
    global $config;
    $line = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $line;
    if (!empty($config['log_file'])) {
        file_put_contents($config['log_file'], $line, FILE_APPEND);
    }
}

/** Requête HTTP JSON. Renvoie [code HTTP, corps décodé]. */
function http(string $method, string $url, array $headers = [], ?array $body = null, ?string $cookieJar = null): array
{
    $ch = curl_init($url);
    $h = array_merge(['Accept: application/json', 'User-Agent: ' . UA], $headers);
    if ($body !== null) {
        $h[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_HTTPHEADER     => $h,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException("Erreur réseau sur $url : $err");
    }
    return [$code, json_decode($raw, true)];
}

// ------------------------------------------------------------ authentification

/** Étape 1 : connexion avec identifiant / mot de passe -> refreshToken. */
function ladditionLogin(string $login, string $password, string $cookieJar): string
{
    [$code, $json] = http('POST', AUTH_API . 'auth', [
        'Origin: https://auth.laddition.com',
        'Referer: https://auth.laddition.com/',
    ], [
        'identifier' => $login,
        'password'   => $password,
        'scope'      => false,
    ], $cookieJar);

    if ($code !== 200 || empty($json['success']) || empty($json['refreshToken'])) {
        $detail = is_array($json) ? ($json['message'] ?? $json['error'] ?? '') : '';
        throw new RuntimeException("Échec de connexion (HTTP $code) $detail");
    }
    return $json['refreshToken'];
}

/** Déconnexion en fin de traitement, pour ne pas laisser de session ouverte. */
function ladditionLogout(string $refreshToken, string $cookieJar): void
{
    try {
        http('POST', AUTH_API . 'auth/logout', [
            'Authorization: refreshToken ' . $refreshToken,
            'Cookie: refreshToken=' . $refreshToken,
            'Origin: https://auth.laddition.com',
        ], [], $cookieJar);
    } catch (Throwable $e) {
        // pas bloquant
    }
}

/** Étape 2 : refreshToken -> accessToken (JWT valable 15 min). */
function ladditionAccessToken(string $refreshToken): string
{
    [$code, $json] = http('GET', REPORTING_API . 'auth/accessToken/?refreshToken=' . rawurlencode($refreshToken));
    if ($code !== 200 || empty($json['success']) || empty($json['accessToken'])) {
        throw new RuntimeException("Échec obtention accessToken (HTTP $code)");
    }
    return $json['accessToken'];
}

/** Appel authentifié à reporting-api. $route est envoyé tel quel (URLs exactes relevées dans le HAR). */
function reportingCall(string $accessToken, string $method, string $route, ?array $body = null): array
{
    [$code, $json] = http($method, REPORTING_API . $route, ['Authorization: accessToken ' . $accessToken], $body);
    if ($code !== 200 || !is_array($json)) {
        $detail = is_array($json) ? (string) ($json['message'] ?? $json['error'] ?? '') : 'réponse non JSON';
        throw new RuntimeException("Erreur API $route (HTTP $code) $detail");
    }
    return $json;
}

// --------------------------------------------------------------------- données

/** Liste des établissements visibles par le compte. */
function getCompanies(string $accessToken): array
{
    $json = reportingCall($accessToken, 'GET', 'informations');
    return $json['companies'] ?? [];
}

/**
 * Signature attendue par data.laddition.com, calculée comme le site de L'Addition :
 * md5("<base>#<5 derniers chiffres de l'heure Unix>").
 */
function ladditionSignature(string $db): string
{
    return md5($db . '#' . substr((string) time(), -5));
}

/** Téléchargement brut (fichier Excel). Renvoie [code HTTP, contenu, type]. */
function httpRaw(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER     => ['User-Agent: ' . UA, 'Referer: https://reporting-pos.laddition.com/'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $raw  = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($raw === false) {
        throw new RuntimeException("Erreur réseau sur $url : $err");
    }
    return [$code, $raw, $type];
}

/**
 * Lignes de vente d'un établissement, journées de service $from à $to incluses.
 *
 * Le 20/11/2026, L'Addition a supprimé la route dataExport/getOrderLines/ (404).
 * On passe par l'export Excel « Lignes de ventes » du nouveau reporting
 * (rapport SalesDocumentLines.php sur data.laddition.com, feuille « SalesDocumentLines »),
 * remis au format de l'ancienne API pour que la suite ne change pas.
 *
 * @param array $company une entrée de getCompanies() (id_company, company_db_name)
 */
function getOrderLines(array $company, string $from, string $to): array
{
    return ladditionLignesExport(getExport($company, $from, $to), (int) $company['id_company']);
}

/** Classeur complet de l'export (feuilles SalesDocumentLines, SalesDocument, …). */
function getExport(array $company, string $from, string $to): array
{
    $db = trim((string) ($company['company_db_name'] ?? ''));
    if ($db === '') {
        throw new RuntimeException("Nom de base L'Addition absent pour l'établissement {$company['id_company']}");
    }
    $url = DATA_URL . 'scripts/reporting/v1/SalesDocumentLines.php?' . http_build_query([
        'db'         => $db,
        'date_start' => $from,
        'date_end'   => $to,
        'signature'  => ladditionSignature($db),
        'price'      => 'ttc',
    ]);
    [$code, $raw] = httpRaw($url);
    if ($code !== 200 || strncmp($raw, "PK", 2) !== 0) {
        throw new RuntimeException("Export L'Addition SalesDocumentLines (HTTP $code) : pas un fichier Excel");
    }
    return xlsxSheets($raw);
}

/** Lecture d'une feuille de l'export : [lignes de données, fonction d'accès par nom de colonne]. */
function ladditionFeuille(array $feuilles, string $nom, array $requises): array
{
    $rows = $feuilles[$nom] ?? null;
    if (!$rows) {
        throw new RuntimeException("Export L'Addition : feuille $nom absente (" . implode(', ', array_keys($feuilles)) . ')');
    }
    $entete = array_map(fn($v) => trim((string) $v), array_shift($rows));
    $col = array_flip($entete);
    foreach ($requises as $requise) {
        if (!isset($col[$requise])) {
            throw new RuntimeException("Export L'Addition : colonne « $requise » absente de $nom, le format a changé");
        }
    }
    $rows = array_values(array_filter($rows, fn($r) => trim((string) ($r[$col['Etablissement'] ?? 0] ?? '')) !== 'Total'));
    return [$rows, fn(array $r, string $c) => isset($col[$c]) ? ($r[$col[$c]] ?? null) : null];
}

function exportTexte($x): ?string
{
    $x = $x === null ? '' : trim((string) $x);
    return ($x === '' || $x === '-') ? null : $x;
}

function exportMontant($x): float
{
    return (float) str_replace([',', ' '], ['.', ''], (string) ($x ?? 0));
}

/** Numéro de série Excel (heure locale) -> 'Y-m-d H:i:s'. */
function exportHeure($x): ?string
{
    return is_numeric($x) ? gmdate('Y-m-d H:i:s', (int) round(((float) $x - 25569) * 86400)) : exportTexte($x);
}

/** Feuille « SalesDocumentLines » de l'export -> lignes au format de l'ancienne API. */
function ladditionLignesExport(array $feuilles, int $idCompany): array
{
    [$rows, $v] = ladditionFeuille($feuilles, 'SalesDocumentLines', ['ID', 'Timestamp', 'Nom', 'Prix TTC', 'Taux']);

    $lignes = [];
    foreach ($rows as $r) {
        $id = exportTexte($v($r, 'ID'));
        if ($id === null) continue;

        $nomTva = exportTexte($v($r, 'Taux'));                // « 10% », « 5,5% »
        $taux = $nomTva !== null ? (float) str_replace([',', '%', ' '], ['.', '', ''], $nomTva) / 100 : null;
        $ttc = exportMontant($v($r, 'Prix TTC'));             // net, remises déduites
        $ht = $taux !== null ? round($ttc / (1 + $taux), 2) : null;

        $lignes[] = [
            'ID_LIGNE_VENTE'    => $id,
            'ETABLISSEMENT'     => exportTexte($v($r, 'Etablissement')),
            'HEURE VENTE'       => exportHeure($v($r, 'Timestamp')),
            'TYPE PRODUIT'      => exportTexte($v($r, 'TAG_TypeProduit')),
            'CATEGORIE PRODUIT' => exportTexte($v($r, 'TAG_Catégorie')),
            'ID_PRODUIT'        => exportTexte($v($r, 'ID produit')),
            'NOM PRODUIT'       => exportTexte($v($r, 'Nom')),
            'TAUX DE TVA'       => $taux,
            'NOM TVA'           => $nomTva,
            'HT'                => $ht,
            'TVA'               => $ht !== null ? round($ttc - $ht, 2) : null,
            'TTC'               => $ttc,
            'ID_PARENT'         => null, // l'export donne le nom du menu, pas son identifiant
            'ID_SALLES'         => null,
            'NOM SALLES'        => exportTexte($v($r, 'TAG_Salle')),
            'TYPOLOGIE'         => exportTexte($v($r, 'TAG_Typologie')), // « Solide », « Liquide (Alcool) », « N/D »
            // Depuis la migration 025
            'ID TICKET'         => exportTexte($v($r, 'ID Ticket')),
            'SERVEUR'           => exportTexte($v($r, 'Nom user')),
            'TABLE'             => exportTexte($v($r, 'TAG_Table')),
            'REMISE TTC'        => exportMontant($v($r, 'Remises TTC')),
            'OFFERT TTC'        => exportMontant($v($r, 'Offerts TTC')),
        ];
    }
    return $lignes;
}

/** Feuille « SalesDocument » : un ticket (addition) par ligne, avec couverts et paiements. */
function ladditionTicketsExport(array $feuilles): array
{
    [$rows, $v] = ladditionFeuille($feuilles, 'SalesDocument', ['ID Ticket', 'Jour', 'Couverts', 'Total TTC', 'Paiements']);
    $tickets = [];
    foreach ($rows as $r) {
        $id = exportTexte($v($r, 'ID Ticket'));
        $jour = $v($r, 'Jour');
        $jour = is_numeric($jour) ? xlsxDate($jour) : exportTexte($jour);
        if ($id === null || $jour === null || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $jour)) continue;
        $tickets[] = [
            'id'        => $id,
            'jour'      => $jour,
            'debut'     => exportHeure($v($r, 'Début ticket')),
            'fin'       => exportHeure($v($r, 'Fin ticket')),
            'couverts'  => (int) exportMontant($v($r, 'Couverts')),
            'serveur'   => exportTexte($v($r, 'Utilisateur')),
            'type'      => exportTexte($v($r, 'TAG_Type')),
            'salle'     => exportTexte($v($r, 'Salle')),
            'table'     => exportTexte($v($r, 'Table')),
            'total_ttc' => exportMontant($v($r, 'Total TTC')),
            'total_ht'  => exportMontant($v($r, 'Total HT')),
            'tva'       => exportMontant($v($r, 'TVA')),
            'remises'   => exportMontant($v($r, 'Remises')),
            'offerts'   => exportMontant($v($r, 'Offerts')),
            'paiements' => exportTexte($v($r, 'Paiements')),
        ];
    }
    return $tickets;
}

/** « CB(103,50), Espèces(20,00) » -> ['CB' => 103.5, 'Espèces' => 20.0] (modes répétés additionnés). */
function ladditionPaiements(?string $texte): array
{
    $out = [];
    if ($texte === null) return $out;
    preg_match_all('/([^()]+?)\s*\(\s*(-?[\d\s.,]+)\s*\)/u', $texte, $m, PREG_SET_ORDER);
    foreach ($m as $p) {
        $mode = trim($p[1], " ,;\t\n");
        if ($mode === '') continue;
        $montant = str_replace(' ', '', $p[2]);
        // « 1.234,50 » ou « 103,50 » ou « 103.50 »
        if (str_contains($montant, ',')) $montant = str_replace(['.', ','], ['', '.'], $montant);
        $out[$mode] = round(($out[$mode] ?? 0) + (float) $montant, 2);
    }
    return $out;
}

/**
 * Classe en solide / liquide les catégories encore jamais vues, d'après la typologie
 * donnée par L'Addition. Un classement déjà fait (à la main ou par migration) n'est pas touché.
 */
function classerCategoriesNouvelles(PDO $pdo, array $lines): int
{
    $familles = [];
    foreach ($lines as $l) {
        $cat = $l['CATEGORIE PRODUIT'] ?? null;
        $typo = mb_strtolower((string) ($l['TYPOLOGIE'] ?? ''));
        if ($cat === null || isset($familles[$cat])) continue;
        if (str_starts_with($typo, 'solide')) $familles[$cat] = 'solide';
        elseif (str_starts_with($typo, 'liquide')) $familles[$cat] = 'liquide';
    }
    $stmt = $pdo->prepare("INSERT IGNORE INTO caisse_familles (source, categorie, famille) VALUES ('laddition', ?, ?)");
    $n = 0;
    foreach ($familles as $cat => $famille) {
        $stmt->execute([$cat, $famille]);
        $n += $stmt->rowCount();
    }
    return $n;
}

function toDatetime(?string $v): ?string
{
    if ($v === null || $v === '') return null;
    foreach (['d-m-Y H:i', 'd-m-Y H:i:s', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s.u\Z', 'Y-m-d\TH:i:s\Z', 'd/m/Y H:i:s', 'd/m/Y H:i', 'H:i:s'] as $fmt) {
        $d = DateTime::createFromFormat($fmt, $v);
        if ($d !== false) return $d->format('Y-m-d H:i:s');
    }
    $ts = strtotime($v);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

function num($v): ?float
{
    if ($v === null || $v === '') return null;
    return (float) str_replace([',', ' '], ['.', ''], (string) $v);
}

/**
 * Enregistre les lignes d'une période. Les lignes déjà en base pour ces journées de service
 * sont d'abord retirées : l'export fait foi (ligne supprimée en caisse, changement d'identifiant).
 */
function saveOrderLines(PDO $pdo, int $idCompany, array $lines, ?string $from = null, ?string $to = null): int
{
    $cols = ['id_company', 'id_ligne_vente', 'etablissement', 'heure_vente', 'heure_vente_raw', 'type_produit',
             'categorie_produit', 'id_produit', 'nom_produit', 'taux_tva', 'nom_tva', 'ht', 'tva', 'ttc',
             'id_parent', 'id_salle', 'nom_salle'];
    $detail = ladditionMigration025($pdo); // ticket, serveur, table, remise, offert
    if ($detail) {
        array_push($cols, 'ticket_id', 'serveur', 'table_nom', 'remise_ttc', 'offert_ttc');
    }
    $maj = implode(', ', array_map(fn($c) => "$c=VALUES($c)", array_slice($cols, 2)));
    $stmt = $pdo->prepare(
        'INSERT INTO laddition_order_lines (' . implode(', ', $cols) . ')
         VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')
         ON DUPLICATE KEY UPDATE ' . $maj
    );
    $n = 0;
    $pdo->beginTransaction();
    if ($lines && $from !== null && $to !== null) {
        $b = CAISSE_HEURE_BASCULE;
        $pdo->prepare(
            "DELETE FROM laddition_order_lines
             WHERE id_company = ? AND heure_vente >= ? + INTERVAL {$b} HOUR
               AND heure_vente < ? + INTERVAL 1 DAY + INTERVAL {$b} HOUR"
        )->execute([$idCompany, $from, $to]);
    }
    foreach ($lines as $l) {
        if (empty($l['ID_LIGNE_VENTE'])) continue;
        $parent = isset($l['ID_PARENT']) ? (string) $l['ID_PARENT'] : '';
        $valeurs = [
            $idCompany,
            (string) $l['ID_LIGNE_VENTE'],
            isset($l['ETABLISSEMENT']) ? trim((string) $l['ETABLISSEMENT']) : null,
            toDatetime($l['HEURE VENTE'] ?? null),
            $l['HEURE VENTE'] ?? null,
            $l['TYPE PRODUIT'] ?? null,
            $l['CATEGORIE PRODUIT'] ?? null,
            isset($l['ID_PRODUIT']) ? (string) $l['ID_PRODUIT'] : null,
            $l['NOM PRODUIT'] ?? null,
            isset($l['TAUX DE TVA']) ? (float) $l['TAUX DE TVA'] : null,
            $l['NOM TVA'] ?? null,
            num($l['HT'] ?? null),
            num($l['TVA'] ?? null),
            num($l['TTC'] ?? null),
            ($parent === '' || $parent === '0') ? null : $parent, // "0" = pas de parent
            isset($l['ID_SALLES']) ? (string) $l['ID_SALLES'] : null,
            $l['NOM SALLES'] ?? null,
        ];
        if ($detail) {
            array_push($valeurs, $l['ID TICKET'] ?? null, $l['SERVEUR'] ?? null, $l['TABLE'] ?? null,
                       num($l['REMISE TTC'] ?? null), num($l['OFFERT TTC'] ?? null));
        }
        $stmt->execute($valeurs);
        $n++;
    }
    $pdo->commit();
    return $n;
}

/** Migration 025 passée ? (tables des tickets et colonnes de détail des lignes) */
function ladditionMigration025(PDO $pdo): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $pdo->query('SELECT 1 FROM laddition_tickets LIMIT 0');
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Enregistre les tickets et paiements d'une période (remplace ceux déjà en base pour ces journées). */
function saveTickets(PDO $pdo, int $idCompany, array $tickets, string $from, string $to): int
{
    if (!$tickets) return 0;
    $pdo->beginTransaction();
    foreach (['laddition_tickets', 'laddition_paiements'] as $table) {
        $pdo->prepare("DELETE FROM {$table} WHERE id_company = ? AND jour BETWEEN ? AND ?")->execute([$idCompany, $from, $to]);
    }
    $t = $pdo->prepare(
        'INSERT INTO laddition_tickets
            (id_company, id_ticket, jour, debut, fin, couverts, serveur, type, salle, table_nom,
             total_ttc, total_ht, tva, remises, offerts, paiements)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE jour=VALUES(jour), debut=VALUES(debut), fin=VALUES(fin), couverts=VALUES(couverts),
            serveur=VALUES(serveur), type=VALUES(type), salle=VALUES(salle), table_nom=VALUES(table_nom),
            total_ttc=VALUES(total_ttc), total_ht=VALUES(total_ht), tva=VALUES(tva), remises=VALUES(remises),
            offerts=VALUES(offerts), paiements=VALUES(paiements)'
    );
    $p = $pdo->prepare(
        'INSERT INTO laddition_paiements (id_company, id_ticket, mode, jour, montant) VALUES (?,?,?,?,?)
         ON DUPLICATE KEY UPDATE jour=VALUES(jour), montant=VALUES(montant)'
    );
    foreach ($tickets as $k) {
        $t->execute([$idCompany, $k['id'], $k['jour'], $k['debut'], $k['fin'], $k['couverts'], $k['serveur'],
                     $k['type'], $k['salle'], $k['table'], $k['total_ttc'], $k['total_ht'], $k['tva'],
                     $k['remises'], $k['offerts'], $k['paiements'] !== null ? mb_substr($k['paiements'], 0, 255) : null]);
        foreach (ladditionPaiements($k['paiements']) as $mode => $montant) {
            $p->execute([$idCompany, $k['id'], mb_substr($mode, 0, 100), $k['jour'], $montant]);
        }
    }
    $pdo->commit();
    return count($tickets);
}

function logRun(PDO $pdo, string $account, string $from, string $to, int $n, string $status, ?string $msg): void
{
    $pdo->prepare('INSERT INTO sync_runs (source, account, date_from, date_to, nb_lines, status, message) VALUES (?,?,?,?,?,?,?)')
        ->execute(['laddition', $account, $from, $to, $n, $status, $msg]);
}

// ------------------------------------------------------------------------ main

// Pas d'exécution quand le fichier est inclus (ex. par test_reel.php)
if (realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {

$args  = array_values(array_filter(array_slice($argv, 1), fn($a) => $a !== '--force'));
$force = in_array('--force', $argv, true);
if ($args) {
    $from = $args[0];
    $to   = $args[1] ?? $from;
} else {
    // Sans date : les 3 derniers jours, pour rattraper une nuit manquée
    $from = date('Y-m-d', strtotime('-' . CAISSE_JOURS_RATTRAPAGE . ' days'));
    $to   = date('Y-m-d', strtotime('-1 day'));
}
foreach ([$from, $to] as $d) {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) { fwrite(STDERR, "Date invalide : $d (format AAAA-MM-JJ)\n"); exit(1); }
}
if ($from > $to) { fwrite(STDERR, "La date de début est après la date de fin\n"); exit(1); }

try {
    $pdo = get_db();
} catch (PDOException $e) {
    logMsg('ERREUR base de données : ' . $e->getMessage());
    exit(1);
}

$exitCode = 0;
foreach ($config['laddition_accounts'] as $acc) {
    $label = $acc['label'];
    $jar = tempnam(sys_get_temp_dir(), 'ladd');
    $refresh = null;
    try {
        $refresh   = ladditionLogin($acc['login'], $acc['password'], $jar);
        $token     = ladditionAccessToken($refresh);
        $companies = getCompanies($token);
        if (!$companies) throw new RuntimeException('Aucun établissement trouvé pour ce compte');

        // 'etablissements' => [id L'Addition => id société ADHUB]
        $liens = $acc['etablissements'] ?? [];
        $total = 0;
        foreach ($companies as $c) {
            $id    = (int) $c['id_company'];
            $nom   = trim($c['company_name'] ?? (string) $id);
            // L'export est par journée de service (coupure à 5 h) : pas besoin du lendemain
            $export = getExport($c, $from, $to);
            $lines = ladditionLignesExport($export, $id);
            $total += saveOrderLines($pdo, $id, $lines, $from, $to);
            logMsg("$label / $nom : " . count($lines) . " lignes ($from → $to)");
            if (ladditionMigration025($pdo)) {
                logMsg('   → ' . saveTickets($pdo, $id, ladditionTicketsExport($export), $from, $to) . ' tickets (couverts, serveurs, paiements)');
            } else {
                logMsg('   → tickets, couverts et paiements non enregistrés : migration 025 à passer');
            }
            if ($classees = classerCategoriesNouvelles($pdo, $lines)) {
                logMsg("   → $classees nouvelle(s) catégorie(s) classée(s) solide / liquide d'après L'Addition");
            }

            if (isset($liens[$id])) {
                caisse_relier('laddition', $id, (int) $liens[$id], $nom);
            }
            $pid = caisse_projet('laddition', $id);
            for ($day = $from; $day <= $to; $day = date('Y-m-d', strtotime("$day +1 day"))) {
                logMsg("   → dashboard $day : " . ($pid ? "société $pid, " . caisse_maj_saisie($pid, $day, $force)
                                                          : "établissement $id non relié à une société (config)"));
            }
            sleep(2); // rester discret
        }
        logRun($pdo, $label, $from, $to, $total, 'ok', null);
    } catch (Throwable $e) {
        $exitCode = 1;
        logMsg("ERREUR $label : " . $e->getMessage());
        logRun($pdo, $label, $from, $to, 0, 'error', $e->getMessage());
    } finally {
        if ($refresh) ladditionLogout($refresh, $jar);
        @unlink($jar); // ne pas laisser traîner les cookies de session
    }
}
// Le suivi mensuel du tableau de bord reprend le CA des caisses
try {
    require_once __DIR__ . '/../../inc/pennylane.php';
    logMsg('Suivi mensuel : ' . pennylane_alimenter_suivi_mensuel() . ' mois mis à jour');
} catch (Throwable $e) {
    logMsg('Suivi mensuel non mis à jour : ' . $e->getMessage());
}
exit($exitCode);
}
