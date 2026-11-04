<?php
/**
 * Deuxième étape de connexion, obligatoire pour tous : code reçu par e-mail
 * (cas général), ou code de l'application d'authentification / code de secours
 * pour les comptes qui l'ont activée. Le mot de passe a déjà été vérifié par login.php.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/ui.php';

const ATTENTE_MAX_SECONDES = 900; // 15 min pour aller chercher le code et le saisir
const ESSAIS_PAR_CONNEXION = 5;   // au-delà, retour au mot de passe

start_session_once();

if (is_logged_in()) {
    header('Location: /pages/accueil.php');
    exit;
}

$attente = $_SESSION['auth_attente'] ?? null;
if (!$attente || time() - $attente['depuis'] > ATTENTE_MAX_SECONDES || $attente['essais'] >= ESSAIS_PAR_CONNEXION) {
    unset($_SESSION['auth_attente']);
    header('Location: /pages/login.php' . ($attente ? '?expire=1' : ''));
    exit;
}

$userId  = (int) $attente['user_id'];
$user    = get_user($userId);
if (!$user || !$user['is_active']) {
    unset($_SESSION['auth_attente']);
    header('Location: /pages/login.php');
    exit;
}
$methode = $attente['methode'] ?? (!empty($user['totp_enabled']) ? 'appli' : 'mail');
$modeSecours = $methode === 'appli' && (isset($_GET['secours']) || isset($_POST['secours']));
$error = null;
$info  = null;

/** Message affiché après une demande d'envoi de code */
function message_envoi(string $resultat): array
{
    return match ($resultat) {
        'envoye'         => ['info', null],
        'attendre'       => ['erreur', "Patientez une minute avant de demander un nouveau code."],
        'limite'         => ['erreur', "Trop de codes demandés. Reconnectez-vous dans quelques minutes."],
        'email_invalide' => ['erreur', "Votre compte n'a pas d'adresse e-mail valide. Demandez à la direction de la corriger."],
        default          => ['erreur', "L'e-mail n'a pas pu être envoyé. Réessayez dans un instant avec « Renvoyer un code »."],
    };
}

