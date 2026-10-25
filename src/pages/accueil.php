<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/financials.php';
require_once __DIR__ . '/../inc/indicateurs.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

// La direction n'a rien à faire ici : sa page d'accueil, c'est la vue globale.
if (is_direction()) {
    header('Location: /pages/vue_globale.php');
    exit;
}

$user     = current_user();
$projects = get_visible_projects();

$bornesMois = bornes_periode('mois');

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
  <div class="row row-cols-1 row-cols-md-2 row-cols-xl-3 g-4">
    <?php foreach ($projects as $p):
      $pid     = (int) $p['id'];
      $voitDash = can_view_project($pid, 'dashboard');
      $latest  = $voitDash ? get_latest_entry($pid) : null;
      $net     = $latest ? ((float) $latest['revenus'] - (float) $latest['charges']) : null;
      $caMois  = $voitDash && $p['type_code'] === 'restauration_exploitation'
               ? ca_societes([$pid], $bornesMois['debut'], $bornesMois['fin'])['ca'] : null;
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
            <?php if ($caMois !== null): ?>
              <div class="col-6">
                <div class="kpi-label">CA du mois</div>
                <div class="fw-bold fs-5 num"><?= eur($caMois) ?></div>
              </div>
            <?php endif; ?>
            <?php if (!$voitDash): ?>
              <div class="col-12 text-muted small"><?= icon('file', 14) ?> Accès aux documents uniquement</div>
            <?php else: ?>
            <div class="col-6">
              <div class="kpi-label">Résultat net<?= $latest ? ' · ' . h(mois_court($latest['period_date'])) : '' ?></div>
              <div class="fw-bold fs-5 num <?= $net !== null && $net < 0 ? 'text-danger' : '' ?>"><?= $net !== null ? eur($net) : '<span class="text-muted fw-normal fs-6">—</span>' ?></div>
            </div>
            <?php endif; ?>
            <?php if ($voitDash && $p['solde_bancaire'] !== null && $caMois === null): ?>
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

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
