<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/documents.php';
require_direction();

$entries = get_access_log(200);

$pageTitle = 'Journal des accès';
require __DIR__ . '/../inc/layout_header.php';
?>

<h1 class="mb-1">Journal des accès aux documents</h1>
<p class="text-muted mb-4">200 dernières ouvertures de dossier.</p>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th>Date</th>
          <th>Utilisateur</th>
          <th>Société</th>
          <th>Catégorie</th>
          <th>IP</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($e['opened_at'])) ?></td>
            <td><?= htmlspecialchars($e['user_name']) ?></td>
            <td><?= htmlspecialchars($e['project_name']) ?></td>
            <td><?= htmlspecialchars($e['category_label']) ?></td>
            <td class="text-muted small"><?= htmlspecialchars($e['ip_address'] ?? '—') ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
          <tr><td colspan="5" class="text-center text-muted py-3">Aucun accès enregistré pour l'instant.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-light border mt-4">
  Ce journal trace l'<strong>ouverture des liens depuis l'outil</strong>. Il ne voit pas ce qui se
  passe ensuite sur OneDrive (téléchargement, partage du lien à un tiers) — cette partie
  relève des paramètres de partage OneDrive.
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
