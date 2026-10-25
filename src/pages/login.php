<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/ui.php';

$error = null;

if (is_logged_in()) {
    header('Location: /pages/accueil.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    $user = verifier_identifiants($email, $password);
    if ($user !== null && !empty($user['totp_enabled'])) {
        // Mot de passe correct, il reste le code de l'application : la session
        // n'est pas encore ouverte, on retient seulement qui est en train de se connecter.
        start_session_once();
        session_regenerate_id(true);
        $_SESSION['auth_attente'] = ['user_id' => (int) $user['id'], 'depuis' => time(), 'essais' => 0];
        header('Location: /pages/login_2fa.php');
        exit;
    }
    if ($user !== null) {
        ouvrir_session($user);
        header('Location: /pages/accueil.php');
        exit;
    }
    // Message volontairement vague : ne pas révéler si l'email existe.
    $error = "Identifiants incorrects.";
}

$info = isset($_GET['expire']) ? "La vérification a expiré. Reconnectez-vous." : null;

$authTitre = 'Connexion';
require __DIR__ . '/../inc/layout_auth_header.php';
?>
        <h1 class="mb-1">Connexion</h1>
        <p class="text-muted mb-4">Entrez vos identifiants pour accéder au tableau de bord.</p>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= h($error) ?></div>
        <?php elseif ($info): ?>
          <div class="alert alert-warning py-2"><?= h($info) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Adresse e-mail</label>
            <input type="email" name="email" class="form-control form-control-lg" style="font-size:.95rem" required autofocus autocomplete="username">
          </div>
          <div class="mb-4">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control form-control-lg" style="font-size:.95rem" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Se connecter</button>
        </form>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
