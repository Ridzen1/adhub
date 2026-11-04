<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/access.php';

/**
 * Double authentification, obligatoire pour tous les comptes :
 *  - par défaut, un code à 6 chiffres envoyé par e-mail à chaque connexion
 *    (sauf sur un appareil marqué « de confiance » pendant 30 jours) ;
 *  - en option, une application d'authentification (TOTP, ci-dessous) pour ceux
 *    qui le souhaitent : elle remplace alors l'e-mail.
 *
 * Code temporaire par application (TOTP, RFC 6238) :
 *
 * Compatible avec toutes les applications d'authentification courantes : Google
 * Authenticator, Microsoft Authenticator, 1Password, Bitwarden… Code à 6 chiffres,
 * renouvelé toutes les 30 secondes, HMAC-SHA1 (le réglage par défaut de ces applis).
 *
 * Aucune dépendance externe : le QR code est dessiné dans le navigateur, le secret
 * ne quitte jamais le serveur ADHUB.
 */

const TOTP_PERIODE      = 30;
const TOTP_CHIFFRES     = 6;
const TOTP_TOLERANCE    = 1;   // ± 1 pas de 30 s : tolère une horloge de téléphone un peu décalée
const TOTP_ECHECS_MAX   = 8;   // échecs consécutifs avant blocage
const TOTP_BLOCAGE_MIN  = 15;  // durée du blocage
const TOTP_NB_SECOURS   = 10;
const TOTP_EMETTEUR     = "AD'HUB";

/**
 * Rôles pour lesquels l'APPLICATION d'authentification est obligatoire.
 * Vide : le code par e-mail suffit pour tout le monde (choix de Kader, novembre 2026 :
 * les utilisateurs n'installeront pas d'application).
 */
const TOTP_ROLES_OBLIGATOIRES = [];

const CODE_MAIL_CHIFFRES       = 6;
const CODE_MAIL_VALIDITE_MIN   = 10;  // durée de vie d'un code envoyé
const CODE_MAIL_RENVOI_SEC     = 60;  // délai avant de pouvoir redemander un code
const CODE_MAIL_ENVOIS_MAX     = 5;   // codes envoyés au plus par tentative de connexion
const APPAREIL_CONFIANCE_JOURS = 30;
const APPAREIL_COOKIE          = 'adhub_appareil_';

/* ------------------------------------------------------------------ */
/* Base32 et calcul du code                                            */
/* ------------------------------------------------------------------ */

const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

function base32_encoder(string $octets): string
{
    $bits = '';
    foreach (str_split($octets) as $c) {
        $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 5) as $bloc) {
        $sortie .= BASE32_ALPHABET[bindec(str_pad($bloc, 5, '0', STR_PAD_RIGHT))];
    }
    return $sortie;
}

function base32_decoder(string $texte): string
{
    $texte = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $texte));
    $bits = '';
    foreach (str_split($texte) as $c) {
        $bits .= str_pad(decbin(strpos(BASE32_ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
    }
    $sortie = '';
    foreach (str_split($bits, 8) as $octet) {
        if (strlen($octet) === 8) {
            $sortie .= chr(bindec($octet));
        }
    }
    return $sortie;
}

/** Nouveau secret : 160 bits aléatoires, soit 32 caractères base32 */
function totp_nouveau_secret(): string
{
    return base32_encoder(random_bytes(20));
}

function totp_code(string $secret, int $pas): string
{
    $compteur = pack('N*', 0, $pas); // entier 64 bits gros-boutiste
    $hash = hash_hmac('sha1', $compteur, base32_decoder($secret), true);
    $decalage = ord($hash[19]) & 0x0F;
    $valeur = ((ord($hash[$decalage]) & 0x7F) << 24)
            | (ord($hash[$decalage + 1]) << 16)
            | (ord($hash[$decalage + 2]) << 8)
            | ord($hash[$decalage + 3]);
    return str_pad((string) ($valeur % (10 ** TOTP_CHIFFRES)), TOTP_CHIFFRES, '0', STR_PAD_LEFT);
}

function totp_pas_courant(?int $horodatage = null): int
{
    return intdiv($horodatage ?? time(), TOTP_PERIODE);
}

/**
 * Vérifie un code saisi. Retourne le pas reconnu, ou null.
 * $dernierPas : pas déjà utilisé ; tout code de ce pas ou d'un pas antérieur est refusé
 * (un code intercepté ne peut pas être rejoué).
 */
function totp_verifier(string $secret, string $saisie, ?int $dernierPas = null, ?int $horodatage = null): ?int
{
    $saisie = preg_replace('/\D/', '', $saisie);
    if (strlen($saisie) !== TOTP_CHIFFRES) {
        return null;
    }
    $courant = totp_pas_courant($horodatage);
    for ($d = -TOTP_TOLERANCE; $d <= TOTP_TOLERANCE; $d++) {
        $pas = $courant + $d;
        if ($dernierPas !== null && $pas <= $dernierPas) {
            continue;
        }
        if (hash_equals(totp_code($secret, $pas), $saisie)) {
            return $pas;
        }
    }
    return null;
}

/** Adresse otpauth:// lue par les applications (contenu du QR code) */
function totp_uri(string $secret, string $compte): string
{
    return 'otpauth://totp/' . rawurlencode(TOTP_EMETTEUR . ':' . $compte)
         . '?secret=' . $secret
         . '&issuer=' . rawurlencode(TOTP_EMETTEUR)
         . '&algorithm=SHA1&digits=' . TOTP_CHIFFRES . '&period=' . TOTP_PERIODE;
}

/** Secret affiché par groupes de 4 pour la saisie manuelle */
function totp_secret_lisible(string $secret): string
{
    return trim(chunk_split($secret, 4, ' '));
}

/* ------------------------------------------------------------------ */
/* Codes de secours                                                    */
/* ------------------------------------------------------------------ */

function code_secours_empreinte(string $code): string
{
    return hash('sha256', strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code)));
}

