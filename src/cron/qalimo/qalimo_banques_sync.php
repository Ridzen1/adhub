<?php
/**
 * Banques Qalimo -> MariaDB : comptes, soldes et opérations de toutes les sociétés.
 *
 * L'API publique de Qalimo (/api/v1, jeton qmcp_) ne donne pas les banques. Ce script se
 * connecte donc comme l'application web (e-mail + mot de passe du compte Qalimo, rangés dans
 * config/qalimo.local.php) et lit les mêmes données que la page « Banques » :
 *   POST /token                    connexion (code à 6 chiffres calculé si la double auth est active)
 *   GET  /banks                    comptes connectés (banque, solde, état de la connexion)
 *   GET  /transactions?page=…      opérations, page par page
 * Uniquement de la lecture : le script n'affecte, ne crée ni ne supprime rien dans Qalimo.
 *
 * Usage :
 *   php cron/qalimo/qalimo_banques_sync.php              60 derniers jours (rattrapage compris)
 *   php cron/qalimo/qalimo_banques_sync.php --tout       tout l'historique (1re fois)
 *   php cron/qalimo/qalimo_banques_sync.php --test       comptes, société proposée et opérations lues, sans rien écrire
 *
 * Cron (tous les jours à 6h45, après la synchro des biens de 6h30) :
 *   45 6 * * * php ~/adhub.fr/cron/qalimo/qalimo_banques_sync.php >> ~/logs/qalimo_banques.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../../inc/qalimo_banques.php';
$config = require (getenv('QALIMO_CONFIG') ?: __DIR__ . '/../../config/qalimo.local.php');

const QB_JOURS_FENETRE = 60;   // opérations relues à chaque passage (une banque peut livrer en retard)
const QB_PAR_PAGE      = 500;  // plus grande page proposée par Qalimo
const QB_PAGES_MAX     = 400;  // garde-fou : 200 000 opérations

define('QB_BASE', rtrim($config['base'] ?? 'https://api.qalimo.fr', '/')); // surchargeable pour tester (mock)

$args  = array_slice($argv, 1);
$tout  = in_array('--tout', $args, true);
$test  = in_array('--test', $args, true);

function logMsg(string $msg): void
{
    echo '[' . date('Y-m-d H:i:s') . "] $msg\n";
}

/** Code TOTP (RFC 6238, 6 chiffres, 30 s) à partir de la clé secrète en base32 */
function totp(string $secretBase32, ?int $instant = null): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secretBase32 = strtoupper(preg_replace('/[\s=]/', '', $secretBase32));
    $bits = '';
    foreach (str_split($secretBase32) as $c) {
        $v = strpos($alphabet, $c);
        if ($v === false) throw new RuntimeException('Clé de double authentification illisible (base32 attendu)');
        $bits .= str_pad(decbin($v), 5, '0', STR_PAD_LEFT);
    }
    $cle = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) $cle .= chr(bindec($octet));
    }
    $compteur = pack('J', intdiv($instant ?? time(), 30));
    $h = hash_hmac('sha1', $compteur, $cle, true);
    $o = ord($h[19]) & 0x0F;
    $n = ((ord($h[$o]) & 0x7F) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** Requête HTTP vers Qalimo ; renvoie [code, données JSON décodées] */
function qb_http(string $methode, string $chemin, ?array $json = null, ?string $jeton = null): array
{
    $entetes = ['Accept: application/json', 'Origin: https://app.qalimo.fr', 'Referer: https://app.qalimo.fr/'];
    if ($jeton) $entetes[] = 'Authorization: Bearer ' . $jeton;
    if ($json !== null) $entetes[] = 'Content-Type: application/json';
    for ($essai = 1; ; $essai++) {
        $ch = curl_init(QB_BASE . $chemin);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 90,
            CURLOPT_CUSTOMREQUEST  => $methode,
            CURLOPT_HTTPHEADER     => $entetes,
            CURLOPT_USERAGENT      => 'ADHUB-sync/1.0',
        ]);
        if ($json !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json));
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        usleep(250000); // on reste poli avec le serveur
        if (($body === false || $code === 429 || $code >= 500) && $essai < 3) {
            sleep(5 * $essai);
            continue;
        }
        if ($body === false) throw new RuntimeException("Réseau : $err");
        return [$code, json_decode((string) $body, true)];
    }
}

