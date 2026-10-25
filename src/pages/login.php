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
  <title>Connexion · AD'HUB</title>
  <link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/adhub.css?v=<?= @filemtime(__DIR__ . '/../assets/adhub.css') ?: 1 ?>" rel="stylesheet">
</head>
<body>
  <div class="login-wrap">
    <aside class="login-aside">
      <div class="ad-brand">
        <span class="ad-brand-mark">AD</span>
        <span class="ad-brand-name">AD<span>'</span>HUB</span>
      </div>
      <div style="position:relative;z-index:1">
        <h2>Le pilotage de toutes vos sociétés, au même endroit.</h2>
        <p>Trésorerie, chiffre d'affaires, résultats et documents de la holding, à jour chaque matin.</p>
        <ul class="login-points">
          <li><?= icon('check', 18) ?> Ventes des restaurants synchronisées depuis les caisses</li>
          <li><?= icon('check', 18) ?> Comparaisons N-1 et alertes d'échéances</li>
          <li><?= icon('check', 18) ?> Accès réservé, droits par société</li>
        </ul>
      </div>
      <div style="color:rgba(255,255,255,.4);font-size:.75rem">AD Holding</div>
    </aside>

    <div class="login-panel">
      <div class="login-box">
        <div class="ad-brand login-brand-mobile">
          <span class="ad-brand-mark">AD</span>
          <span class="ad-brand-name">AD<span>'</span>HUB</span>
        </div>
        <h1 class="mb-1">Connexion</h1>
        <p class="text-muted mb-4">Entrez vos identifiants pour accéder au tableau de bord.</p>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= h($error) ?></div>
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
      </div>
    </div>
  </div>
</body>
</html>