// Résultat de l'envoi fait par login.php
if (isset($_SESSION['auth_attente']['envoi'])) {
    [$type, $texte] = message_envoi($_SESSION['auth_attente']['envoi']);
    unset($_SESSION['auth_attente']['envoi']);
    if ($type === 'erreur') {
        $error = $texte;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'valider';

    if ($action === 'annuler') {
        unset($_SESSION['auth_attente']);
        header('Location: /pages/login.php');
        exit;
    }

    if ($action === 'renvoyer' || $action === 'par_mail') {
        $_SESSION['auth_attente']['methode'] = $methode = 'mail';
        $modeSecours = false;
        $res = code_mail_envoyer($user);
        [$type, $texte] = message_envoi($res);
        if ($type === 'erreur') {
            $error = $texte;
        } else {
            $info = "Nouveau code envoyé.";
        }
        if ($res === 'limite') {
            unset($_SESSION['auth_attente']);
        }
    }

    if ($action === 'valider') {
        $_SESSION['auth_attente']['essais']++;
        $saisie = (string) ($_POST['code'] ?? '');
        $resultat = $methode === 'mail'
            ? code_mail_verifier($userId, $saisie)
            : double_auth_controler($userId, $saisie);

        if ($resultat === 'ok' || $resultat === 'secours') {
            $user = get_user($userId); // relu : l'appli a pu mettre à jour le compte
            if (!empty($_POST['confiance'])) {
                appareil_memoriser($user);
            }
            ouvrir_session($user);
            if ($resultat === 'secours') {
                $_SESSION['flash_secours'] = codes_secours_restants($userId);
            }
            header('Location: /pages/accueil.php');
            exit;
        } elseif ($resultat === 'bloque') {
            unset($_SESSION['auth_attente']);
            $error = "Trop de codes erronés. Réessayez dans " . TOTP_BLOCAGE_MIN . " minutes.";
        } elseif ($resultat === 'expire') {
            $error = "Ce code a expiré. Demandez-en un nouveau.";
        } elseif ($resultat === 'aucun') {
            $error = "Aucun code valable n'a été envoyé. Cliquez sur « Renvoyer un code ».";
        } else {
            $restants = ESSAIS_PAR_CONNEXION - $_SESSION['auth_attente']['essais'];
            $error = $methode === 'mail'
                ? "Code incorrect. Vérifiez le dernier e-mail reçu."
                : "Code incorrect. Vérifiez l'heure de votre téléphone et réessayez.";
            if ($restants <= 0) {
                unset($_SESSION['auth_attente']);
                $error = "Code incorrect.";
            }
        }
    }
}

$encoreActif = isset($_SESSION['auth_attente']);
$codeLocal   = $_SESSION['auth_attente']['mail']['code_local'] ?? null;

$authTitre = 'Vérification';
require __DIR__ . '/../inc/layout_auth_header.php';
?>
        <div class="kpi-icon mb-3" style="display:flex;width:44px;height:44px;border-radius:12px;background:var(--ad-gold-soft);color:var(--ad-gold-ink)">
          <?= icon($methode === 'mail' ? 'message' : 'key', 22) ?>
        </div>
        <h1 class="mb-1">Vérification en deux étapes</h1>
        <p class="text-muted mb-4">
          <?php if ($methode === 'mail'): ?>
            Nous avons envoyé un code à 6 chiffres à <strong class="text-body"><?= h(email_masque($user['email'])) ?></strong>.
            Il est valable <?= CODE_MAIL_VALIDITE_MIN ?> minutes. Pensez à regarder dans les courriers indésirables.
          <?php elseif ($modeSecours): ?>
            Saisissez l'un de vos codes de secours (format <code>XXXXX-XXXXX</code>). Chaque code ne sert qu'une fois.
          <?php else: ?>
            Ouvrez votre application d'authentification et saisissez le code à 6 chiffres affiché pour AD'HUB.
          <?php endif; ?>
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php elseif ($info): ?>
          <div class="alert alert-success py-2"><?= h($info) ?></div>
        <?php endif; ?>
        <?php if ($codeLocal && $encoreActif): ?>
          <div class="alert alert-secondary py-2 small">Mode local (aucun e-mail envoyé) : le code est <strong><?= h($codeLocal) ?></strong>.</div>
        <?php endif; ?>

        <?php if ($encoreActif): ?>
          <form method="post" autocomplete="off">
            <input type="hidden" name="action" value="valider">
            <?php if ($modeSecours): ?>
              <input type="hidden" name="secours" value="1">
              <div class="mb-3">
                <label class="form-label">Code de secours</label>
                <input type="text" name="code" class="form-control form-control-lg text-uppercase" style="font-size:1.05rem;letter-spacing:.08em"
                       required autofocus maxlength="11" placeholder="XXXXX-XXXXX" autocapitalize="characters" spellcheck="false">
              </div>
            <?php else: ?>
              <div class="mb-3">
                <label class="form-label">Code à 6 chiffres</label>
                <input type="text" name="code" class="form-control form-control-lg text-center" style="font-size:1.6rem;letter-spacing:.4em;font-weight:600"
                       required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="••••••">
              </div>
            <?php endif; ?>
            <div class="form-check mb-4">
              <input class="form-check-input" type="checkbox" name="confiance" value="1" id="confiance" checked>
              <label class="form-check-label small" for="confiance">
                Ne plus demander de code sur cet appareil pendant <?= APPAREIL_CONFIANCE_JOURS ?> jours
                <span class="text-muted">(décochez sur un ordinateur partagé)</span>
              </label>
            </div>
            <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Valider</button>
          </form>

          <div class="d-flex justify-content-between align-items-center gap-3 mt-4 small flex-wrap">
            <?php if ($methode === 'mail'): ?>
              <form method="post" class="m-0"><button name="action" value="renvoyer" class="btn btn-link btn-sm p-0">Renvoyer un code</button></form>
            <?php elseif ($modeSecours): ?>
              <a href="/pages/login_2fa.php">Utiliser l'application</a>
            <?php else: ?>
              <span class="d-flex gap-3 flex-wrap">
                <form method="post" class="m-0"><button name="action" value="par_mail" class="btn btn-link btn-sm p-0">Recevoir un code par e-mail</button></form>
                <a href="/pages/login_2fa.php?secours=1">Code de secours</a>
              </span>
            <?php endif; ?>
            <form method="post" class="m-0"><button name="action" value="annuler" class="btn btn-link btn-sm p-0 text-muted">Annuler</button></form>
          </div>
        <?php else: ?>
          <a href="/pages/login.php" class="btn btn-outline-dark w-100">Revenir à la connexion</a>
        <?php endif; ?>

        <p class="text-muted small mt-4 mb-0">
          <?= $methode === 'mail'
              ? "Pas d'e-mail après quelques minutes, ou adresse qui n'est plus la bonne ? Demandez à la direction de vérifier l'adresse de votre compte."
              : "Plus d'accès à l'application ni aux codes de secours ? Utilisez « Recevoir un code par e-mail », ou demandez à la direction de réinitialiser votre double authentification." ?>
        </p>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