/** Connexion comme l'application web ; renvoie le jeton d'accès */
function qb_connexion(array $config): string
{
    $email = trim((string) ($config['email'] ?? ''));
    $mdp   = (string) ($config['mot_de_passe'] ?? '');
    if ($email === '' || $mdp === '' || $mdp === 'A_REMPLACER') {
        throw new RuntimeException("Identifiants Qalimo absents : renseigner 'email' et 'mot_de_passe' dans config/qalimo.local.php");
    }
    $corps = ['email' => $email, 'password' => $mdp, 'persist_login' => false, 'totp_code' => ''];
    [$code, $data] = qb_http('POST', '/token', $corps);
    if ($code === 401 && !empty($data['requires_totp'])) {
        $secret = trim((string) ($config['totp_secret'] ?? ''));
        if ($secret === '') {
            throw new RuntimeException("Le compte Qalimo demande un code de double authentification : renseigner 'totp_secret' dans config/qalimo.local.php");
        }
        $corps['totp_code'] = totp($secret);
        [$code, $data] = qb_http('POST', '/token', $corps);
    }
    if ($code !== 200 || empty($data['access_token'])) {
        $msg = is_array($data) ? ($data['error'] ?? $data['message'] ?? '') : '';
        throw new RuntimeException("Connexion Qalimo refusée (HTTP $code)" . ($msg ? " : $msg" : ''));
    }
    return (string) $data['access_token'];
}

/** Liste renvoyée par Qalimo : tableau simple, ou enveloppé dans data / items / results */
function qb_liste($data): array
{
    if (!is_array($data)) return [];
    if (array_is_list($data)) return $data;
    foreach (['data', 'items', 'results', 'transactions', 'banks'] as $k) {
        if (isset($data[$k]) && is_array($data[$k])) return $data[$k];
    }
    return [];
}

function qb_get(string $chemin, string $jeton, array $query = []): array
{
    // Qalimo lit les tableaux sous la forme account=a&account=b (sans crochets)
    $qs = preg_replace('/%5B\d+%5D=/', '=', http_build_query($query));
    [$code, $data] = qb_http('GET', $chemin . ($qs !== '' ? '?' . $qs : ''), null, $jeton);
    if ($code !== 200) {
        $msg = is_array($data) ? ($data['error'] ?? $data['message'] ?? '') : '';
        throw new RuntimeException("HTTP $code sur $chemin" . ($msg ? " : $msg" : ''));
    }
    return is_array($data) ? $data : [];
}

function journal(string $status, int $nb, string $message, ?string $du): void
{
    try {
        get_db()->prepare(
            "INSERT INTO sync_runs (source, account, date_from, date_to, nb_lines, status, message)
             VALUES ('qalimo_banques', 'Qalimo banques', ?, CURDATE(), ?, ?, ?)"
        )->execute([$du ?? '2000-01-01', $nb, $status, mb_substr($message, 0, 1000)]);
    } catch (PDOException $e) {
        // table sync_runs absente : le journal du terminal suffit
    }
}

if (!$test && !qalimo_banques_disponible()) {
    fwrite(STDERR, "Tables absentes : passer d'abord database/028_qalimo_banques.sql\n");
    exit(1);
}

