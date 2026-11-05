<?php
/**
 * Import du « Z quotidien » que L'Addition envoie chaque nuit par courriel (Valentino).
 *
 * Le courriel arrive sur contact@ad-hub.fr, hébergé chez o2switch. Le script lit
 * directement les fichiers de la boîte (Maildir, ~/mail/ad-hub.fr/contact) : pas de
 * mot de passe, pas d'extension IMAP. Les courriels ne sont ni déplacés ni supprimés.
 *
 * Usage :
 *   php cron/caisses/z_quotidien_mail.php                 -> courriels reçus ces 3 derniers jours
 *   php cron/caisses/z_quotidien_mail.php --jours=60      -> rattrapage sur 60 jours
 *   php cron/caisses/z_quotidien_mail.php --tout          -> toute la boîte
 *   php cron/caisses/z_quotidien_mail.php fichier.eml     -> un courriel enregistré (.eml)
 *   php cron/caisses/z_quotidien_mail.php -               -> courriel sur l'entrée standard
 *   ajouter --test pour lire sans rien écrire, --force pour remplacer une saisie manuelle
 *
 * Cron cPanel (le Z arrive vers minuit) :
 *   15 6 * * * /usr/local/bin/php ~/adhub.fr/cron/caisses/z_quotidien_mail.php >/dev/null 2>&1
 *
 * Réglages facultatifs dans config/caisses.local.php :
 *   'z_quotidien' => ['Valentino' => 16],          // établissement → id société (sinon trouvé par le nom)
 *   'z_quotidien_maildir' => '/home/USER/mail/ad-hub.fr/contact',
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
date_default_timezone_set('Europe/Paris');

require_once __DIR__ . '/../../inc/z_quotidien.php';

$fichierConfig = getenv('CAISSES_CONFIG') ?: __DIR__ . '/../../config/caisses.local.php';
$config = is_file($fichierConfig) ? (require $fichierConfig) : [];

function zlog(string $msg): void
{
    global $config;
    $ligne = '[' . date('Y-m-d H:i:s') . "] $msg\n";
    echo $ligne;
    if (!empty($config['log_file'])) {
        @file_put_contents($config['log_file'], $ligne, FILE_APPEND);
    }
}

function z_journal(string $compte, string $jour, int $n, string $statut, string $msg): void
{
    try {
        get_db()->prepare(
            'INSERT INTO sync_runs (source, account, date_from, date_to, nb_lines, status, message) VALUES (?,?,?,?,?,?,?)'
        )->execute(['z_courriel', mb_substr($compte, 0, 100), $jour, $jour, $n, $statut, $msg]);
    } catch (Throwable $e) {
        // table sync_runs absente : le journal du terminal suffit
    }
}

/** Fichiers de la boîte (dossiers cur/ et new/, y compris les sous-dossiers comme .spam). */
function z_fichiers_boite(string $maildir, ?int $depuis): array
{
    $fichiers = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($maildir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $f) {
        /** @var SplFileInfo $f */
        $dossier = basename($f->getPath());
        if (!$f->isFile() || ($dossier !== 'cur' && $dossier !== 'new')) {
            continue;
        }
        if ($f->getSize() > 3_000_000 || ($depuis !== null && $f->getMTime() < $depuis)) {
            continue;
        }
        $fichiers[$f->getPathname()] = $f->getMTime();
    }
    asort($fichiers); // du plus ancien au plus récent : le dernier Z reçu pour un jour l'emporte
    return $fichiers;
}

/** Repère vite un Z de L'Addition sans décoder tout le courriel. */
function z_ressemble(string $chemin): bool
{
    $debut = (string) @file_get_contents($chemin, false, null, 0, 20000);
    return stripos($debut, 'laddition.com') !== false && stripos($debut, 'Z quotidien') !== false;
}

// ------------------------------------------------------------------------ main

$opts  = array_slice($argv, 1);
$force = in_array('--force', $opts, true);
$test  = in_array('--test', $opts, true);
$tout  = in_array('--tout', $opts, true);
$jours = 3;
$cibles = [];
foreach ($opts as $o) {
    if (preg_match('/^--jours=(\d+)$/', $o, $m)) {
        $jours = max(1, (int) $m[1]);
    } elseif ($o === '-' || !str_starts_with($o, '--')) {
        $cibles[] = $o;
    }
}

