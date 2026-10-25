<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_login();

$user     = current_user();
$errors   = [];
$success  = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    $full = get_user($user['id']);

    if (!$full || !password_verify($current, $full['password_hash'])) {
        $errors[] = "Mot de passe actuel incorrect.";
    }
    if (strlen($new) < 8) {
        $errors[] = "Le nouveau mot de passe doit faire au moins 8 caractères.";
    }
    if ($new !== $confirm) {
        $errors[] = "La confirmation ne correspond pas au nouveau mot de passe.";
    }

    if (!$errors) {
        update_password($user['id'], $new);
        $success = true;
    }
}

$full = get_user($user['id']);

$pageTitle = 'Mon compte';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Mon compte', h(current_user()['name'] ?? '')) ?>

<?php if ($success): ?>
  <div class="alert alert-success">Mot de passe mis à jour.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="row">
  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header">Informations</div>
      <div class="card-body">
        <table class="table table-sm mb-0">
          <tr><td class="text-muted">Nom</td><td class="text-end"><?= htmlspecialchars($full['name']) ?></td></tr>
          <tr><td class="text-muted">Email</td><td class="text-end"><?= htmlspecialchars($full['email']) ?></td></tr>
          <tr><td class="text-muted">Rôle</td><td class="text-end"><?= htmlspecialchars($full['role_label']) ?></td></tr>
        </table>
      </div>
    </div>
  </div>

  <div class="col-md-7">
    <div class="card mb-4">
      <div class="card-header">
        Double authentification
        <?php if ($full['totp_enabled']): ?>
          <span class="badge bg-success">Activée</span>
        <?php else: ?>
          <span class="badge bg-warning">Non activée</span>
        <?php endif; ?>
      </div>
      <div class="card-body d-flex justify-content-between align-items-center gap-3 flex-wrap">
        <div class="text-muted">
          <?= $full['totp_enabled']
              ? 'Un code de votre application est demandé à chaque connexion. ' . codes_secours_restants($user['id']) . ' code(s) de secours restant(s).'
              : 'Protégez votre compte avec un code à 6 chiffres sur votre téléphone.' ?>
        </div>
        <a href="/pages/double_authentification.php" class="btn btn-sm <?= $full['totp_enabled'] ? 'btn-outline-dark' : 'btn-dark' ?>">
          <?= $full['totp_enabled'] ? 'Gérer' : 'Activer' ?>
        </a>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Changer mon mot de passe</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="change_password">
          <div class="col-12">
            <label class="form-label">Mot de passe actuel</label>
            <input type="password" name="current_password" class="form-control" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="new_password" class="form-control" minlength="8" required>
          </div>
          <div class="col-md-6">
            <label class="form-label">Confirmer</label>
            <input type="password" name="confirm_password" class="form-control" minlength="8" required>
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-dark">Mettre à jour</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
