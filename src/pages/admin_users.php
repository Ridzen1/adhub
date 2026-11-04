<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/mot_de_passe.php';
require_direction();

$errors = [];

// Envoi du lien « choisir mon mot de passe » à un compte
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite_user') {
    $cible = get_user((int) ($_POST['user_id'] ?? 0));
    if (!csrf_valide() || $cible === null) {
        $errors[] = "Envoi refusé.";
    } elseif (!$cible['is_active']) {
        $errors[] = "Ce compte est désactivé : réactivez-le avant d'envoyer le lien.";
    } elseif (!email_valide_pour_envoi($cible['email'])) {
        $errors[] = "L'adresse de " . $cible['name'] . " n'est pas valide : corrigez-la avec « Modifier » avant d'envoyer le lien.";
    } elseif (!envoyer_invitation($cible)) {
        $errors[] = "L'e-mail pour " . $cible['name'] . " n'a pas pu partir. Réessayez dans un instant.";
    } else {
        $_SESSION['flash_invitations'] = ['envoyes' => [$cible['name'] . ' (' . $cible['email'] . ')'], 'echecs' => [], 'ignores' => []];
        header('Location: /pages/admin_users.php#user-' . (int) $cible['id']);
        exit;
    }
}

// Envoi groupé : comptes sans mot de passe, ou tous les comptes actifs (sauf soi-même)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite_all') {
    $cibleGroupe = ($_POST['cible'] ?? '') === 'tous' ? 'tous' : 'sans_mdp';
    if (!csrf_valide()) {
        $errors[] = "Envoi refusé.";
    } else {
        $bilan = ['envoyes' => [], 'echecs' => [], 'ignores' => []];
        foreach (get_all_users() as $cu) {
            if (!$cu['is_active'] || (int) $cu['id'] === current_user()['id']) {
                continue;
            }
            if ($cibleGroupe === 'sans_mdp' && !user_sans_mot_de_passe($cu)) {
                continue;
            }
            $libelle = $cu['name'] . ' (' . $cu['email'] . ')';
            if (!email_valide_pour_envoi($cu['email'])) {
                $bilan['ignores'][] = $libelle;
            } elseif (envoyer_invitation($cu)) {
                $bilan['envoyes'][] = $libelle;
            } else {
                $bilan['echecs'][] = $libelle;
            }
        }
        $_SESSION['flash_invitations'] = $bilan;
        header('Location: /pages/admin_users.php');
        exit;
    }
}