// Liste des courriels à lire : [chemin ou '-' => date de réception]
$courriels = [];
if ($cibles) {
    foreach ($cibles as $c) {
        $courriels[$c] = $c === '-' ? time() : (int) @filemtime($c);
    }
} else {
    $home = getenv('HOME') ?: (function_exists('posix_getpwuid') ? (posix_getpwuid(posix_geteuid())['dir'] ?? '') : '');
    $maildir = $config['z_quotidien_maildir'] ?? "$home/mail/ad-hub.fr/contact";
    if (!is_dir($maildir)) {
        zlog("ERREUR : boîte mail introuvable ($maildir). Renseigner 'z_quotidien_maildir' dans config/caisses.local.php.");
        z_journal('Z quotidien', date('Y-m-d'), 0, 'error', "Boîte mail introuvable : $maildir");
        exit(1);
    }
    $toutes = z_fichiers_boite($maildir, $tout ? null : time() - $jours * 86400);
    foreach ($toutes as $chemin => $mtime) {
        if (z_ressemble($chemin)) {
            $courriels[$chemin] = $mtime;
        }
    }
    zlog(sprintf('%d courriel(s) lu(s) dans %s, dont %d Z quotidien(s) L\'Addition', count($toutes), $maildir, count($courriels)));
}

$forcees = (array) ($config['z_quotidien'] ?? []);
$erreurs = 0;
$dejaFaits = 0;

foreach ($courriels as $chemin => $recu) {
    $brut = $chemin === '-' ? stream_get_contents(STDIN) : @file_get_contents($chemin);
    if ($brut === false || $brut === '') {
        zlog("ERREUR : lecture impossible de $chemin");
        $erreurs++;
        continue;
    }
    $z = z_lire_courriel($brut);
    if ($z === null) {
        zlog("Ignoré (pas un Z quotidien L'Addition) : " . basename($chemin));
        continue;
    }

    $nom = $z['etablissement'];
    $r = $z['rapport'];
    if ($test) {
        zlog(sprintf("[test] %s %s : CA TTC %s, HT %s, %s couverts, solide %s, liquide %s, %d lignes de vente%s",
            $nom, $z['jour'], $r['ca_ttc'] ?? '?', $r['ca_ht'] ?? '?', $r['couverts'] ?? '?',
            $r['ca_solide'] ?? '—', $r['ca_liquide'] ?? '—', count($r['ventes']),
            $r['erreurs'] || $r['avertissements'] ? ' — ' . implode(' ', array_merge($r['erreurs'], $r['avertissements'])) : ''));
        continue;
    }

    try {
        $pid = z_societe($nom, $forcees);
        if ($pid === null) {
            throw new RuntimeException("établissement « $nom » non rattaché à une société : ajouter "
                . "'z_quotidien' => ['$nom' => ID] dans config/caisses.local.php");
        }
        // Boîte mail : déjà importé depuis ce courriel (lancement précédent), on passe.
        // Un fichier donné à la main est toujours relu.
        if (!$cibles && !$force && caisse_z_disponible()) {
            $st = get_db()->prepare("SELECT MAX(recu_le) FROM laddition_z_ventes WHERE project_id = ? AND jour = ?");
            $st->execute([$pid, $z['jour']]);
            $dernier = $st->fetchColumn();
            if ($dernier && strtotime((string) $dernier) >= $recu) {
                $dejaFaits++;
                continue;
            }
        }
        $msg = z_enregistrer($pid, $z, $force);
        zlog("$nom → société $pid, $msg");
        z_journal($nom, $z['jour'], count($r['ventes']), 'ok', $msg);
    } catch (Throwable $e) {
        $erreurs++;
        zlog("ERREUR $nom {$z['jour']} : " . $e->getMessage());
        z_journal($nom, $z['jour'], 0, 'error', $e->getMessage());
    }
}

if ($dejaFaits) {
    zlog("$dejaFaits Z déjà importé(s), laissé(s) tels quels (--force pour les relire)");
}
exit($erreurs ? 1 : 0);