/**
 * Remplace les codes de secours d'un utilisateur. Retourne les codes en clair,
 * à montrer une seule fois : ils ne sont plus lisibles ensuite.
 */
function codes_secours_generer(int $userId): array
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // sans 0/O ni 1/I, souvent confondus
    $codes = [];
    for ($i = 0; $i < TOTP_NB_SECOURS; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) {
            $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
    }

    $pdo = get_db();
    $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = :u")->execute(['u' => $userId]);
    $ins = $pdo->prepare("INSERT INTO user_recovery_codes (user_id, code_hash) VALUES (:u, :h)");
    foreach ($codes as $c) {
        $ins->execute(['u' => $userId, 'h' => code_secours_empreinte($c)]);
    }
    return $codes;
}

/** Consomme un code de secours. true s'il était valide et inutilisé. */
function code_secours_utiliser(int $userId, string $saisie): bool
{
    $stmt = get_db()->prepare(
        "UPDATE user_recovery_codes SET used_at = NOW()
         WHERE user_id = :u AND code_hash = :h AND used_at IS NULL LIMIT 1"
    );
    $stmt->execute(['u' => $userId, 'h' => code_secours_empreinte($saisie)]);
    return $stmt->rowCount() === 1;
}

function codes_secours_restants(int $userId): int
{
    $stmt = get_db()->prepare("SELECT COUNT(*) FROM user_recovery_codes WHERE user_id = :u AND used_at IS NULL");
    $stmt->execute(['u' => $userId]);
    return (int) $stmt->fetchColumn();
}

/* ------------------------------------------------------------------ */
/* État en base                                                        */
/* ------------------------------------------------------------------ */

function double_auth_activer(int $userId, string $secret, int $pas): void
{
    get_db()->prepare(
        "UPDATE users SET totp_secret = :s, totp_enabled = 1, totp_enabled_at = NOW(),
                          totp_last_step = :p, totp_failures = 0, totp_locked_until = NULL
         WHERE id = :id"
    )->execute(['s' => $secret, 'p' => $pas, 'id' => $userId]);
}

/** Désactive la 2FA et efface secret et codes de secours (aussi utilisé par la direction pour un téléphone perdu) */
function double_auth_desactiver(int $userId): void
{
    $pdo = get_db();
    $pdo->prepare(
        "UPDATE users SET totp_secret = NULL, totp_enabled = 0, totp_enabled_at = NULL,
                          totp_last_step = NULL, totp_failures = 0, totp_locked_until = NULL
         WHERE id = :id"
    )->execute(['id' => $userId]);
    $pdo->prepare("DELETE FROM user_recovery_codes WHERE user_id = :u")->execute(['u' => $userId]);
}

function double_auth_est_obligatoire(?string $roleCode): bool
{
    return in_array($roleCode, TOTP_ROLES_OBLIGATOIRES, true);
}

