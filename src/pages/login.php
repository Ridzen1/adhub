<?php
require_once __DIR__ . '/../inc/access.php';

$error = null;

if (is_logged_in()) {
    header('Location: /pages/accueil.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (attempt_login($email, $password)) {
        header('Location: /pages/accueil.php');
        exit;
    }
    // Message volontairement vague : ne pas révéler si l'email existe.
    $error = "Identifiants incorrects.";
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Connexion</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #0E1C2B; height: 100vh; display: flex; align-items: center; }
    .login-card { max-width: 400px; width: 100%; margin: 0 auto; }
    .brand { color: #C8A84B; font-weight: bold; letter-spacing: 1px; font-size: 22px; text-align: center; margin-bottom: 6px; }
    .subtitle { color: #8A93A6; font-size: 13px; text-align: center; margin-bottom: 24px; }
  </style>
</head>
<body>
  <div class="login-card">
    <div class="brand">AD'HUB</div>
    <div class="subtitle">Tableau de bord multi-sociétés</div>
    <div class="card">
      <div class="card-body p-4">
        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <div class="mb-3">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label">Mot de passe</label>
            <input type="password" name="password" class="form-control" required>
          </div>
          <button type="submit" class="btn btn-dark w-100">Se connecter</button>
        </form>
      </div>
    </div>
  </div>
</body>
</html>
