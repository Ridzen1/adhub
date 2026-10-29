<?php
/**
 * Synchronisation Qalimo (gestion locative) -> MariaDB, en lecture seule côté Qalimo.
 *
 * Lit les profils bailleurs, les biens actifs, les baux actifs et leurs écritures, remplace
 * les tables qalimo_* et met à jour l'occupation (lots_total / lots_loues) des sociétés
 * reliées. Chaque profil est relié à la société ADHUB du même nom (voir config/qalimo.local.php).
 *
 * Usage :
 *   php cron/qalimo/qalimo_sync.php
 *
 * Cron (tous les jours à 6h30) :
 *   30 6 * * * php ~/adhub.fr/cron/qalimo/qalimo_sync.php >> ~/logs/qalimo.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../../inc/qalimo.php';
$config = require (getenv('QALIMO_CONFIG') ?: __DIR__ . '/../../config/qalimo.local.php');
$token  = trim((string) ($config['token'] ?? ''));
define('QALIMO_API', $config['api'] ?? 'https://api.qalimo.fr/api/v1'); // surchargeable pour tester contre un serveur simulé

function logMsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/** GET sur l'API ; le script n'envoie jamais d'autre méthode. */
function qalimo_get(string $path, array $query = []): array
{
    global $token;
    $url = QALIMO_API . $path . ($query ? '?' . http_build_query($query) : '');
    for ($essai = 1; ; $essai++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        usleep(200000); // limite de débit non documentée : on reste poli
        // Trop de requêtes ou erreur passagère : on réessaie deux fois en patientant
        if (($body === false || $code === 429 || $code >= 500) && $essai < 3) {
            sleep(5 * $essai);
            continue;
        }
        if ($body === false) throw new RuntimeException("Réseau : $err");
        $data = json_decode((string) $body, true);
        if ($code !== 200) {
            throw new RuntimeException("HTTP $code sur $path : " . ($data['message'] ?? $data['error'] ?? substr((string) $body, 0, 200)));
        }
        if (!is_array($data)) throw new RuntimeException("Réponse illisible sur $path");
        return $data;
    }
}

function journal(string $status, int $nb, string $message): void
{
    try {
        get_db()->prepare(
            "INSERT INTO sync_runs (source, account, date_from, date_to, nb_lines, status, message)
             VALUES ('qalimo', 'Qalimo', CURDATE(), CURDATE(), ?, ?, ?)"
        )->execute([$nb, $status, mb_substr($message, 0, 1000)]);
    } catch (PDOException $e) {
        // table sync_runs absente (migration 011 non passée) : le journal du terminal suffit
    }
}

if (!str_starts_with($token, 'qmcp_') || $token === 'qmcp_A_REMPLACER') {
    fwrite(STDERR, "Jeton absent ou invalide dans config/qalimo.local.php (il doit commencer par qmcp_)\n");
    exit(1);
}
if (!qalimo_disponible()) {
    fwrite(STDERR, "Tables Qalimo absentes : passer d'abord database/018_qalimo.sql\n");
    exit(1);
}

$ignores = array_map('intval', $config['ignorer'] ?? []);
$forcees = $config['societes'] ?? [];

try {
    logMsg('Synchro Qalimo : lecture des profils, biens et baux');
    $profils = array_values(array_filter(qalimo_get('/landlord-profiles'), fn($p) => !in_array((int) $p['id'], $ignores, true)));
    $garder  = array_flip(array_map(fn($p) => (int) $p['id'], $profils));

    $biens = [];
    foreach (qalimo_get('/properties') as $b) {
        $d = qalimo_get('/properties/' . (int) $b['id']);
        if (isset($garder[(int) ($d['owner_profile_id'] ?? 0)])) {
            $biens[(int) $d['id']] = $d;
        }
    }

    $baux = [];
    $ecritures = [];
    foreach (qalimo_get('/tenants') as $t) {
        if (!isset($biens[(int) $t['property_id']])) {
            continue; // bail d'un profil ignoré
        }
        $baux[(int) $t['id']] = array_merge($t, qalimo_get('/tenants/' . (int) $t['id']));
        $ecritures[(int) $t['id']] = qalimo_get('/tenants/' . (int) $t['id'] . '/balances');
    }

    $liens = [];
    foreach ($profils as $p) {
        $pid = qalimo_societe_du_profil($p, $forcees);
        $liens[(int) $p['id']] = $pid;
        $nb = count(array_filter($biens, fn($b) => (int) $b['owner_profile_id'] === (int) $p['id']));
        logMsg(sprintf('  profil %d %-28s %2d bien(s) → %s', $p['id'], $p['name'], $nb,
            $pid ? 'société ADHUB ' . $pid : 'NON RELIÉ (ajouter dans « societes » de qalimo.local.php)'));
    }

    $lots = qalimo_enregistrer(['profils' => $profils, 'biens' => array_values($biens), 'baux' => array_values($baux), 'ecritures' => $ecritures], $liens);
    $nbEcritures = array_sum(array_map('count', $ecritures));
    foreach ($lots as $pid => [$total, $loues]) {
        logMsg("  société $pid : $loues lot(s) loué(s) sur $total");
    }
    $msg = count($biens) . ' biens, ' . count($baux) . ' baux, ' . $nbEcritures . ' écritures';
    logMsg("OK : $msg");
    journal('ok', $nbEcritures, $msg);
} catch (Throwable $e) {
    logMsg('ÉCHEC : ' . $e->getMessage());
    journal('error', 0, $e->getMessage());
    exit(1);
}