/** Minutes de blocage restantes (0 = pas bloqué) */
function double_auth_blocage_restant(array $user): int
{
    if (empty($user['totp_locked_until'])) {
        return 0;
    }
    $reste = strtotime($user['totp_locked_until']) - time();
    return $reste > 0 ? (int) ceil($reste / 60) : 0;
}

/**
 * Contrôle complet d'un code saisi à la connexion : code de l'appli ou code de secours,
 * avec anti-rejeu et blocage après trop d'échecs.
 * Retourne 'ok', 'secours' (code de secours accepté), 'invalide' ou 'bloque'.
 */
function double_auth_controler(int $userId, string $saisie): string
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE id = :id");
    $stmt->execute(['id' => $userId]);
    $u = $stmt->fetch();
    if (!$u || !$u['totp_enabled']) {
        return 'invalide';
    }
    if (double_auth_blocage_restant($u) > 0) {
        return 'bloque';
    }

    $pas = totp_verifier($u['totp_secret'], $saisie, $u['totp_last_step'] !== null ? (int) $u['totp_last_step'] : null);
    if ($pas !== null) {
        $pdo->prepare("UPDATE users SET totp_last_step = :p, totp_failures = 0, totp_locked_until = NULL WHERE id = :id")
            ->execute(['p' => $pas, 'id' => $userId]);
        return 'ok';
    }

    // Un code de secours fait 10 caractères (hors tiret) ; un code de l'appli, 6 chiffres
    if (strlen(preg_replace('/[^A-Za-z0-9]/', '', $saisie)) === 10 && code_secours_utiliser($userId, $saisie)) {
        $pdo->prepare("UPDATE users SET totp_failures = 0, totp_locked_until = NULL WHERE id = :id")->execute(['id' => $userId]);
        return 'secours';
    }

    return double_auth_noter_echec($u);
}

/** Compte un code erroné (appli ou e-mail) ; bloque le compte 15 min après trop d'échecs. */
function double_auth_noter_echec(array $u): string
{
    $echecs = (int) $u['totp_failures'] + 1;
    $bloque = $echecs >= TOTP_ECHECS_MAX;
    get_db()->prepare(
        "UPDATE users SET totp_failures = :f, totp_locked_until = :l WHERE id = :id"
    )->execute([
        'f'  => $bloque ? 0 : $echecs,
        'l'  => $bloque ? date('Y-m-d H:i:s', time() + TOTP_BLOCAGE_MIN * 60) : null,
        'id' => (int) $u['id'],
    ]);
    return $bloque ? 'bloque' : 'invalide';
}

/* ------------------------------------------------------------------ */
/* Code de connexion par e-mail                                        */
/* ------------------------------------------------------------------ */

/** Un second facteur est-il exigé pour ce compte ? (toujours, sauf interrupteur de secours) */
function double_auth_requise(array $user): bool
{
    return !empty($user['totp_enabled']) || double_auth_email_active();
}

/** Interrupteur de secours de config.local.php ('double_auth_email' => false) */
function double_auth_email_active(): bool
{
    return (config_locale()['double_auth_email'] ?? true) !== false;
}

/**
 * Crée un code, l'envoie à l'utilisateur et le garde (haché) dans la tentative de
 * connexion en cours ($_SESSION['auth_attente']). Rien n'est écrit en base.
 * Retourne 'envoye', 'attendre' (renvoi trop rapproché), 'limite', 'email_invalide' ou 'echec'.
 */
function code_mail_envoyer(array $user): string
{
    $attente = &$_SESSION['auth_attente'];
    if (!email_valide_pour_envoi($user['email'] ?? '')) {
        return 'email_invalide';
    }
    $precedent = $attente['mail'] ?? null;
    if ($precedent && time() - $precedent['envoye'] < CODE_MAIL_RENVOI_SEC) {
        return 'attendre';
    }
    $envois = (int) ($precedent['envois'] ?? 0);
    if ($envois >= CODE_MAIL_ENVOIS_MAX) {
        return 'limite';
    }

    $code = str_pad((string) random_int(0, 10 ** CODE_MAIL_CHIFFRES - 1), CODE_MAIL_CHIFFRES, '0', STR_PAD_LEFT);
    $sel  = bin2hex(random_bytes(16));
    $attente['mail'] = [
        'empreinte' => hash('sha256', $sel . $code),
        'sel'       => $sel,
        'expire'    => time() + CODE_MAIL_VALIDITE_MIN * 60,
        'envoye'    => time(),
        'envois'    => $envois + 1,
    ];
    if (!est_en_production()) {
        $attente['mail']['code_local'] = $code; // affiché à l'écran en local uniquement
    }

    $prenom = trim(explode(' ', (string) ($user['name'] ?? ''))[0]);
    $texte = ($prenom !== '' ? "Bonjour $prenom,\n\n" : "Bonjour,\n\n")
        . "Voici votre code de connexion à AD'HUB :\n\n"
        . "    $code\n\n"
        . "Il est valable " . CODE_MAIL_VALIDITE_MIN . " minutes et ne sert qu'une fois.\n\n"
        . "Si vous n'essayez pas de vous connecter, quelqu'un connaît votre mot de passe :\n"
        . "ne communiquez pas ce code et changez votre mot de passe dans « Mon compte ».\n\n"
        . "— AD'HUB\n";

    if (!envoyer_mail($user['email'], "Code de connexion AD'HUB : $code", $texte)) {
        unset($attente['mail']['empreinte']); // aucun code valable si l'e-mail n'est pas parti
        return 'echec';
    }
    return 'envoye';
}

