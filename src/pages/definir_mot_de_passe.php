<?php
/**
 * Choix du mot de passe depuis le lien reçu par e-mail (invitation ou mot de passe oublié).
 * Le lien ne sert qu'une fois : une fois le mot de passe enregistré, il ne marche plus.
 * Ouvrir le lien prouve l'accès à la boîte mail, qui est aussi le second facteur : la
 * session est donc ouverte directement (sauf compte protégé par une application TOTP,
 * qui repasse par la connexion normale).
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/mot_de_passe.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/ui.php';

start_session_once();
header('Referrer-Policy: no-referrer'); // le jeton ne doit pas fuiter vers un autre site

$jeton    = (string) ($_GET['t'] ?? $_POST['t'] ?? '');
$controle = lien_mdp_verifier($jeton);
$errors   = [];

if (is_array($controle) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $user    = $controle['user'];
    $nouveau = (string) ($_POST['new_password'] ?? '');
    if (!csrf_valide()) {
        $errors[] = "La page a expiré. Réessayez.";
    } else {
        $errors = mdp_erreurs($nouveau, (string) ($_POST['confirm_password'] ?? ''), $user);
    }
    if (!$errors) {
        update_password((int) $user['id'], $nouveau);
        $user = get_user((int) $user['id']);
        if (!empty($user['totp_enabled'])) {
            unset($_SESSION['auth_attente']);
            header('Location: /pages/login.php?mdp=1');
            exit;
        }
        ouvrir_session($user);
        $_SESSION['flash_mdp'] = true;
        header('Location: /pages/accueil.php');
        exit;
    }
}

$authTitre = 'Choisir mon mot de passe';
require __DIR__ . '/../inc/layout_auth_header.php';
?>
<?php if (!is_array($controle)): ?>
        <h1 class="mb-1">Lien plus valable</h1>
        <div class="alert alert-warning mt-3">
          <?= $controle === 'expire'
              ? "Ce lien a expiré."
              : "Ce lien n'est pas valable : il a peut-être déjà servi, ou il a été copié incomplètement." ?>
        </div>
        <p class="text-muted">Demandez un nouveau lien avec « Mot de passe oublié ».</p>
        <a href="/pages/mot_de_passe_oublie.php" class="btn btn-dark w-100 mb-2">Recevoir un nouveau lien</a>
        <a href="/pages/login.php" class="btn btn-outline-dark w-100">Revenir à la connexion</a>
<?php else: $u = $controle['user']; ?>
        <h1 class="mb-1"><?= $controle['type'] === 'i' ? 'Bienvenue sur AD\'HUB' : 'Nouveau mot de passe' ?></h1>
        <p class="text-muted mb-4">
          Choisissez le mot de passe du compte <strong class="text-body"><?= h($u['email']) ?></strong>
          (au moins <?= MDP_LONGUEUR_MIN ?> caractères).
        </p>
        <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2"><?= h($e) ?></div><?php endforeach; ?>
        <form method="post" autocomplete="off">
          <?= csrf_champ() ?>
          <input type="hidden" name="t" value="<?= h($jeton) ?>">
          <input type="email" name="email" value="<?= h($u['email']) ?>" autocomplete="username" hidden readonly>
          <div class="mb-3">
            <label class="form-label">Nouveau mot de passe</label>
            <input type="password" name="new_password" class="form-control form-control-lg" style="font-size:.95rem"
                   required autofocus minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
          </div>
          <div class="mb-4">
            <label class="form-label">Confirmer le mot de passe</label>
            <input type="password" name="confirm_password" class="form-control form-control-lg" style="font-size:.95rem"
                   required minlength="<?= MDP_LONGUEUR_MIN ?>" autocomplete="new-password">
          </div>
          <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Enregistrer et me connecter</button>
        </form>
        <p class="text-muted small mt-4 mb-0">Ce lien ne sert qu'une fois. Vous vous connecterez ensuite avec votre e-mail et ce mot de passe.</p>
<?php endif; ?>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
