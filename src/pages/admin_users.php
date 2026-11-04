<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_direction();

$errors = [];

// Création d'un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId   = (int) ($_POST['role_id'] ?? 0);

    if ($name === '' || $email === '') {
        $errors[] = "Nom et email sont obligatoires.";
    }
    if (strlen($password) < 8) {
        $errors[] = "Le mot de passe doit faire au moins 8 caractères.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email invalide.";
    }

    if (!$errors) {
        try {
            create_user($name, $email, $password, $roleId);
            header('Location: /pages/admin_users.php?created=1');
            exit;
        } catch (PDOException $e) {
            $errors[] = "Cet email est déjà utilisé.";
        }
    }
}

// Modification du nom, de l'e-mail et, si renseigné, du mot de passe provisoire
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit_user') {
    $cible    = (int) ($_POST['user_id'] ?? 0);
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (!csrf_valide() || get_user($cible) === null) {
        $errors[] = "Modification refusée.";
    } else {
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Nom et e-mail valide obligatoires.";
        }
        if ($password !== '' && strlen($password) < 8) {
            $errors[] = "Le mot de passe doit faire au moins 8 caractères.";
        }
    }

    if (!$errors) {
        try {
            update_user_identity($cible, $name, $email);
            if ($password !== '') {
                update_password($cible, $password);
            }
            header('Location: /pages/admin_users.php?updated=1#user-' . $cible);
            exit;
        } catch (PDOException $e) {
            $errors[] = "Cet e-mail est déjà utilisé.";
        }
    }
}

// Activation / désactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_active') {
    set_user_active((int) $_POST['user_id'], (int) $_POST['active'] === 1);
    header('Location: /pages/admin_users.php?updated=1');
    exit;
}

// Attribution d'un accès
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'grant') {
    grant_access(
        (int) $_POST['user_id'],
        (int) $_POST['project_id'],
        $_POST['access_level'] ?? 'read',
        $_POST['scope'] ?? 'all'
    );
    header('Location: /pages/admin_users.php?granted=1#user-' . (int) $_POST['user_id']);
    exit;
}

// Retrait d'un accès
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'revoke') {
    revoke_access((int) $_POST['user_id'], (int) $_POST['project_id']);
    header('Location: /pages/admin_users.php?revoked=1#user-' . (int) $_POST['user_id']);
    exit;
}

// Réinitialisation de la double authentification (téléphone perdu, plus de codes de secours).
// Pas sur son propre compte : chacun gère le sien depuis « Double authentification ».
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'reset_2fa') {
    $cible = (int) ($_POST['user_id'] ?? 0);
    if (csrf_valide() && $cible !== current_user()['id']) {
        double_auth_desactiver($cible);
        header('Location: /pages/admin_users.php?reset2fa=1#user-' . $cible);
        exit;
    }
    $errors[] = "Réinitialisation refusée.";
}

$users       = get_all_users();
$roles       = get_all_roles();
$allProjects = get_all_projects();

