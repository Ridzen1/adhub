<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/access.php';

/**
 * Liens pour choisir son mot de passe soi-même, envoyés par e-mail :
 *  - invitation : envoyée par la direction (un compte ou tous), valable 7 jours ;
 *  - mot de passe oublié : demandé depuis la page de connexion, valable 1 heure.
 *
 * Aucune table : le lien porte l'id du compte, son type, sa date d'expiration et une
 * signature. La clé de signature contient l'empreinte ACTUELLE du mot de passe : dès
 * que le mot de passe est choisi, l'empreinte change et le lien ne marche plus
 * (usage unique). Elle contient aussi un secret serveur (mot de passe de la base, ou
 * 'secret' de config.local.php), car les comptes créés sans mot de passe partagent la
 * même empreinte « !a-definir ».
 */

const LIEN_MDP_DUREES = [
    'i' => 7 * 86400, // invitation
    'o' => 3600,      // mot de passe oublié
];
const MDP_LONGUEUR_MIN        = 8;
const MDP_OUBLI_DELAI_SEC     = 300; // un e-mail « mot de passe oublié » par compte toutes les 5 min
const MDP_OUBLI_MAX_SESSION   = 5;   // demandes par navigateur

function lien_mdp_secret_serveur(): string
{
    $conf = config_locale();
    return (string) ($conf['secret'] ?? (get_db_config()['pass'] ?? ''));
}

function lien_mdp_signature(array $user, string $type, int $expire): string
{
    $cle = 'adhub-mdp|' . lien_mdp_secret_serveur() . '|' . $user['password_hash'] . '|' . $user['email'];
    return hash_hmac('sha256', $user['id'] . '|' . $type . '|' . $expire, $cle);
}

/** Jeton à mettre dans l'URL : <id>-<type>-<expiration>-<signature> */
function lien_mdp_jeton(array $user, string $type): string
{
    $expire = time() + LIEN_MDP_DUREES[$type];
    return (int) $user['id'] . '-' . $type . '-' . $expire . '-' . lien_mdp_signature($user, $type, $expire);
}

/**
 * Vérifie un jeton. Retourne ['user' => …, 'type' => 'i'|'o'] ou une chaîne d'erreur :
 * 'invalide' (lien abîmé, déjà utilisé, compte désactivé) ou 'expire'.
 */
function lien_mdp_verifier(string $jeton): array|string
{
    if (!preg_match('/^(\d+)-([io])-(\d+)-([0-9a-f]{64})$/', $jeton, $m)) {
        return 'invalide';
    }
    [, $id, $type, $expire, $sig] = $m;
    $user = get_user((int) $id);
    if (!$user || !$user['is_active'] || !hash_equals(lien_mdp_signature($user, $type, (int) $expire), $sig)) {
        return 'invalide';
    }
    if ((int) $expire < time()) {
        return 'expire';
    }
    return ['user' => $user, 'type' => $type];
}

/** Adresse publique du site, pour les liens des e-mails (jamais l'en-tête Host en production) */
function url_site(): string
{
    $conf = config_locale();
    if (!empty($conf['url_site'])) {
        return rtrim((string) $conf['url_site'], '/');
    }
    if (est_en_production()) {
        return 'https://ad-hub.fr';
    }
    return 'http://' . ($_SERVER['HTTP_HOST'] ?? 'localhost:8080');
}

function lien_mdp_url(array $user, string $type): string
{
    return url_site() . '/pages/definir_mot_de_passe.php?t=' . lien_mdp_jeton($user, $type);
}

function prenom_de(array $user): string
{
    return trim(explode(' ', (string) ($user['name'] ?? ''))[0]);
}

