<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
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

    if ($user !== null && (!double_auth_requise($user) || appareil_de_confiance($user))) {
        // Appareil de confiance (code déjà saisi ici il y a moins de 30 jours),
        // ou interrupteur de secours de config.local.php
        ouvrir_session($user);
        header('Location: /pages/accueil.php');
        exit;
    }

    if ($user !== null) {
        $methode = !empty($user['totp_enabled']) ? 'appli' : 'mail';
        if ($methode === 'mail' && !email_valide_pour_envoi($user['email'])) {
            $error = "Votre compte n'a pas d'adresse e-mail valide pour recevoir le code de connexion. "
                   . "Demandez à la direction de la corriger.";
        } else {
            // Mot de passe correct, il reste le second facteur : la session n'est pas
            // encore ouverte, on retient seulement qui est en train de se connecter.
            start_session_once();
            session_regenerate_id(true);
            $_SESSION['auth_attente'] = [
                'user_id' => (int) $user['id'], 'depuis' => time(), 'essais' => 0, 'methode' => $methode,
            ];
            if ($methode === 'mail') {
                $_SESSION['auth_attente']['envoi'] = code_mail_envoyer($user);
            }
            header('Location: /pages/login_2fa.php');
            exit;
        }
    } else {
        // Message volontairement vague : ne pas révéler si l'email existe.
        $error = "Identifiants incorrects.";
    }
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
            <input type="email" name="email" class="form-control form-control-lg" style="font-size:.95rem" required autofocus autocomplete="username"
                   value="<?= h($_POST['email'] ?? '') ?>">
          </div>
          <div class="mb-4">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control form-control-lg" style="font-size:.95rem" required autocomplete="current-password">
          </div>
          <button type="submit" class="btn btn-dark btn-lg w-100" style="font-size:.95rem">Se connecter</button>
        </form>
        <p class="text-muted small mt-4 mb-0">Un code de vérification vous sera envoyé par e-mail.</p>
<?php require __DIR__ . '/../inc/layout_auth_footer.php'; ?>
