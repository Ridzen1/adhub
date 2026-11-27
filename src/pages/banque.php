<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/qalimo_banques.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

/**
 * Banque d'une société, d'après Qalimo (relu chaque matin) : comptes et soldes,
 * entrées / sorties du mois et des 12 derniers mois, dernières opérations.
 * Une seule société par page : rien n'est additionné ni comparé entre sociétés.
 */

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);
if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}
require_project_access($id, 'dashboard');

// Les restaurants n'affichent pas de trésorerie : la page n'est ouverte qu'à la direction
if (is_restaurant($project) && !is_direction()) {
    http_response_code(403);
    echo "Accès refusé.";
    exit;
}

$comptes = qalimo_banques_disponible() ? qalimo_comptes_projet($id) : [];
$uids = array_column($comptes, 'unique_id');
$compteChoisi = in_array($_GET['compte'] ?? '', $uids, true) ? $_GET['compte'] : null;
$sens = in_array($_GET['sens'] ?? '', ['entrees', 'sorties'], true) ? $_GET['sens'] : null;

$aujourdhui = new DateTimeImmutable('today');
$debutMois  = $aujourdhui->format('Y-m-01');
$moisPrec   = $aujourdhui->modify('first day of last month');
$solde      = array_sum(array_map(fn($c) => (float) $c['solde'], $comptes));
$fluxMois   = $comptes ? qalimo_flux_projet($id, $debutMois, $aujourdhui->format('Y-m-d')) : null;
$fluxPrec   = $comptes ? qalimo_flux_projet($id, $moisPrec->format('Y-m-01'), $moisPrec->format('Y-m-t')) : null;
$flux30     = $comptes ? qalimo_flux_projet($id, $aujourdhui->modify('-30 days')->format('Y-m-d'), $aujourdhui->format('Y-m-d')) : null;
$mensuel    = $comptes ? qalimo_flux_mensuels($id) : [];
$sorties    = $comptes ? qalimo_principales_sorties($id, $aujourdhui->modify('-90 days')->format('Y-m-d'), $aujourdhui->format('Y-m-d')) : [];
$operations = $comptes ? qalimo_operations_projet($id, 150, $compteChoisi, $sens) : [];
$alertes    = array_filter($comptes, fn($c) => qalimo_statut_compte($c['statut'])[1] !== 'success');
$lecture    = $comptes ? max(array_column($comptes, 'synced_at')) : null;

$meta = $lecture ? 'Banques lues dans Qalimo le ' . date('d/m/Y à H:i', strtotime($lecture)) : 'Banques Qalimo';

$pageTitle = $project['name'] . ' — Banque';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'banque', '', $meta) ?>

<?php if (!$comptes): ?>
  <div class="card"><div class="card-body empty-state">
    Aucun compte bancaire Qalimo n'est relié à <?= h($project['name']) ?>.
    <?php if (is_direction()): ?>
      Les comptes non reliés sont listés dans <a href="/pages/admin_tresorerie.php">Administration → Trésorerie</a>.
    <?php endif; ?>
  </div></div>
<?php else: ?>

<?php if ($alertes): ?>
  <div class="alert alert-warning">
    <?= count($alertes) === 1 ? 'Un compte n\'est plus synchronisé' : count($alertes) . ' comptes ne sont plus synchronisés' ?>
    avec la banque dans Qalimo (<?= h(implode(', ', array_map(fn($c) => trim(($c['banque'] ?? '') . ' ' . ($c['libelle'] ?? '')), $alertes))) ?>) :
    son solde et ses opérations peuvent dater. La reconnexion se fait dans Qalimo, page Banques.
  </div>
<?php endif; ?>

