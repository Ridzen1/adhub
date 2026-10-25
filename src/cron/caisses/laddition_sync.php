<?php
/**
 * Synchronisation des lignes de vente L'Addition -> MariaDB
 *
 * Puis reporte le résumé de chaque journée dans daily_entries (dashboard ADHUB),
 * pour les établissements reliés à une société dans config/caisses.local.php.
 *
 * Usage :
 *   php cron/caisses/laddition_sync.php                          -> la veille
 *   php cron/caisses/laddition_sync.php 2026-10-01 2026-10-22    -> une période (rattrapage)
 *   ajouter --force pour écraser aussi les journées saisies à la main
 *
 * Cron (tous les jours à 6h, après les clôtures de la nuit) :
 *   0 6 * * * php ~/adhub/cron/caisses/laddition_sync.php >> ~/logs/caisses.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../../inc/caisses.php';
$config = require (getenv('CAISSES_CONFIG') ?: __DIR__ . '/../../config/caisses.local.php');

// URLs surchargeables dans config.php (utile pour tester contre le serveur simulé)
define('AUTH_API',      $config['laddition_auth_api']      ?? 'https://auth.laddition.com/v2/api/');
define('REPORTING_API', $config['laddition_reporting_api'] ?? 'https://reporting-api.laddition.com/api/');
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

/** Lignes de vente sur une période, tous types de commande (sur place, emporter, livraison). */
function getOrderLines(string $accessToken, array $companyIds, string $from, string $to): array
{
    $json = reportingCall($accessToken, 'POST', 'dataExport/getOrderLines/', [ // slash final comme le front
        'opening_date'  => "$from 00:00:00",
        'closing_date'  => "$to 23:59:59",
        'idCompanies'   => json_encode(array_map('intval', $companyIds)),
        'orderTypeAway' => json_encode([1, 2, 3]),
        'limit'         => false, // le front envoie true pour l'aperçu : à vérifier au premier test
    ]);
    if (empty($json['success'])) {
        throw new RuntimeException('getOrderLines : success=false');
    }
    return $json['data'] ?? [];
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

function saveOrderLines(PDO $pdo, int $idCompany, array $lines): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO laddition_order_lines
            (id_company, id_ligne_vente, etablissement, heure_vente, heure_vente_raw, type_produit,
             categorie_produit, id_produit, nom_produit, taux_tva, nom_tva, ht, tva, ttc,
             id_parent, id_salle, nom_salle)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE
            etablissement=VALUES(etablissement), heure_vente=VALUES(heure_vente),
            heure_vente_raw=VALUES(heure_vente_raw), type_produit=VALUES(type_produit),
            categorie_produit=VALUES(categorie_produit), id_produit=VALUES(id_produit),
            nom_produit=VALUES(nom_produit), taux_tva=VALUES(taux_tva), nom_tva=VALUES(nom_tva),
            ht=VALUES(ht), tva=VALUES(tva), ttc=VALUES(ttc), id_parent=VALUES(id_parent),
            id_salle=VALUES(id_salle), nom_salle=VALUES(nom_salle)'
    );
    $n = 0;
    $pdo->beginTransaction();
    foreach ($lines as $l) {
        if (empty($l['ID_LIGNE_VENTE'])) continue;
        $parent = isset($l['ID_PARENT']) ? (string) $l['ID_PARENT'] : '';
        $stmt->execute([
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
        ]);
        $n++;
    }
    $pdo->commit();
    return $n;
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
$from = $args[0] ?? date('Y-m-d', strtotime('-1 day'));
$to   = $args[1] ?? $from;
foreach ([$from, $to] as $d) {
    $dt = DateTime::createFromFormat('!Y-m-d', $d);
    if (!$dt || $dt->format('Y-m-d') !== $d) { fwrite(STDERR, "Date invalide : $d (format AAAA-MM-JJ)\n"); exit(1); }
}

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
        // Une journée de service finit à 5h le lendemain : on récupère aussi ce lendemain
        $toPlus = date('Y-m-d', strtotime("$to +1 day"));
        $total = 0;
        foreach ($companies as $c) {
            $id    = (int) $c['id_company'];
            $nom   = trim($c['company_name'] ?? (string) $id);
            $lines = getOrderLines($token, [$id], $from, $toPlus);
            $total += saveOrderLines($pdo, $id, $lines);
            logMsg("$label / $nom : " . count($lines) . " lignes ($from → $toPlus)");

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
