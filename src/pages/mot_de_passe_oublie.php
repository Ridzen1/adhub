<?php
/**
 * « Mot de passe oublié ? » : envoie un lien (valable 1 heure) pour en choisir un nouveau.
 * Le message affiché est le même que le compte existe ou non.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/mot_de_passe.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/ui.php';

start_session_once();
if (is_logged_in()) {
    header('Location: /pages/mon_compte.php');
    exit;
}

$envoye = false;
$error  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    if (!csrf_valide()) {
        $error = "La page a expiré. Réessayez.";
    } elseif (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
        $error = "Adresse e-mail invalide.";
    } elseif (demander_lien_oubli($email) === 'limite') {
        $error = "Trop de demandes. Réessayez dans une heure, ou demandez à la direction.";
    } else {
        $envoye = true;
    }
}

$authTitre = 'Mot de passe oublié';
require __DIR__ . '/../inc/layout_auth_header.php';
?>
        <h1 class="mb-1">Mot de passe oublié</h1>
        <?php if ($envoye): ?>
          <div class="alert alert-success mt-3">
            Si un compte AD'HUB existe pour <strong><?= h($_POST['email'] ?? '') ?></strong>, un e-mail vient de lui être envoyé
            avec un lien pour choisir un nouveau mot de passe. Le lien est valable 1 heure.
          </div>
          <p class="text-muted small">Pensez à regarder dans les courriers indésirables. Rien reçu après quelques minutes ? Demandez à la direction de vérifier l'adresse de votre compte.</p>
          <a href="/pages/login.php" class="btn btn-outline-dark w-100">Revenir à la connexion</a>
        <?php else: ?>
          <p class="text-muted mb-4">Indiquez l'adresse e-mail de votre compte : vous recevrez un lien pour choisir un nouveau mot de passe.</p>
          <?php if ($error): ?><div class="alert alert-danger py-2"><?= h($error) ?></div><?php endif; ?>
          <form method="post">
            <?= csrf_champ() ?>
            <div class="mb-4">
              <label class="form-label">Adresse e-mail</label>
              <input type="email" name="email" class="form-control form-control-lg" style="font-size:.95rem" required autofocus autocomplete="username"
                     value="<?= h($_POST['email'] ?? $_GET['email'] ?? '') ?>">
            </div>
            <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Recevoir le lien</button>
          </form>
          <p class="small mt-4 mb-0"><a href="/pages/login.php">Revenir à la connexion</a></p>
        <?php endif; ?>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
