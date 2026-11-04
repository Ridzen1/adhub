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

/**
 * Adresse des e-mails « humains » (invitations, mot de passe oublié) : une vraie boîte,
 * pour que les réponses arrivent quelque part. Les codes de connexion, eux, partent
 * de mail_expediteur() (ne-pas-repondre@…).
 */
function mail_expediteur_comptes(): array
{
    $conf = config_locale()['mail'] ?? [];
    return [
        'adresse' => (string) ($conf['expediteur_comptes'] ?? 'contact@ad-hub.fr'),
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

/**
 * Réglages SMTP de config.local.php (bloc 'mail' => 'smtp'), ou null si absents.
 * Avec ces réglages, ADHUB envoie comme le webmail : connexion avec le mot de passe
 * de la boîte. Sans eux, il passe par la fonction mail() du serveur, que le relais
 * d'envoi d'o2switch peut retenir pour un domaine récent.
 */
function mail_smtp_config(): ?array
{
    $smtp = config_locale()['mail']['smtp'] ?? null;
    if (!is_array($smtp) || empty($smtp['utilisateur']) || empty($smtp['mot_de_passe'])) {
        return null;
    }
    return [
        'hote'         => (string) ($smtp['hote'] ?? 'ad-hub.fr'),
        'port'         => (int) ($smtp['port'] ?? 465),
        'chiffrement'  => (string) ($smtp['chiffrement'] ?? 'ssl'), // ssl (465), tls (587) ou aucun
        'utilisateur'  => (string) $smtp['utilisateur'],
        'mot_de_passe' => (string) $smtp['mot_de_passe'],
    ];
}

/** Message complet (en-têtes + corps) prêt pour SMTP */
function mail_message_brut(string $destinataire, string $sujet, string $texte, array $exp): string
{
    $domaine = substr(strrchr($exp['adresse'], '@'), 1) ?: 'ad-hub.fr';
    $corps = quoted_printable_encode(str_replace(["\r\n", "\r", "\n"], ["\n", "\n", "\r\n"], $texte));
    return implode("\r\n", [
        'Date: ' . date('r'),
        'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . $domaine . '>',
        'From: ' . mail_entete_utf8($exp['nom']) . ' <' . $exp['adresse'] . '>',
        'To: <' . $destinataire . '>',
        'Reply-To: ' . $exp['adresse'],
        'Subject: ' . mail_entete_utf8($sujet),
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: quoted-printable',
        'X-Mailer: ADHUB',
    ]) . "\r\n\r\n" . $corps;
}

/**
 * Envoi SMTP authentifié, sans bibliothèque. Lève une RuntimeException en cas d'échec
 * (le message ne contient jamais le mot de passe).
 */
function envoyer_smtp(array $c, string $expediteur, string $destinataire, string $message): void
{
    $prefixe = $c['chiffrement'] === 'ssl' ? 'ssl://' : 'tcp://';
    $contexte = stream_context_create(['ssl' => ['peer_name' => $c['hote'], 'SNI_enabled' => true]]);
    $flux = @stream_socket_client($prefixe . $c['hote'] . ':' . $c['port'], $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $contexte);
    if (!$flux) {
        throw new RuntimeException("connexion à {$c['hote']}:{$c['port']} impossible ($errstr)");
    }
    stream_set_timeout($flux, 20);

    $lire = function () use ($flux): array {
        $texte = '';
        while (($ligne = fgets($flux, 1024)) !== false) {
            $texte .= $ligne;
            if (strlen($ligne) < 4 || $ligne[3] !== '-') {
                break;
            }
        }
        return [(int) substr($texte, 0, 3), trim($texte)];
    };
    $commande = function (?string $ligne, array $attendus, string $etape) use ($flux, $lire): void {
        if ($ligne !== null) {
            fwrite($flux, $ligne . "\r\n");
        }
        [$code, $reponse] = $lire();
        if (!in_array($code, $attendus, true)) {
            throw new RuntimeException("$etape refusé : $reponse");
        }
    };

    try {
        $hoteLocal = gethostname() ?: 'adhub';
        $commande(null, [220], 'accueil');
        $commande('EHLO ' . $hoteLocal, [250], 'EHLO');
        if ($c['chiffrement'] === 'tls') {
            $commande('STARTTLS', [220], 'STARTTLS');
            if (!stream_socket_enable_crypto($flux, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('chiffrement TLS impossible');
            }
            $commande('EHLO ' . $hoteLocal, [250], 'EHLO');
        }
        $commande('AUTH LOGIN', [334], 'AUTH');
        $commande(base64_encode($c['utilisateur']), [334], 'identifiant');
        $commande(base64_encode($c['mot_de_passe']), [235], 'mot de passe');
        $commande('MAIL FROM:<' . $expediteur . '>', [250], 'expéditeur');
        $commande('RCPT TO:<' . $destinataire . '>', [250, 251], 'destinataire');
        $commande('DATA', [354], 'DATA');
        // Une ligne commençant par un point est doublée (RFC 5321)
        $commande(preg_replace('/^\./m', '..', $message) . "\r\n.", [250], 'envoi');
        fwrite($flux, "QUIT\r\n");
    } finally {
        fclose($flux);
    }
}

/**
 * Envoie un e-mail texte. true si le serveur l'a accepté.
 * $exp : expéditeur ['adresse' => …, 'nom' => …], par défaut mail_expediteur().
 * Avec un bloc SMTP dans config.local.php, l'e-mail part de la boîte configurée (le
 * serveur n'accepte pas d'expéditeur différent du compte connecté).
 */
function envoyer_mail(string $destinataire, string $sujet, string $texte, ?array $exp = null): bool
{
    if (!email_valide_pour_envoi($destinataire)) {
        return false;
    }
    $exp ??= mail_expediteur();

    if (!est_en_production()) {
        error_log("[ADHUB mail local, non envoyé] De : {$exp['adresse']} | À : $destinataire | $sujet\n$texte");
        return true;
    }

    $smtp = mail_smtp_config();
    if ($smtp !== null) {
        $exp['adresse'] = $smtp['utilisateur'];
        try {
            envoyer_smtp($smtp, $exp['adresse'], $destinataire, mail_message_brut($destinataire, $sujet, $texte, $exp));
            return true;
        } catch (Throwable $e) {
            error_log("ADHUB : échec d'envoi SMTP vers $destinataire : " . $e->getMessage() . ' (essai avec mail())');
        }
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
