<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Envoi d'e-mails par la fonction mail() de PHP, branchée sur le serveur d'envoi
 * d'o2switch : aucun service externe, aucun compte payant.
 *
 * En local (sans config.local.php), rien n'est envoyé : le message est écrit dans
 * le journal PHP, et la fonction renvoie true pour que les écrans restent testables.
 */

function mail_expediteur(): array
{
    $conf = config_locale()['mail'] ?? [];
    return [
        'adresse' => (string) ($conf['expediteur'] ?? 'ne-pas-repondre@ad-hub.fr'),
        'nom'     => (string) ($conf['expediteur_nom'] ?? "AD'HUB"),
    ];
}

/** En-tête encodé en UTF-8 (accents dans le sujet ou le nom d'expéditeur) */
function mail_entete_utf8(string $texte): string
{
    return preg_match('/[^\x20-\x7E]/', $texte) ? '=?UTF-8?B?' . base64_encode($texte) . '?=' : $texte;
}

/**
 * Adresse capable de recevoir un e-mail : syntaxe correcte et pas une adresse
 * provisoire (exemple, .invalid, .test…) comme celles créées par certaines migrations.
 */
function email_valide_pour_envoi(?string $email): bool
{
    $email = trim((string) $email);
    if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        return false;
    }
    $domaine = strtolower(substr(strrchr($email, '@'), 1));
    if (in_array($domaine, ['example.com', 'example.org', 'example.net', 'exemple.fr', 'exemple.com'], true)) {
        return false;
    }
    return !preg_match('/\.(invalid|test|example|localhost|local)$/', $domaine);
}

/** Envoie un e-mail texte. true si le serveur l'a accepté. */
function envoyer_mail(string $destinataire, string $sujet, string $texte): bool
{
    if (!email_valide_pour_envoi($destinataire)) {
        return false;
    }
    $exp = mail_expediteur();

    if (!est_en_production()) {
        error_log("[ADHUB mail local, non envoyé] À : $destinataire | $sujet\n$texte");
        return true;
    }

    $entetes = implode("\r\n", [
        'From: ' . mail_entete_utf8($exp['nom']) . ' <' . $exp['adresse'] . '>',
        'Reply-To: ' . $exp['adresse'],
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: ADHUB',
    ]);
    // Normalise les fins de ligne et évite les lignes trop longues pour les serveurs de messagerie
    $corps = wordwrap(str_replace(["\r\n", "\r"], "\n", $texte), 900, "\n", true);

    $ok = @mail($destinataire, mail_entete_utf8($sujet), $corps, $entetes, '-f' . $exp['adresse']);
    if (!$ok) {
        error_log("ADHUB : échec d'envoi d'e-mail vers $destinataire");
    }
    return $ok;
}