// Création d'un utilisateur
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_user') {
    $name     = trim($_POST['name'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $roleId   = (int) ($_POST['role_id'] ?? 0);

    if ($name === '' || $email === '') {
        $errors[] = "Nom et email sont obligatoires.";
    }
    if ($password !== '' && strlen($password) < 8) {
        $errors[] = "Le mot de passe doit faire au moins 8 caractères.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Email invalide.";
    }

    if (!$errors) {
        try {
            $nouvelId = create_user($name, $email, $password !== '' ? $password : bin2hex(random_bytes(24)), $roleId);
            if ($password === '') {
                // Pas de mot de passe provisoire : la personne choisira le sien avec le lien e-mail
                retirer_mot_de_passe($nouvelId);
                $nouveau = get_user($nouvelId);
                if (!empty($_POST['inviter']) && email_valide_pour_envoi($email)) {
                    $_SESSION['flash_invitations'] = envoyer_invitation($nouveau)
                        ? ['envoyes' => [$name . ' (' . $email . ')'], 'echecs' => [], 'ignores' => []]
                        : ['envoyes' => [], 'echecs' => [$name . ' (' . $email . ')'], 'ignores' => []];
                }
            }
            header('Location: /pages/admin_users.php?created=1#user-' . $nouvelId);
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
<?php if (isset($_SESSION['flash_invitations'])): $bilan = $_SESSION['flash_invitations']; unset($_SESSION['flash_invitations']); ?>
  <div class="alert <?= $bilan['echecs'] || $bilan['ignores'] ? 'alert-warning' : 'alert-success' ?>">
    <?php if ($bilan['envoyes']): ?>
      <strong><?= count($bilan['envoyes']) ?> e-mail(s) envoyé(s)</strong> pour choisir le mot de passe :
      <?= h(implode(', ', $bilan['envoyes'])) ?>.
    <?php elseif (!$bilan['echecs'] && !$bilan['ignores']): ?>
      Aucun compte concerné : aucun e-mail envoyé.
    <?php endif; ?>
    <?php if ($bilan['echecs']): ?>
      <div class="mt-1"><strong>Envoi impossible</strong> (réessayez) : <?= h(implode(', ', $bilan['echecs'])) ?>.</div>
    <?php endif; ?>
    <?php if ($bilan['ignores']): ?>
      <div class="mt-1"><strong>Pas d'adresse e-mail valide</strong>, rien envoyé (corrigez avec « Modifier ») : <?= h(implode(', ', $bilan['ignores'])) ?>.</div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">Nouvel utilisateur</div>
  <div class="card-body">
    <form method="post" class="row g-3" id="form-nouvel-utilisateur">
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
        <label class="form-label">Mot de passe provisoire <span class="text-muted small">(facultatif)</span></label>
        <input type="text" name="password" class="form-control" minlength="8" autocomplete="off" placeholder="Vide : la personne le choisit">
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
    <div class="form-check mt-2">
      <input class="form-check-input" type="checkbox" name="inviter" value="1" id="inviter" form="form-nouvel-utilisateur" checked>
      <label class="form-check-label small" for="inviter">
        Sans mot de passe provisoire : envoyer tout de suite l'e-mail pour choisir son mot de passe (depuis <?= h(mail_expediteur_comptes()['adresse']) ?>)
      </label>
    </div>
  </div>
</div>

<?php
  $autres       = array_filter($users, fn ($x) => $x['is_active'] && (int) $x['id'] !== current_user()['id']);
  $nbSansMdp    = count(array_filter($autres, 'user_sans_mot_de_passe'));
  $sansAdresse  = array_filter($autres, fn ($x) => !email_valide_pour_envoi($x['email']));
?>
<div class="card mb-4">
  <div class="card-header">Laisser chacun choisir son mot de passe</div>
  <div class="card-body">
    <p class="mb-2">
      Chaque compte reçoit un e-mail de <strong><?= h(mail_expediteur_comptes()['adresse']) ?></strong> avec un lien sécurisé,
      valable 7 jours et utilisable une seule fois, pour choisir lui-même son mot de passe.
      Son mot de passe actuel reste valable tant qu'il n'en a pas choisi un nouveau.
    </p>
    <?php if ($sansAdresse): ?>
      <p class="small text-warning-emphasis mb-2">
        Sans adresse e-mail valide (rien ne leur sera envoyé) : <?= h(implode(', ', array_map(fn ($x) => $x['name'] . ' (' . $x['email'] . ')', $sansAdresse))) ?>.
      </p>
    <?php endif; ?>
    <div class="d-flex gap-2 flex-wrap">
      <form method="post" onsubmit="return confirm('Envoyer le lien aux <?= $nbSansMdp ?> compte(s) qui n\'ont pas encore de mot de passe ?');">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="invite_all">
        <input type="hidden" name="cible" value="sans_mdp">
        <button type="submit" class="btn btn-sm btn-dark" <?= $nbSansMdp ? '' : 'disabled' ?>>Envoyer aux comptes sans mot de passe (<?= $nbSansMdp ?>)</button>
      </form>
      <form method="post" onsubmit="return confirm('Envoyer le lien à TOUS les comptes actifs (<?= count($autres) ?>, vous exclu) ?');">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="invite_all">
        <input type="hidden" name="cible" value="tous">
        <button type="submit" class="btn btn-sm btn-outline-dark">Envoyer à tous les comptes actifs (<?= count($autres) ?>)</button>
      </form>
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
          <span class="badge bg-warning" title="Ce compte ne peut pas se connecter tant que la personne n'a pas choisi son mot de passe (lien e-mail) ou qu'un mot de passe provisoire n'est pas défini">Mot de passe à définir</span>
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
      <?php if ($u['is_active'] && email_valide_pour_envoi($u['email'])): ?>
        <form method="post" onsubmit="return confirm('Envoyer à <?= h(addslashes($u['email'])) ?> le lien pour choisir son mot de passe ?');">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="invite_user">
          <input type="hidden" name="user_id" value="<?= (int) $u['id'] ?>">
          <button type="submit" class="btn btn-sm btn-outline-dark" title="E-mail avec un lien pour choisir son mot de passe">Envoyer le lien mot de passe</button>
        </form>
      <?php endif; ?>
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
