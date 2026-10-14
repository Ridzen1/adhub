<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/financials.php';
require_login();

// La direction n'a rien à faire ici : sa page d'accueil, c'est la vue globale.
if (is_direction()) {
    header('Location: /pages/vue_globale.php');
    exit;
}

$user     = current_user();
$projects = get_visible_projects();

$pageTitle = 'Mes sociétés';
require __DIR__ . '/../inc/layout_header.php';
?>

<h1 class="mb-1">Mes sociétés</h1>
<p class="text-muted mb-4">
  Bonjour <?= htmlspecialchars($user['name']) ?>, voici les sociétés auxquelles vous avez accès.
</p>

<?php if (!$projects): ?>
  <div class="alert alert-secondary">
    Aucune société ne vous est attribuée pour l'instant. Contactez la direction si c'est une erreur.
  </div>
<?php else: ?>
  <div class="row row-cols-1 row-cols-md-2 g-3">
    <?php foreach ($projects as $p):
      $latest = get_latest_entry((int) $p['id']);
      $net = $latest ? ((float) $latest['revenus'] - (float) $latest['charges']) : null;
    ?>
      <div class="col">
        <a href="/pages/project_dashboard.php?id=<?= (int) $p['id'] ?>" class="text-decoration-none text-dark">
          <div class="card h-100">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-start">
                <h5 class="card-title mb-0"><?= htmlspecialchars($p['name']) ?></h5>
                <?php if ($net !== null): ?>
                  <span class="badge <?= $net >= 0 ? 'bg-success' : 'bg-danger' ?>">
                    <?= $net >= 0 ? 'Rentable' : 'Non rentable' ?>
                  </span>
                <?php endif; ?>
              </div>
              <div class="text-muted small mb-2"><?= htmlspecialchars($p['type_label']) ?></div>
              <?php if ($net !== null): ?>
                <div class="fs-4 <?= $net >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= number_format($net, 0, ',', ' ') ?> €
                </div>
                <div class="text-muted small"><?= date('F Y', strtotime($latest['period_date'])) ?></div>
              <?php else: ?>
                <div class="text-muted">Pas encore de données</div>
              <?php endif; ?>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
