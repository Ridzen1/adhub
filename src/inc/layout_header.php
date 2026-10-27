<?php
/**
 * En-tête commun. Usage :
 *   $pageTitle = "Mon titre";
 *   require __DIR__ . '/../inc/layout_header.php';
 *
 * Suppose que require_login() a déjà été appelé par la page.
 * Navigation en haut : vue d'ensemble, sélecteur de société (avec recherche),
 * administration (direction) et menu du compte.
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/ui.php';

$navUser     = current_user();
$navProjects = get_visible_projects();

// Regroupement par type pour le sélecteur de société
$navGroups = [];
foreach ($navProjects as $np) {
    $navGroups[$np['type_label']][] = $np;
}

$navPage = basename($_SERVER['SCRIPT_NAME'] ?? '', '.php');
$currentProjectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$pagesSociete = ['project_dashboard', 'project_detail', 'project_documents', 'charges', 'saisie_quotidienne',
                 'evolution', 'analyse_caisse', 'import_caisse', 'comptabilite'];
$pagesAdmin   = ['projects', 'admin_users', 'admin_logs', 'admin_caisses', 'admin_pennylane'];
$enSociete    = in_array($navPage, $pagesSociete, true) && $currentProjectId > 0;

$nomSocieteCourante = null;
foreach ($navProjects as $np) {
    if ((int) $np['id'] === $currentProjectId) {
        $nomSocieteCourante = $np['name'];
    }
}

// Version des fichiers statiques : force le rechargement après une mise à jour
$assetV = @filemtime(__DIR__ . '/../assets/adhub.css') ?: 1;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($pageTitle ?? "AD'HUB") ?> · AD'HUB</title>
  <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'><rect width='32' height='32' rx='8' fill='%23C8A84B'/><text x='16' y='21.5' font-family='Arial' font-weight='700' font-size='13' text-anchor='middle' fill='%230E1C2B'>AD</text></svg>">
  <link href="/assets/vendor/bootstrap.min.css" rel="stylesheet">
  <link href="/assets/adhub.css?v=<?= $assetV ?>" rel="stylesheet">
  <script src="/assets/vendor/chart.umd.min.js"></script>
  <script src="/assets/adhub.js?v=<?= $assetV ?>"></script>
</head>
<body>

<header class="hub-topbar" id="hubTopbar">
  <div class="hub-topbar-inner">
    <a class="hub-brand" href="/pages/accueil.php">
      <span class="hub-brand-mark">AD</span>
      <span class="hub-brand-name">AD<span>'</span>HUB</span>
    </a>

    <button class="hub-menu-toggle" type="button" aria-label="Menu" onclick="document.getElementById('hubTopbar').classList.toggle('open')">
      <?= icon('menu', 22) ?>
    </button>

    <nav class="hub-nav">
      <?php if (is_direction()): ?>
        <a href="/pages/vue_globale.php" class="<?= $navPage === 'vue_globale' ? 'active' : '' ?>">
          <?= icon('grid', 16) ?> Vue d'ensemble
        </a>
      <?php else: ?>
        <a href="/pages/accueil.php" class="<?= $navPage === 'accueil' ? 'active' : '' ?>">
          <?= icon('grid', 16) ?> Mes sociétés
        </a>
      <?php endif; ?>

      <div class="dropdown">
        <button type="button" class="<?= $enSociete ? 'active' : '' ?>" data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false">
          <?= icon('building', 16) ?>
          <?= $enSociete && $nomSocieteCourante ? h($nomSocieteCourante) : 'Sociétés' ?>
          <span class="hub-caret"><?= icon('chevron', 14) ?></span>
        </button>
        <div class="dropdown-menu hub-companies-menu">
          <div class="hub-companies-search">
            <input type="search" class="form-control" placeholder="Rechercher une société…" data-company-search autocomplete="off">
          </div>
          <div class="hub-companies-grid">
            <?php foreach ($navGroups as $groupLabel => $groupProjects): ?>
              <div class="hub-companies-group" data-company-group>
                <div class="dropdown-header">
                  <span class="type-chip <?= type_famille($groupProjects[0]['type_code'] ?? null) ?>" style="padding:0;background:none"><span class="type-dot"></span></span>
                  <?= h($groupLabel) ?>
                </div>
                <?php foreach ($groupProjects as $gp): ?>
                  <a class="dropdown-item <?= $currentProjectId === (int) $gp['id'] && $enSociete ? 'current' : '' ?>"
                     data-company-name="<?= h(mb_strtolower($gp['name'])) ?>"
                     href="<?= lien_societe((int) $gp['id']) ?>">
                    <span><?= h($gp['name']) ?></span>
                  </a>
                <?php endforeach; ?>
              </div>
            <?php endforeach; ?>
            <?php if (!$navGroups): ?>
              <div class="text-muted p-3">Aucune société accessible</div>
            <?php endif; ?>
          </div>
          <div class="hub-companies-empty" data-company-empty>Aucune société ne correspond.</div>
        </div>
      </div>

      <?php if (is_direction()): ?>
        <div class="dropdown">
          <button type="button" class="<?= in_array($navPage, $pagesAdmin, true) ? 'active' : '' ?>" data-bs-toggle="dropdown" aria-expanded="false">
            <?= icon('settings', 16) ?> Administration
            <span class="hub-caret"><?= icon('chevron', 14) ?></span>
          </button>
          <div class="dropdown-menu">
            <a class="dropdown-item <?= $navPage === 'projects' ? 'active' : '' ?>" href="/pages/projects.php">Gérer les sociétés</a>
            <a class="dropdown-item <?= $navPage === 'admin_users' ? 'active' : '' ?>" href="/pages/admin_users.php">Utilisateurs et accès</a>
            <a class="dropdown-item <?= $navPage === 'admin_caisses' ? 'active' : '' ?>" href="/pages/admin_caisses.php">Caisses</a>
            <a class="dropdown-item <?= $navPage === 'admin_pennylane' ? 'active' : '' ?>" href="/pages/admin_pennylane.php">Import Pennylane</a>
            <div class="dropdown-divider"></div>
            <a class="dropdown-item <?= $navPage === 'admin_logs' ? 'active' : '' ?>" href="/pages/admin_logs.php">Journal des accès</a>
          </div>
        </div>
      <?php endif; ?>

      <a href="/pages/guide.php" class="<?= $navPage === 'guide' ? 'active' : '' ?>">
        <?= icon('file', 16) ?> Guide
      </a>
    </nav>

    <div class="hub-user dropdown">
      <button type="button" class="hub-user-btn" data-bs-toggle="dropdown" aria-expanded="false">
        <span class="hub-avatar"><?= h(initiales($navUser['name'] ?? '')) ?></span>
        <span class="d-none d-xl-inline"><?= h($navUser['name'] ?? '') ?></span>
        <span class="hub-caret"><?= icon('chevron', 14) ?></span>
      </button>
      <div class="dropdown-menu dropdown-menu-end">
        <div class="px-2 py-1">
          <div class="fw-semibold"><?= h($navUser['name'] ?? '') ?></div>
          <div class="text-muted small"><?= is_direction() ? 'Direction' : 'Accès société' ?></div>
        </div>
        <div class="dropdown-divider"></div>
        <a class="dropdown-item d-flex align-items-center gap-2" href="/pages/mon_compte.php"><?= icon('users', 15) ?> Mon compte</a>
        <a class="dropdown-item d-flex align-items-center gap-2" href="/pages/double_authentification.php"><?= icon('key', 15) ?> Double authentification</a>
        <a class="dropdown-item d-flex align-items-center gap-2" href="/pages/logout.php"><?= icon('logout', 15) ?> Déconnexion</a>
      </div>
    </div>
  </div>
</header>

<main class="hub-main">
<?php if (isset($_SESSION['flash_secours'])): $restantsSecours = (int) $_SESSION['flash_secours']; unset($_SESSION['flash_secours']); ?>
  <div class="alert alert-warning">
    Vous vous êtes connecté avec un code de secours. Il vous en reste <strong><?= $restantsSecours ?></strong>.
    Si vous avez perdu votre téléphone, <a href="/pages/double_authentification.php">changez d'appareil</a> dès que possible.
  </div>
<?php endif; ?>