$pageTitle = 'Utilisateurs';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Utilisateurs et accès', 'Comptes, rôles et droits par société.', '', 'Administration') ?>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Utilisateur créé.</div><?php endif; ?>
<?php if (isset($_GET['granted'])): ?><div class="alert alert-success">Accès attribué.</div><?php endif; ?>
<?php if (isset($_GET['reset2fa'])): ?><div class="alert alert-success">Application d'authentification retirée : l'utilisateur recevra désormais son code de connexion par e-mail.</div><?php endif; ?>
<?php if (isset($_GET['revoked'])): ?><div class="alert alert-secondary">Accès retiré.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-secondary">Utilisateur mis à jour.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="card mb-4">
  <div class="card-header">Nouvel utilisateur</div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="create_user">
      <div class="col-md-3">
        <label class="form-label">Nom</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Mot de passe provisoire</label>
        <input type="text" name="password" class="form-control" minlength="8" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Rôle</label>
        <select name="role_id" class="form-select" required>
          <?php foreach ($roles as $r): ?>
            <option value="<?= (int) $r['id'] ?>" <?= $r['code'] === 'associe' ? 'selected' : '' ?>>
              <?= htmlspecialchars($r['label']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-dark w-100">Créer</button>
      </div>
    </form>
    <div class="form-text mt-2">
      Le rôle <strong>Direction</strong> donne accès à toutes les sociétés automatiquement — inutile de lui attribuer des accès un par un.
    </div>
  </div>
</div>

<?php foreach ($users as $u):
  $access = get_user_access((int) $u['id']);
  $accessIds = array_column($access, 'project_id');
?>
  <div class="card mb-3" id="user-<?= (int) $u['id'] ?>">
    <div class="card-header d-flex justify-content-between align-items-center">
      <div>
        <strong><?= htmlspecialchars($u['name']) ?></strong>
        <span class="text-muted small">· <?= htmlspecialchars($u['email']) ?></span>
        <span class="badge badge-type text-white ms-2"><?= htmlspecialchars($u['role_label']) ?></span>
        <?php if (!$u['is_active']): ?>
          <span class="badge badge-archived text-white">Désactivé</span>
        <?php endif; ?>
        <?php if (user_sans_mot_de_passe($u)): ?>
          <span class="badge bg-warning" title="Ce compte ne peut pas se connecter tant qu'un mot de passe provisoire n'est pas défini">Mot de passe à définir</span>
        <?php endif; ?>
        <?php if ($u['totp_enabled']): ?>
          <span class="badge bg-success" title="Code de l'application d'authentification à chaque connexion (ou par e-mail en secours)">2FA appli</span>
        <?php elseif (email_valide_pour_envoi($u['email'])): ?>
          <span class="badge bg-success" title="Code envoyé par e-mail à chaque connexion">2FA e-mail</span>
        <?php else: ?>
          <span class="badge bg-warning" title="Le code de connexion ne peut pas être envoyé : ce compte ne peut pas se connecter tant que l'adresse n'est pas corrigée (bouton Modifier)">E-mail à corriger</span>
        <?php endif; ?>
      </div>
      <div class="d-flex gap-2">
      <button type="button" class="btn btn-sm btn-outline-dark" data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $u['id'] ?>">Modifier</button>
      <?php if ($u['totp_enabled'] && (int) $u['id'] !== current_user()['id']): ?>
        <form method="post" onsubmit="return confirm('Retirer l\'application d\'authentification de cet utilisateur ? Il recevra ses codes par e-mail.');">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="reset_2fa">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-danger">Retirer l'appli 2FA</button>
        </form>
      <?php endif; ?>
      <form method="post">
        <input type="hidden" name="action" value="toggle_active">
        <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
        <input type="hidden" name="active" value="<?= $u['is_active'] ? 0 : 1 ?>">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
          <?= $u['is_active'] ? 'Désactiver' : 'Réactiver' ?>
        </button>
      </form>
      </div>
    </div>
    <div class="collapse border-bottom" id="edit-<?= (int) $u['id'] ?>">
      <div class="card-body bg-light">
        <form method="post" class="row g-2 align-items-end">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="edit_user">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <div class="col-md-3">
            <label class="form-label small">Nom</label>
            <input type="text" name="name" class="form-control form-control-sm" value="<?= htmlspecialchars($u['name']) ?>" required>
          </div>
          <div class="col-md-4">
            <label class="form-label small">E-mail de connexion</label>
            <input type="email" name="email" class="form-control form-control-sm" value="<?= htmlspecialchars($u['email']) ?>" required>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Nouveau mot de passe provisoire</label>
            <input type="text" name="password" class="form-control form-control-sm" minlength="8" autocomplete="off" placeholder="Laisser vide pour ne pas changer">
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-dark w-100">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>
    <div class="card-body">
      <?php if ($u['role_code'] === 'direction'): ?>
        <p class="text-muted mb-0">Accès à toutes les sociétés (rôle Direction).</p>
      <?php else: ?>

        <?php if (!$access): ?>
          <p class="text-muted">Aucun accès attribué — cet utilisateur ne voit rien pour l'instant.</p>
        <?php else: ?>
          <table class="table table-sm">
            <thead>
              <tr><th>Société</th><th>Droit</th><th>Portée</th><th></th></tr>
            </thead>
            <tbody>
              <?php foreach ($access as $a): ?>
                <tr>
                  <td><?= htmlspecialchars($a['project_name']) ?>
                      <span class="text-muted small">(<?= htmlspecialchars($a['type_label']) ?>)</span></td>
                  <td><?= $a['access_level'] === 'write' ? 'Lecture + modification' : 'Lecture seule' ?></td>
                  <td><?= $a['scope'] === 'all' ? 'Tout' : ucfirst($a['scope']) ?></td>
                  <td class="text-end">
                    <form method="post" onsubmit="return confirm('Retirer cet accès ?');">
                      <input type="hidden" name="action" value="revoke">
                      <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
                      <input type="hidden" name="project_id" value="<?= (int) $a['project_id'] ?>">
                      <button type="submit" class="btn btn-sm btn-outline-danger">Retirer</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <form method="post" class="row g-2 align-items-end">
          <input type="hidden" name="action" value="grant">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <div class="col-md-4">
            <label class="form-label small">Donner accès à</label>
            <select name="project_id" class="form-select form-select-sm" required>
              <option value="">— Choisir une société —</option>
              <?php foreach ($allProjects as $p): ?>
                <?php if (in_array($p['id'], $accessIds)) continue; ?>
                <option value="<?= (int) $p['id'] ?>">
                  <?= htmlspecialchars($p['name']) ?> (<?= htmlspecialchars($p['type_label']) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Droit</label>
            <select name="access_level" class="form-select form-select-sm">
              <option value="read">Lecture seule</option>
              <option value="write">Lecture + modification</option>
            </select>
          </div>
          <div class="col-md-3">
            <label class="form-label small">Portée</label>
            <select name="scope" class="form-select form-select-sm">
              <option value="all">Tout</option>
              <option value="dashboard">Dashboard seulement</option>
              <option value="documents">Documents seulement</option>
            </select>
          </div>
          <div class="col-md-2">
            <button type="submit" class="btn btn-sm btn-dark w-100">Ajouter</button>
          </div>
        </form>

      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