/**
 * Contrôle le code e-mail saisi. Retourne 'ok', 'invalide', 'expire', 'aucun' ou 'bloque'.
 * Les erreurs comptent dans le même compteur que l'application (blocage 15 min).
 */
function code_mail_verifier(int $userId, string $saisie): string
{
    $u = get_user($userId);
    if (!$u) {
        return 'invalide';
    }
    if (double_auth_blocage_restant($u) > 0) {
        return 'bloque';
    }
    $m = $_SESSION['auth_attente']['mail'] ?? null;
    if (!$m || empty($m['empreinte'])) {
        return 'aucun';
    }
    if (time() > $m['expire']) {
        return 'expire';
    }
    $saisie = preg_replace('/\D/', '', $saisie);
    if (strlen($saisie) === CODE_MAIL_CHIFFRES && hash_equals($m['empreinte'], hash('sha256', $m['sel'] . $saisie))) {
        unset($_SESSION['auth_attente']['mail']); // usage unique
        get_db()->prepare("UPDATE users SET totp_failures = 0, totp_locked_until = NULL WHERE id = :id")
            ->execute(['id' => $userId]);
        return 'ok';
    }
    return double_auth_noter_echec($u);
}

/* ------------------------------------------------------------------ */
/* Appareils de confiance (30 jours sans code)                         */
/* ------------------------------------------------------------------ */

/**
 * Signature du cookie. Clé dérivée de l'empreinte du mot de passe et du secret de
 * l'application : changer de mot de passe, ou la réinitialisation de la 2FA par la
 * direction, rend aussitôt caducs tous les appareils de confiance du compte.
 */
function appareil_signature(array $user, int $expire): string
{
    $cle = 'adhub-appareil|' . $user['password_hash'] . '|' . ($user['totp_secret'] ?? '') . '|' . $user['email'];
    return hash_hmac('sha256', $user['id'] . '|' . $expire, $cle);
}

function appareil_memoriser(array $user): void
{
    $expire = time() + APPAREIL_CONFIANCE_JOURS * 86400;
    setcookie(APPAREIL_COOKIE . (int) $user['id'], $expire . '.' . appareil_signature($user, $expire), [
        'expires'  => $expire,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
}

function appareil_de_confiance(array $user): bool
{
    $valeur = $_COOKIE[APPAREIL_COOKIE . (int) $user['id']] ?? '';
    if (!is_string($valeur) || !preg_match('/^(\d+)\.([0-9a-f]{64})$/', $valeur, $m)) {
        return false;
    }
    $expire = (int) $m[1];
    return $expire > time() && hash_equals(appareil_signature($user, $expire), $m[2]);
}

/** Masque une adresse pour l'affichage : r•••••••0@gmail.com */
function email_masque(string $email): string
{
    [$local, $domaine] = array_pad(explode('@', $email, 2), 2, '');
    $n = strlen($local);
    $visible = $n <= 2 ? substr($local, 0, 1) . '•' : $local[0] . str_repeat('•', min(6, $n - 2)) . $local[$n - 1];
    return $visible . '@' . $domaine;
}

/* ------------------------------------------------------------------ */
/* Jeton anti-falsification des formulaires (CSRF)                     */
/* ------------------------------------------------------------------ */

function csrf_jeton(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_champ(): string
{
    return '<input type="hidden" name="csrf" value="' . csrf_jeton() . '">';
}

function csrf_valide(): bool
{
    return !empty($_SESSION['csrf']) && is_string($_POST['csrf'] ?? null) && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}
