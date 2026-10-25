<?php
/**
 * Deuxième étape de connexion : code de l'application d'authentification
 * (ou un code de secours). Le mot de passe a déjà été vérifié par login.php.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/ui.php';

const ATTENTE_MAX_SECONDES = 300; // 5 min pour saisir le code
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

$userId = (int) $attente['user_id'];
$error  = null;
$modeSecours = isset($_GET['secours']) || isset($_POST['secours']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['annuler'])) {
        unset($_SESSION['auth_attente']);
        header('Location: /pages/login.php');
        exit;
    }

    $_SESSION['auth_attente']['essais']++;
    $resultat = double_auth_controler($userId, (string) ($_POST['code'] ?? ''));

    if ($resultat === 'ok' || $resultat === 'secours') {
        $user = get_user($userId);
        if ($user && $user['is_active']) {
            ouvrir_session($user);
            if ($resultat === 'secours') {
                $_SESSION['flash_secours'] = codes_secours_restants($userId);
            }
            header('Location: /pages/accueil.php');
            exit;
        }
        $error = "Ce compte n'est plus actif.";
    } elseif ($resultat === 'bloque') {
        unset($_SESSION['auth_attente']);
        $error = "Trop de codes erronés. Réessayez dans " . TOTP_BLOCAGE_MIN . " minutes.";
    } else {
        $restants = ESSAIS_PAR_CONNEXION - $_SESSION['auth_attente']['essais'];
        $error = $restants > 0
            ? "Code incorrect. Vérifiez l'heure de votre téléphone et réessayez."
            : "Code incorrect.";
        if ($restants <= 0) {
            unset($_SESSION['auth_attente']);
        }
    }
}

$encoreActif = isset($_SESSION['auth_attente']);

$authTitre = 'Vérification';
require __DIR__ . '/../inc/layout_auth_header.php';
?>
        <div class="kpi-icon mb-3" style="display:flex;width:44px;height:44px;border-radius:12px;background:var(--ad-gold-soft);color:#7A5F12">
          <?= icon('key', 22) ?>
        </div>
        <h1 class="mb-1">Vérification en deux étapes</h1>
        <p class="text-muted mb-4">
          <?php if ($modeSecours): ?>
            Saisissez l'un de vos codes de secours (format <code>XXXXX-XXXXX</code>). Chaque code ne sert qu'une fois.
          <?php else: ?>
            Ouvrez votre application d'authentification et saisissez le code à 6 chiffres affiché pour AD'HUB.
          <?php endif; ?>
        </p>

        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php endif; ?>

        <?php if ($encoreActif): ?>
          <form method="post" autocomplete="off">
            <?php if ($modeSecours): ?>
              <input type="hidden" name="secours" value="1">
              <div class="mb-4">
                <label class="form-label">Code de secours</label>
                <input type="text" name="code" class="form-control form-control-lg text-uppercase" style="font-size:1.05rem;letter-spacing:.08em"
                       required autofocus maxlength="11" placeholder="XXXXX-XXXXX" autocapitalize="characters" spellcheck="false">
              </div>
            <?php else: ?>
              <div class="mb-4">
                <label class="form-label">Code à 6 chiffres</label>
                <input type="text" name="code" class="form-control form-control-lg text-center" style="font-size:1.6rem;letter-spacing:.4em;font-weight:600"
                       required autofocus inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="••••••">
              </div>
            <?php endif; ?>
            <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Valider</button>
          </form>

          <div class="d-flex justify-content-between align-items-center mt-4 small">
            <?php if ($modeSecours): ?>
              <a href="/pages/login_2fa.php">Utiliser l'application</a>
            <?php else: ?>
              <a href="/pages/login_2fa.php?secours=1">Téléphone indisponible ? Code de secours</a>
            <?php endif; ?>
            <form method="post" class="m-0"><button name="annuler" value="1" class="btn btn-link btn-sm p-0 text-muted">Annuler</button></form>
          </div>
        <?php else: ?>
          <a href="/pages/login.php" class="btn btn-outline-dark w-100">Revenir à la connexion</a>
        <?php endif; ?>

        <p class="text-muted small mt-4 mb-0">
          Plus d'accès à l'application ni aux codes de secours ? Un membre de la direction peut
          réinitialiser la double authentification de votre compte.
        </p>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
