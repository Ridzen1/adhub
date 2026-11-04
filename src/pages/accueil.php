<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/financials.php';
require_once __DIR__ . '/../inc/indicateurs.php';
require_once __DIR__ . '/../inc/cartes_restaurants.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

// La direction n'a rien à faire ici : sa page d'accueil, c'est la vue globale.
if (is_direction()) {
    header('Location: /pages/vue_globale.php');
    exit;
}

$user     = current_user();
$projects = get_visible_projects();

// Les restaurants dont on voit le tableau de bord ont leurs indicateurs détaillés
// (mêmes cartes que la vue d'ensemble de la direction) ; les autres sociétés gardent une carte simple.
$restaurants = array_values(array_filter($projects, fn($p) =>
    $p['type_code'] === 'restauration_exploitation' && can_view_project((int) $p['id'], 'dashboard')));
$idsRestos = array_map(fn($p) => (int) $p['id'], $restaurants);
$autres    = array_values(array_filter($projects, fn($p) => !in_array((int) $p['id'], $idsRestos, true)));

$pageTitle = 'Mes sociétés';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Mes sociétés', 'Bonjour ' . h($user['name']) . ', voici les sociétés auxquelles vous avez accès.') ?>

<?php if (!$projects): ?>
  <div class="card"><div class="empty-state">
    <?= icon('building', 32) ?>
    <div>Aucune société ne vous est attribuée pour l'instant. Contactez la direction si c'est une erreur.</div>
  </div></div>
<?php else: ?>
  <?php afficher_cartes_restaurants($restaurants); ?>
  <?php if ($autres): ?>
  <?php if ($restaurants): ?><div class="family-head mb-3"><div class="family-title">Autres sociétés</div></div><?php endif; ?>
  <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
    <?php foreach ($autres as $p):
      $pid     = (int) $p['id'];
      $voitDash = can_view_project($pid, 'dashboard');
      $latest  = $voitDash ? get_latest_entry($pid) : null;
      $net     = $latest ? ((float) $latest['revenus'] - (float) $latest['charges']) : null;
      $estResto = $p['type_code'] === 'restauration_exploitation';
    ?>
      <div class="col">
        <a href="<?= lien_societe($pid) ?>" class="kpi company-card">
          <div class="cc-top">
            <div>
              <div class="cc-name"><?= h($p['name']) ?></div>
              <div class="mt-1"><?= type_chip($p['type_code'], $p['type_label']) ?></div>
            </div>
            <?php if ($net !== null): ?>
              <span class="badge <?= $net >= 0 ? 'bg-success' : 'bg-danger' ?>"><?= $net >= 0 ? 'Rentable' : 'Non rentable' ?></span>
            <?php endif; ?>
          </div>
          <div class="row g-2">
            <?php if (!$voitDash): ?>
              <div class="col-12 text-muted small"><?= icon('file', 14) ?> Accès aux documents uniquement</div>
            <?php else: ?>
            <div class="col-6">
              <div class="kpi-label">Résultat net<?= $latest ? ' · ' . h(mois_court($latest['period_date'])) : '' ?></div>
              <div class="fw-bold fs-5 num <?= $net !== null && $net < 0 ? 'text-danger' : '' ?>"><?= $net !== null ? eur($net) : '<span class="text-muted fw-normal fs-6">—</span>' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($voitDash && $p['solde_bancaire'] !== null && !$estResto): // jamais de trésorerie pour un restaurant ?>
              <div class="col-6">
                <div class="kpi-label">Trésorerie</div>
                <div class="fw-bold fs-5 num"><?= eur((float) $p['solde_bancaire']) ?></div>
              </div>
            <?php endif; ?>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