$du = null;
try {
    logMsg('Banques Qalimo : connexion');
    $jeton = qb_connexion($config);

    $ignores = array_map('intval', $config['ignorer'] ?? []);
    $comptes = array_values(array_filter(
        qb_liste(qb_get('/banks', $jeton)),
        fn($c) => isset($c['id']) && !in_array((int) ($c['id_profile_nom'] ?? 0), $ignores, true)
    ));
    // Rapprochement compte → société ; 0 dans « comptes » = compte à ne pas reprendre (ex. compte personnel)
    $forcees  = array_map('intval', $config['comptes'] ?? []);
    $comptes = array_values(array_filter($comptes, fn($c) =>
        ($forcees[(string) ($c['unique_id'] ?? '')] ?? $forcees[(string) $c['id']] ?? null) !== 0));
    logMsg(count($comptes) . ' compte(s) bancaire(s) repris');

    // Le rapprochement ne fait que lire la base : il est aussi montré en mode --test
    $profils  = qalimo_profils_relies();
    $projets  = get_db()->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll();
    $nomsProjets = array_column($projets, 'name', 'id');
    $liens = [];
    foreach ($comptes as $c) {
        $pid = qalimo_societe_du_compte($c, $forcees, $profils, $projets);
        $liens[(int) $c['id']] = $pid;
        $iban = preg_replace('/\s+/', '', (string) ($c['iban'] ?? ''));
        [$etat] = qalimo_statut_compte(isset($c['status']) ? (string) $c['status'] : null);
        logMsg(sprintf('  compte %-6d %s — %s%s', $c['id'], $c['bank_metadata']['name'] ?? 'Banque ?', trim((string) ($c['nom'] ?? '')) ?: 'sans nom',
            $iban !== '' ? ' …' . substr($iban, -4) : ''));
        logMsg(sprintf('         solde %s €, %s, profil %s → %s',
            number_format((float) qb_nombre($c['montant'] ?? 0), 2, ',', ' '), $etat, $c['id_profile_nom'] ?? '-',
            $pid ? $nomsProjets[$pid] ?? "société $pid" : "NON RELIÉ : ajouter " . $c['id'] . " => id de la société (ou 0 pour l'ignorer) dans « comptes » de qalimo.local.php"));
    }

    // Opérations : toute la base la 1re fois (ou --tout), sinon les 60 derniers jours
    $premiere = !$test && !(int) get_db()->query("SELECT COUNT(*) FROM qalimo_operations")->fetchColumn();
    $du = ($tout || $premiere) ? null : date('Y-m-d', strtotime('-' . QB_JOURS_FENETRE . ' days'));
    logMsg('Lecture des opérations ' . ($du ? "depuis le $du" : "(tout l'historique)"));
    $operations = [];
    $vus = [];
    for ($page = 1; $page <= QB_PAGES_MAX; $page++) {
        $q = ['page' => $page, 'per_page' => QB_PAR_PAGE];
        if ($du) $q['date_min'] = $du;
        $lot = qb_liste(qb_get('/transactions', $jeton, $q));
        if ($test) logMsg("  page $page : " . count($lot) . ' opération(s)');
        $nouvelles = 0;
        foreach ($lot as $o) {
            if (!isset($o['id']) || isset($vus[$o['id']])) continue;
            $vus[$o['id']] = true;
            $operations[] = $o;
            $nouvelles++;
        }
        if (count($lot) < QB_PAR_PAGE || $nouvelles === 0) break; // dernière page (ou pagination ignorée)
    }
    logMsg(count($operations) . ' opération(s) lue(s)');

    if ($test) {
        // Entrées / sorties lues, compte par compte (une seule société par compte)
        $parCompte = [];
        foreach ($operations as $o) {
            $m = (float) qb_nombre($o['montant'] ?? 0);
            $k = (string) ($o['unique_account_id'] ?? '?');
            $parCompte[$k] ??= ['e' => 0.0, 's' => 0.0, 'n' => 0, 'min' => null];
            $parCompte[$k][$m >= 0 ? 'e' : 's'] += abs($m);
            $parCompte[$k]['n']++;
            $d = substr((string) ($o['date_transac'] ?? ''), 0, 10);
            if ($d !== '' && ($parCompte[$k]['min'] === null || $d < $parCompte[$k]['min'])) $parCompte[$k]['min'] = $d;
        }
        logMsg('Entrées et sorties lues ' . ($du ? "depuis le $du" : 'sur tout l\'historique') . ' :');
        foreach ($comptes as $c) {
            $r = $parCompte[(string) ($c['unique_id'] ?? $c['id'])] ?? ['e' => 0, 's' => 0, 'n' => 0, 'min' => null];
            logMsg(sprintf('  compte %-6d %4d opération(s)  entrées %12s €  sorties %12s €%s', $c['id'], $r['n'],
                number_format($r['e'], 2, ',', ' '), number_format($r['s'], 2, ',', ' '), $r['min'] ? '  (dès le ' . $r['min'] . ')' : ''));
        }
        logMsg('Test terminé : rien n\'a été écrit');
        exit(0);
    }

    $soldes = qalimo_banques_enregistrer($comptes, $operations, $liens, $du);
    foreach ($soldes as $pid => $s) {
        logMsg(sprintf('  société %d : trésorerie %s €', $pid, number_format($s, 2, ',', ' ')));
    }
    $nonRelies = count(array_filter($liens, fn($p) => $p === null));
    $msg = count($comptes) . ' comptes (' . $nonRelies . ' non reliés), ' . count($operations) . ' opérations';
    logMsg("OK : $msg");
    journal('ok', count($operations), $msg, $du);
} catch (Throwable $e) {
    logMsg('ÉCHEC : ' . $e->getMessage());
    if (!$test) journal('error', 0, $e->getMessage(), $du);
    exit(1);
}
