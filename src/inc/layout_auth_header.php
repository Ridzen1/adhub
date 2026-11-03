<?php
/**
 * Gabarit des pages hors connexion (connexion, code de double authentification).
 * Usage : $authTitre = '…'; require layout_auth_header.php; …contenu…; require layout_auth_footer.php
 */
require_once __DIR__ . '/ui.php';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($authTitre ?? 'Connexion') ?> · AD'HUB</title>
  <link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/adhub.css?v=<?= @filemtime(__DIR__ . '/../assets/adhub.css') ?: 1 ?>" rel="stylesheet">
  <link rel="icon" href="/favicon.ico" sizes="any">
  <link rel="icon" type="image/png" href="/assets/img/favicon-32.png">
  <link rel="apple-touch-icon" href="/assets/img/apple-touch-icon.png">
</head>
<body>
  <div class="login-wrap">
    <aside class="login-aside">
      <div class="login-logo">
        <img src="/assets/img/adhub-logo-clair.png" alt="AD'Hub · Invest, Connect, Grow" width="260" height="173">
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
        <div class="login-brand-mobile">
          <img src="/assets/img/adhub-logo.png" alt="AD'Hub · Invest, Connect, Grow" width="200" height="133">
        </div>
