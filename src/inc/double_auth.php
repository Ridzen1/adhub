<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Double authentification par code temporaire (TOTP, RFC 6238).
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
 * Rôles pour lesquels la double authentification est obligatoire.
 * Un utilisateur concerné qui ne l'a pas encore activée est redirigé vers la page
 * d'activation après sa connexion. Laisser vide pour la rendre facultative partout.
 */
const TOTP_ROLES_OBLIGATOIRES = ['direction'];

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

    $echecs = (int) $u['totp_failures'] + 1;
    $bloque = $echecs >= TOTP_ECHECS_MAX;
    $pdo->prepare(
        "UPDATE users SET totp_failures = :f, totp_locked_until = :l WHERE id = :id"
    )->execute([
        'f'  => $bloque ? 0 : $echecs,
        'l'  => $bloque ? date('Y-m-d H:i:s', time() + TOTP_BLOCAGE_MIN * 60) : null,
        'id' => $userId,
    ]);
    return $bloque ? 'bloque' : 'invalide';
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