/** Invitation à choisir son mot de passe (envoyée par la direction). true si l'e-mail est parti. */
function envoyer_invitation(array $user): bool
{
    if (!$user['is_active'] || !email_valide_pour_envoi($user['email'])) {
        return false;
    }
    $prenom = prenom_de($user);
    $texte = ($prenom !== '' ? "Bonjour $prenom,\n\n" : "Bonjour,\n\n")
        . "Votre accès à AD'HUB, le tableau de bord d'AD Holding, est prêt.\n\n"
        . "Pour choisir vous-même votre mot de passe, ouvrez ce lien :\n\n"
        . lien_mdp_url($user, 'i') . "\n\n"
        . "Le lien est valable 7 jours et ne sert qu'une fois.\n\n"
        . "Votre identifiant de connexion : " . $user['email'] . "\n"
        . "Adresse du site : " . url_site() . "\n\n"
        . "À chaque connexion depuis un nouvel appareil, un code à 6 chiffres vous sera\n"
        . "aussi envoyé par e-mail.\n\n"
        . "Si le lien a expiré, utilisez « Mot de passe oublié ? » sur la page de connexion.\n\n"
        . "— AD'HUB\n";
    return envoyer_mail($user['email'], "Votre accès AD'HUB : choisissez votre mot de passe", $texte, mail_expediteur_comptes());
}

/** E-mail « mot de passe oublié ». true si l'e-mail est parti. */
function envoyer_lien_oubli(array $user): bool
{
    if (!$user['is_active'] || !email_valide_pour_envoi($user['email'])) {
        return false;
    }
    $prenom = prenom_de($user);
    $texte = ($prenom !== '' ? "Bonjour $prenom,\n\n" : "Bonjour,\n\n")
        . "Une demande de nouveau mot de passe a été faite pour votre compte AD'HUB.\n\n"
        . "Pour en choisir un, ouvrez ce lien :\n\n"
        . lien_mdp_url($user, 'o') . "\n\n"
        . "Le lien est valable 1 heure et ne sert qu'une fois.\n\n"
        . "Si vous n'avez rien demandé, ignorez ce message : votre mot de passe actuel\n"
        . "reste valable.\n\n"
        . "— AD'HUB\n";
    return envoyer_mail($user['email'], "AD'HUB : choisir un nouveau mot de passe", $texte, mail_expediteur_comptes());
}

/**
 * Demande « mot de passe oublié » pour une adresse. Ne dit jamais si le compte existe.
 * Limites : MDP_OUBLI_MAX_SESSION demandes par navigateur, un e-mail par compte toutes
 * les MDP_OUBLI_DELAI_SEC secondes (petit fichier horodaté dans le dossier temporaire).
 * Retourne 'ok' ou 'limite'.
 */
function demander_lien_oubli(string $email): string
{
    start_session_once();
    $_SESSION['mdp_oubli'] = array_values(array_filter(
        $_SESSION['mdp_oubli'] ?? [],
        fn ($t) => $t > time() - 3600
    ));
    if (count($_SESSION['mdp_oubli']) >= MDP_OUBLI_MAX_SESSION) {
        return 'limite';
    }
    $_SESSION['mdp_oubli'][] = time();

    $stmt = get_db()->prepare("SELECT * FROM users WHERE email = :e AND is_active = 1");
    $stmt->execute(['e' => trim($email)]);
    $user = $stmt->fetch();
    if (!$user) {
        return 'ok';
    }

    $repere = sys_get_temp_dir() . '/adhub_mdp_' . hash('sha256', lien_mdp_secret_serveur() . '|' . $user['id']);
    $dernier = is_readable($repere) ? (int) @file_get_contents($repere) : 0;
    if (time() - $dernier < MDP_OUBLI_DELAI_SEC) {
        return 'ok'; // déjà envoyé à l'instant : on ne renvoie pas, sans le dire
    }
    if (envoyer_lien_oubli($user)) {
        @file_put_contents($repere, (string) time());
    }
    return 'ok';
}

/** Contrôle d'un nouveau mot de passe. Retourne la liste des erreurs (vide si correct). */
function mdp_erreurs(string $nouveau, string $confirmation, array $user): array
{
    $erreurs = [];
    if (strlen($nouveau) < MDP_LONGUEUR_MIN) {
        $erreurs[] = "Le mot de passe doit faire au moins " . MDP_LONGUEUR_MIN . " caractères.";
    }
    if ($nouveau !== $confirmation) {
        $erreurs[] = "Les deux mots de passe ne sont pas identiques.";
    }
    if ($nouveau !== '' && strcasecmp($nouveau, (string) $user['email']) === 0) {
        $erreurs[] = "Le mot de passe ne doit pas être votre adresse e-mail.";
    }
    return $erreurs;
}
