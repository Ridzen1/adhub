<?php
/**
 * En-tête commun. Usage :
 *   $pageTitle = "Mon titre";
 *   require __DIR__ . '/../inc/layout_header.php';
 *
 * Suppose que require_login() a déjà été appelé par la page.
 */
require_once __DIR__ . '/access.php';

$navUser     = current_user();
$navProjects = get_visible_projects();

// Regroupement par type pour le menu latéral
$navGroups = [];
foreach ($navProjects as $np) {
    $navGroups[$np['type_label']][] = $np;
}

$currentProjectId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= htmlspecialchars($pageTitle ?? "AD'HUB") ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background: #F4F6F9; }
    .topbar { background: #0E1C2B; padding: 12px 24px; display: flex; justify-content: space-between; align-items: center; }
    .brand { color: #C8A84B; font-weight: bold; letter-spacing: 1px; font-size: 18px; text-decoration: none; }
    .topbar .user { color: #AAB4C4; font-size: 13px; }
    .topbar .user a { color: #fff; }
    .layout { display: flex; min-height: calc(100vh - 52px); }
    .sidebar { width: 250px; background: #fff; border-right: 1px solid #E3E3DE; padding: 18px 0; flex-shrink: 0; }
    .sidebar .group-label { font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6B7280; font-weight: 600; padding: 12px 20px 6px; }
    .sidebar a.nav-item { display: block; padding: 7px 20px; font-size: 14px; color: #1A1A1A; text-decoration: none; }
    .sidebar a.nav-item:hover { background: #F4F6F9; }
    .sidebar a.nav-item.active { background: #EEF2FA; color: #0E1C2B; font-weight: 600; border-left: 3px solid #C8A84B; padding-left: 17px; }
    .sidebar .top-link { display: block; padding: 8px 20px; font-size: 14px; font-weight: 600; color: #0E1C2B; text-decoration: none; }
    .sidebar .top-link:hover { background: #F4F6F9; }
    .main { flex: 1; padding: 26px 32px; }
    .badge-type { background: #0E1C2B; }
    .badge-archived { background: #6B7280; }
  </style>
</head>
<body>

<div class="topbar">
  <a class="brand" href="/pages/accueil.php">AD'HUB</a>
  <div class="user">
    <?= htmlspecialchars($navUser['name'] ?? '') ?>
    &middot; <a href="/pages/mon_compte.php">Mon compte</a>
    &middot; <a href="/pages/logout.php">Déconnexion</a>
  </div>
</div>

<div class="layout">
  <div class="sidebar">
    <?php if (is_direction()): ?>
      <a class="top-link" href="/pages/vue_globale.php">Vue globale</a>
      <a class="top-link" href="/pages/projects.php">Gérer les sociétés</a>
      <a class="top-link" href="/pages/admin_users.php">Utilisateurs</a>
      <a class="top-link" href="/pages/admin_logs.php">Journal des accès</a>
      <a class="top-link" href="/pages/admin_caisses.php">Caisses</a>
    <?php else: ?>
      <a class="top-link" href="/pages/accueil.php">Mes sociétés</a>
    <?php endif; ?>

    <?php foreach ($navGroups as $groupLabel => $groupProjects): ?>
      <div class="group-label"><?= htmlspecialchars($groupLabel) ?></div>
      <?php foreach ($groupProjects as $gp): ?>
        <a class="nav-item <?= $currentProjectId === (int) $gp['id'] ? 'active' : '' ?>"
           href="/pages/project_dashboard.php?id=<?= (int) $gp['id'] ?>">
          <?= htmlspecialchars($gp['name']) ?>
        </a>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <?php if (!$navGroups): ?>
      <div class="group-label">Aucune société accessible</div>
    <?php endif; ?>
  </div>

  <div class="main">