<div class="kpi-grid">
  <?= kpi('Trésorerie', eur($solde), [
        'hero' => true, 'icon' => 'bank',
        'foot' => '<span>' . count($comptes) . ' compte' . (count($comptes) > 1 ? 's' : '') . '</span>'
                . ($flux30 ? delta_eur($flux30['entrees'] - $flux30['sorties'], 'sur 30 j') : ''),
      ]) ?>
  <?= kpi('Entrées du mois', eur($fluxMois['entrees']), [
        'icon' => 'trend',
        'foot' => 'Mois dernier <strong class="text-body">' . eur($fluxPrec['entrees']) . '</strong>',
      ]) ?>
  <?= kpi('Sorties du mois', eur($fluxMois['sorties']), [
        'icon' => 'wallet',
        'foot' => 'Mois dernier <strong class="text-body">' . eur($fluxPrec['sorties']) . '</strong>',
      ]) ?>
  <?= kpi('Solde des flux du mois', eur($fluxMois['entrees'] - $fluxMois['sorties']), [
        'icon' => 'layers', 'class' => $fluxMois['entrees'] - $fluxMois['sorties'] < 0 ? 'kpi-warn' : '',
        'foot' => $fluxMois['nb'] . ' opération' . ($fluxMois['nb'] > 1 ? 's' : ''),
      ]) ?>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header">Comptes bancaires</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
          <tbody>
            <?php foreach ($comptes as $c): [$etat, $couleur] = qalimo_statut_compte($c['statut']); ?>
              <tr>
                <td>
                  <a class="fw-semibold text-body" href="?id=<?= $id ?>&amp;compte=<?= urlencode($c['unique_id']) ?>#operations"><?= h($c['banque'] ?: 'Compte') ?></a>
                  <?php if ($c['compte_gestion']): ?><span class="badge bg-info-subtle text-info-emphasis">Compte de gestion</span><?php endif; ?>
                  <div class="small text-muted">
                    <?= h($c['libelle'] ?? '') ?><?= $c['iban_fin'] ? ' …' . h($c['iban_fin']) : '' ?>
                    <span class="badge bg-<?= $couleur ?>-subtle text-<?= $couleur ?>-emphasis"><?= h($etat) ?></span>
                    <?php if ($c['maj_banque']): ?><span title="Dernière synchronisation Qalimo ↔ banque">· <?= date('d/m H:i', strtotime($c['maj_banque'])) ?></span><?php endif; ?>
                  </div>
                </td>
                <td class="text-end fw-semibold <?= (float) $c['solde'] < 0 ? 'text-danger' : '' ?>"><?= $c['solde'] !== null ? eur((float) $c['solde'], 2) : '—' ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (count($comptes) > 1): ?>
              <tr class="table-light">
                <td class="fw-semibold">Total <?= h($project['name']) ?></td>
                <td class="text-end fw-bold"><?= eur($solde, 2) ?></td>
              </tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header">
        Entrées et sorties par mois
        <span class="legend-inline">
          <span><i style="background:var(--ad-green)"></i>Entrées</span>
          <span><i style="background:var(--ad-red)"></i>Sorties</span>
        </span>
      </div>
      <div class="card-body"><div class="chart-box"><canvas id="chartBanque"></canvas></div></div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  <div class="col-xl-4">
    <div class="card h-100">
      <div class="card-header">Principales sorties <span class="text-muted small fw-normal">90 derniers jours</span></div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <?php foreach ($sorties as $s): ?>
              <tr>
                <td><div class="text-truncate" style="max-width:min(240px,55vw)" title="<?= h($s['libelle']) ?>"><?= h($s['libelle'] ?: '—') ?></div>
                    <?php if ((int) $s['nb'] > 1): ?><div class="small text-muted"><?= (int) $s['nb'] ?> opérations</div><?php endif; ?></td>
                <td class="text-end fw-semibold"><?= eur((float) $s['total']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$sorties): ?><tr><td class="text-muted text-center py-3">Aucune sortie.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-8">
    <div class="card h-100" id="operations">
      <div class="card-header">
        Dernières opérations
        <form method="get" class="d-flex gap-2 m-0 ms-auto">
          <input type="hidden" name="id" value="<?= $id ?>">
          <?php if (count($comptes) > 1): ?>
          <select name="compte" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Tous les comptes</option>
            <?php foreach ($comptes as $c): ?>
              <option value="<?= h($c['unique_id']) ?>"<?= $c['unique_id'] === $compteChoisi ? ' selected' : '' ?>><?= h(trim(($c['banque'] ?? '') . ' ' . ($c['libelle'] ?? ''))) ?></option>
            <?php endforeach; ?>
          </select>
          <?php endif; ?>
          <select name="sens" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">Entrées et sorties</option>
            <option value="entrees"<?= $sens === 'entrees' ? ' selected' : '' ?>>Entrées</option>
            <option value="sorties"<?= $sens === 'sorties' ? ' selected' : '' ?>>Sorties</option>
          </select>
        </form>
      </div>
      <div class="card-body p-0" style="max-height:560px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead style="position:sticky;top:0;z-index:1"><tr><th>Date</th><th>Libellé</th><?php if (count($comptes) > 1): ?><th class="d-none d-md-table-cell">Compte</th><?php endif; ?><th class="text-end">Montant</th></tr></thead>
          <tbody>
            <?php foreach ($operations as $o): ?>
              <tr>
                <td class="text-nowrap text-muted"><?= date('d/m/Y', strtotime($o['date_operation'])) ?></td>
                <td><div class="text-truncate" style="max-width:min(420px,50vw)" title="<?= h($o['libelle']) ?>"><?= h($o['libelle'] ?: '—') ?></div></td>
                <?php if (count($comptes) > 1): ?><td class="small text-muted d-none d-md-table-cell"><?= h($o['banque'] ?: $o['compte']) ?></td><?php endif; ?>
                <td class="text-end fw-semibold text-nowrap <?= (float) $o['montant'] > 0 ? 'text-success' : '' ?>"><?= ((float) $o['montant'] > 0 ? '+' : '') . eur((float) $o['montant'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$operations): ?><tr><td colspan="4" class="text-muted text-center py-4">Aucune opération.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<script>
(function () {
  var m = <?= json_encode(array_map(fn($k, $v) => ['mois' => mois_court($k . '-01'), 'entrees' => round($v['entrees'], 2), 'sorties' => round($v['sorties'], 2)], array_keys($mensuel), $mensuel), JSON_UNESCAPED_UNICODE) ?>;
  // Les mois d'avant la première opération connue sont retirés
  while (m.length > 3 && !m[0].entrees && !m[0].sorties) m.shift();
  var c = ADHUB.colors;
  new Chart(document.getElementById('chartBanque'), {
    type: 'bar',
    data: {
      labels: m.map(function (x) { return x.mois; }),
      datasets: [
        { label: 'Entrées', data: m.map(function (x) { return x.entrees; }), backgroundColor: c.green, maxBarThickness: 18 },
        { label: 'Sorties', data: m.map(function (x) { return x.sorties; }), backgroundColor: c.red, maxBarThickness: 18 }
      ]
    },
    options: {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
      scales: { x: { grid: { display: false } }, y: { ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
    }
  });
})();
</script>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
