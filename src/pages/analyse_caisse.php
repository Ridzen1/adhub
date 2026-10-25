<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/periodes.php';
require_once __DIR__ . '/../inc/caisses.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'dashboard');

/**
 * Analyse détaillée des ventes d'un restaurant, à partir du détail des caisses.
 * Pensée pour le dirigeant : où se fait le chiffre (produits, catégories, heures,
 * salles, serveurs), comment il est encaissé, et ce qui s'en échappe (remises,
 * offerts, annulations).
 */

// --- Période ---------------------------------------------------------------
$aujourdhui = new DateTimeImmutable('today');
$presets = [
    'hier'    => ['Hier', $aujourdhui->modify('-1 day'), $aujourdhui->modify('-1 day')],
    '7j'      => ['7 derniers jours', $aujourdhui->modify('-7 days'), $aujourdhui->modify('-1 day')],
    'mois'    => ['Mois en cours', $aujourdhui->modify('first day of this month'), $aujourdhui],
    'mois_m1' => ['Mois dernier', $aujourdhui->modify('first day of last month'), $aujourdhui->modify('last day of last month')],
    '30j'     => ['30 derniers jours', $aujourdhui->modify('-30 days'), $aujourdhui->modify('-1 day')],
];

$periode = $_GET['periode'] ?? '7j';
$du = $_GET['du'] ?? '';
$au = $_GET['au'] ?? '';
$dateValide = fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;

if ($periode === 'perso' && $dateValide($du) && $dateValide($au)) {
    if ($du > $au) {
        [$du, $au] = [$au, $du];
    }
} else {
    if (!isset($presets[$periode])) {
        $periode = '7j';
    }
    $du = $presets[$periode][1]->format('Y-m-d');
    $au = $presets[$periode][2]->format('Y-m-d');
}

// --- Données ---------------------------------------------------------------
$sources     = caisse_sources_projet($id);
$kpis        = analyse_kpis($id, $du, $au);
$parJour     = analyse_par_jour($id, $du, $au);
$parSemaine  = analyse_par_jour_semaine($id, $du, $au);
$parHeure    = analyse_par_heure($id, $du, $au);
$services    = analyse_services($id, $du, $au);
$categories  = analyse_par_categorie($id, $du, $au);
$top         = analyse_produits($id, $du, $au, 15, 'DESC');
$flop        = analyse_produits($id, $du, $au, 10, 'ASC');
$salles      = analyse_par_salle($id, $du, $au);
$serveurs    = analyse_par_serveur($id, $du, $au);
$paiements   = analyse_paiements($id, $du, $au);
$tva         = analyse_tva($id, $du, $au);
$fuites      = analyse_offerts_annulations($id, $du, $au, 30);

$caCategories = array_sum(array_map(fn($c) => (float) $c['ttc'], $categories));
$totalPaiements = array_sum(array_map(fn($p) => (float) $p['montant'], $paiements));
$aClasser = array_filter($categories, fn($c) => $c['famille'] === null);

function euro(?float $v, int $dec = 0): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', ' ') . ' €';
}

function pct(float $part, float $total): string
{
    return $total > 0 ? number_format($part / $total * 100, 1, ',', ' ') . ' %' : '—';
}

function badge_var(?float $v): string
{
    if ($v === null) {
        return '';
    }
    $classe = $v >= 0 ? 'text-success' : 'text-danger';
    return '<span class="' . $classe . ' fw-semibold">' . ($v >= 0 ? '+' : '')
         . number_format($v, 1, ',', ' ') . ' %</span>';
}

const NOMS_JOURS = [1 => 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi', 'Dimanche'];
const COULEURS_FAMILLE = ['solide' => '#0F7B55', 'liquide' => '#1F5FAD', 'autre' => '#9CA3AF'];

$pageTitle = $project['name'] . ' — Analyse des ventes';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'analyse', '',
      $sources ? 'Caisse ' . h(implode(' + ', array_map(fn($s) => CAISSE_SOURCES[$s], $sources))) : 'Aucune caisse reliée') ?>

<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <ul class="nav nav-pills">
    <?php foreach ($presets as $k => [$lab]): ?>
      <li class="nav-item">
        <a class="nav-link py-1 <?= $periode === $k ? 'active' : '' ?>"
           href="?id=<?= $id ?>&periode=<?= $k ?>"><?= $lab ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="get" class="d-flex gap-1 align-items-center ms-md-3 date-range">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="periode" value="perso">
    <input type="date" name="du" value="<?= htmlspecialchars($du) ?>" class="form-control form-control-sm">
    <span class="text-muted small">au</span>
    <input type="date" name="au" value="<?= htmlspecialchars($au) ?>" class="form-control form-control-sm">
    <button class="btn btn-sm btn-dark">OK</button>
  </form>
</div>

<p class="text-muted">
  Du <?= date('d/m/Y', strtotime($du)) ?> au <?= date('d/m/Y', strtotime($au)) ?>
  &middot; <?= $kpis['jours'] ?> jour(s) avec des ventes
  <?php if ($kpis['n1_ca_ttc'] !== null): ?>
    &middot; comparé au <?= date('d/m/Y', strtotime($kpis['n1_du'])) ?> – <?= date('d/m/Y', strtotime($kpis['n1_au'])) ?>
  <?php endif; ?>
</p>

<?php if (!$sources): ?>
  <div class="alert alert-secondary">
    Aucune caisse n'est reliée à ce restaurant : les analyses détaillées apparaîtront après
    la première synchronisation (voir <code>config/caisses.local.php</code>).
    Les chiffres ci-dessous viennent des saisies quotidiennes.
  </div>
<?php endif; ?>

<?php if ($kpis['jours'] === 0 && !$categories): ?>
  <div class="alert alert-warning">Aucune vente sur cette période.</div>
<?php else: ?>

<!-- Indicateurs -->
<?php $mixTot = $kpis['ca_solide'] + $kpis['ca_liquide']; ?>
<div class="kpi-grid">
  <?= kpi("Chiffre d'affaires TTC", euro($kpis['ca_ttc']), [
        'hero' => true, 'icon' => 'receipt',
        'foot' => delta(variation($kpis['ca_ttc'], $kpis['n1_ca_ttc']), false, 'vs N-1') . '<span>HT ' . euro($kpis['ca_ht']) . '</span>',
      ]) ?>
  <?= kpi('CA moyen par jour ouvert', euro($kpis['ca_par_jour']), [
        'icon' => 'calendar', 'foot' => '<span>' . $kpis['jours'] . ' jour(s) avec des ventes</span>',
      ]) ?>
  <?php if ($kpis['couverts'] > 0): ?>
    <?= kpi('Couverts', number_format($kpis['couverts'], 0, ',', "\u{202F}"), [
          'icon' => 'users',
          'foot' => delta(variation((float) $kpis['couverts'], $kpis['n1_couverts'] !== null ? (float) $kpis['n1_couverts'] : null), false, 'vs N-1')
                  . '<span>Panier moyen <strong class="text-body">' . euro($kpis['panier_moyen'], 2) . '</strong></span>',
        ]) ?>
  <?php endif; ?>
  <?php if ($kpis['tickets'] > 0): ?>
    <?= kpi('Additions', number_format($kpis['tickets'], 0, ',', "\u{202F}"), [
          'icon' => 'file', 'foot' => '<span>Ticket moyen <strong class="text-body">' . euro($kpis['ticket_moyen'], 2) . '</strong></span>',
        ]) ?>
  <?php endif; ?>
  <?php if ($mixTot > 0): ?>
    <?= kpi('Solide / liquide', pct($kpis['ca_solide'], $mixTot) . ' <small>/ ' . pct($kpis['ca_liquide'], $mixTot) . '</small>', [
          'icon' => 'pie',
          'foot' => '<div class="meter" style="flex:1;min-width:80px"><span style="width:' . round($kpis['ca_solide'] / $mixTot * 100) . '%;background:#0F7B55"></span></div>',
        ]) ?>
  <?php endif; ?>
  <?php if ($kpis['remises'] > 0 || $kpis['offerts'] > 0): ?>
    <?= kpi('Remises et offerts', euro($kpis['remises'] + $kpis['offerts']), [
          'icon' => 'percent',
          'foot' => '<span>' . pct($kpis['remises'] + $kpis['offerts'], $kpis['ca_ttc'] + $kpis['remises'] + $kpis['offerts']) . ' du CA brut</span>',
        ]) ?>
  <?php endif; ?>
  <?php if ($kpis['annulations'] > 0): ?>
    <?= kpi('Lignes annulées', (string) $kpis['annulations'], [
          'icon' => 'alert', 'foot' => $kpis['annulations_ttc'] > 0 ? '<span>' . euro($kpis['annulations_ttc'], 2) . '</span>' : '',
        ]) ?>
  <?php endif; ?>
  <?php if ($kpis['pourboires'] > 0): ?>
    <?= kpi('Pourboires', euro($kpis['pourboires'], 2), ['icon' => 'wallet']) ?>
  <?php endif; ?>
</div>

<!-- CA par jour -->
<?php if (count($parJour) > 1): ?>
<div class="card mb-4">
  <div class="card-header">Chiffre d'affaires jour par jour</div>
  <div class="card-body"><div class="chart-box"><canvas id="chartJours"></canvas></div></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- Heures -->
  <?php if ($parHeure): ?>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Ventes par heure</div>
      <div class="card-body"><div class="chart-box sm"><canvas id="chartHeures"></canvas></div></div>
    </div>
  </div>
  <?php endif; ?>
  <!-- Services -->
  <?php if ($services): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Midi / soir</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Service</th><th class="text-end">CA</th><th class="text-end">Part</th><th class="text-end">Moy. / jour</th></tr></thead>
          <tbody>
            <?php $totServ = array_sum(array_map(fn($s) => (float) $s['ttc'], $services));
            foreach ($services as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['service']) ?></td>
                <td class="text-end"><?= euro((float) $s['ttc']) ?></td>
                <td class="text-end"><?= pct((float) $s['ttc'], $totServ) ?></td>
                <td class="text-end"><?= euro((int) $s['jours'] > 0 ? (float) $s['ttc'] / (int) $s['jours'] : null) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="small text-muted p-2">Midi = ventes avant 16 h.</div>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Jours de la semaine -->
<?php if (count($parSemaine) > 1): ?>
<div class="card mb-4">
  <div class="card-header">Moyenne par jour de la semaine</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Jour</th><th class="text-end">Jours ouverts</th><th class="text-end">CA moyen</th><th class="text-end">Couverts moyens</th></tr></thead>
      <tbody>
        <?php foreach ($parSemaine as $j): ?>
          <tr>
            <td><?= NOMS_JOURS[(int) $j['jour_semaine']] ?></td>
            <td class="text-end"><?= (int) $j['jours'] ?></td>
            <td class="text-end"><?= euro((float) $j['ca_moyen']) ?></td>
            <td class="text-end"><?= (float) $j['couverts_moyen'] > 0 ? number_format((float) $j['couverts_moyen'], 0, ',', ' ') : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Catégories -->
<?php if ($categories): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Répartition par catégorie</div>
      <div class="card-body"><div class="chart-box"><canvas id="chartCategories"></canvas></div></div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Catégories</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Catégorie</th><th>Famille</th><th class="text-end">Qté</th><th class="text-end">CA TTC</th><th class="text-end">Part</th></tr></thead>
          <tbody>
            <?php foreach ($categories as $c): ?>
              <tr>
                <td><?= htmlspecialchars($c['categorie']) ?></td>
                <td>
                  <?php if ($c['famille'] === null): ?>
                    <span class="badge bg-warning text-dark">à classer</span>
                  <?php else: ?>
                    <span class="badge" style="background:<?= COULEURS_FAMILLE[$c['famille']] ?>"><?= $c['famille'] ?></span>
                  <?php endif; ?>
                </td>
                <td class="text-end"><?= number_format((float) $c['quantite'], 0, ',', ' ') ?></td>
                <td class="text-end"><?= euro((float) $c['ttc']) ?></td>
                <td class="text-end"><?= pct((float) $c['ttc'], $caCategories) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <?php if ($aClasser && is_direction()): ?>
          <div class="small p-2">
            <a href="/pages/admin_caisses.php">Classer les catégories en solide / liquide</a>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Produits -->
<?php if ($top): ?>
<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Meilleures ventes (15)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Produit</th><th>Catégorie</th><th class="text-end">Qté</th><th class="text-end">CA TTC</th><th class="text-end">Prix moyen</th></tr></thead>
          <tbody>
            <?php foreach ($top as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['produit']) ?></td>
                <td class="text-muted small"><?= htmlspecialchars($p['categorie']) ?></td>
                <td class="text-end"><?= number_format((float) $p['quantite'], 0, ',', ' ') ?></td>
                <td class="text-end"><?= euro((float) $p['ttc']) ?></td>
                <td class="text-end"><?= euro($p['prix_moyen'] !== null ? (float) $p['prix_moyen'] : null, 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Les moins vendus (10)</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Produit</th><th class="text-end">Qté</th><th class="text-end">CA TTC</th></tr></thead>
          <tbody>
            <?php foreach ($flop as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['produit']) ?></td>
                <td class="text-end"><?= number_format((float) $p['quantite'], 0, ',', ' ') ?></td>
                <td class="text-end"><?= euro((float) $p['ttc'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
        <div class="small text-muted p-2">Seulement les produits vendus au moins une fois sur la période.</div>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- Paiements -->
  <?php if ($paiements): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Encaissements</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <?php foreach ($paiements as $p): ?>
              <tr>
                <td><?= htmlspecialchars($p['mode']) ?></td>
                <td class="text-end"><?= euro((float) $p['montant']) ?></td>
                <td class="text-end text-muted"><?= pct((float) $p['montant'], $totalPaiements) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- Salles -->
  <?php if (count($salles) > 1): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Par salle</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <?php $totSalles = array_sum(array_map(fn($s) => (float) $s['ttc'], $salles));
            foreach ($salles as $s): ?>
              <tr>
                <td><?= htmlspecialchars($s['salle']) ?></td>
                <td class="text-end"><?= euro((float) $s['ttc']) ?></td>
                <td class="text-end text-muted"><?= pct((float) $s['ttc'], $totSalles) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- TVA -->
  <?php if ($tva): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">TVA collectée</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead><tr><th>Taux</th><th class="text-end">HT</th><th class="text-end">TVA</th></tr></thead>
          <tbody>
            <?php foreach ($tva as $t): ?>
              <tr>
                <td><?= rtrim(rtrim(number_format((float) $t['taux'], 2, ',', ''), '0'), ',') ?> %</td>
                <td class="text-end"><?= euro((float) $t['ht']) ?></td>
                <td class="text-end"><?= euro((float) $t['tva'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<!-- Serveurs -->
<?php if ($serveurs): ?>
<div class="card mb-4">
  <div class="card-header">Par serveur</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Serveur</th><th class="text-end">Additions</th><th class="text-end">CA TTC</th><th class="text-end">Ticket moyen</th><th class="text-end">Offerts</th></tr></thead>
      <tbody>
        <?php foreach ($serveurs as $s): ?>
          <tr>
            <td><?= htmlspecialchars($s['serveur']) ?></td>
            <td class="text-end"><?= (int) $s['tickets'] ?></td>
            <td class="text-end"><?= euro((float) $s['ttc']) ?></td>
            <td class="text-end"><?= euro($s['ticket_moyen'] !== null ? (float) $s['ticket_moyen'] : null, 2) ?></td>
            <td class="text-end"><?= (float) $s['offert'] > 0 ? euro((float) $s['offert'], 2) : '—' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Offerts et annulations -->
<?php if ($fuites): ?>
<div class="card mb-4">
  <div class="card-header">Offerts et annulations (30 plus importants)</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Serveur</th><th>Table</th><th>Produit</th><th>Type</th><th class="text-end">Montant</th></tr></thead>
      <tbody>
        <?php foreach ($fuites as $f): ?>
          <tr>
            <td><?= $f['date_ligne'] ? date('d/m H:i', strtotime($f['date_ligne'])) : date('d/m', strtotime($f['jour'])) ?></td>
            <td><?= htmlspecialchars($f['serveur'] ?? '—') ?></td>
            <td><?= htmlspecialchars($f['table_nom'] ?? '—') ?></td>
            <td><?= htmlspecialchars($f['produit'] ?? '') ?></td>
            <td><?= (int) $f['annule'] ? '<span class="badge bg-secondary">annulé</span>' : '<span class="badge bg-warning text-dark">offert</span>' ?></td>
            <td class="text-end"><?= euro((int) $f['annule'] ? (float) $f['brut_ttc'] : (float) $f['offert_ttc'], 2) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<div class="alert alert-light border small">
  <strong>D'où viennent les chiffres :</strong> les indicateurs du haut reprennent les saisies
  quotidiennes (alimentées chaque nuit par la caisse, ou saisies à la main). Le détail
  (produits, catégories, heures, salles, serveurs, encaissements) vient directement des tickets
  de caisse. Une journée corrigée à la main peut donc différer légèrement du détail.
  <?php if (in_array('laddition', $sources, true)): ?>
    L'Addition ne transmet ni les couverts, ni le serveur, ni les modes de paiement : ces blocs
    n'apparaissent que pour Popina.
  <?php endif; ?>
</div>

<?php endif; ?>

<?php if (count($parJour) > 1 || $parHeure || $categories): ?>
<script>
<?php if (count($parJour) > 1): ?>
new Chart(document.getElementById('chartJours'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($j) => date('d/m', strtotime($j['jour'])), $parJour)) ?>,
    datasets: [
      { label: 'Solide', data: <?= json_encode(array_map(fn($j) => (float) $j['ca_solide'], $parJour)) ?>, backgroundColor: '#0F7B55', stack: 'ca', maxBarThickness: 26 },
      { label: 'Liquide', data: <?= json_encode(array_map(fn($j) => (float) $j['ca_liquide'], $parJour)) ?>, backgroundColor: '#1F5FAD', stack: 'ca', maxBarThickness: 26 },
      { label: 'Autre / non réparti', data: <?= json_encode(array_map(
          fn($j) => max(0, round((float) $j['ca_ttc'] - (float) $j['ca_solide'] - (float) $j['ca_liquide'], 2)), $parJour)) ?>,
        backgroundColor: '#CBD2DB', stack: 'ca', maxBarThickness: 26 }
    ]
  },
  options: { plugins: { legend: { position: 'top', align: 'end' }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
             scales: { x: { stacked: true, grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                       y: { stacked: true, beginAtZero: true, ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } } }
});
<?php endif; ?>

<?php if ($parHeure): ?>
new Chart(document.getElementById('chartHeures'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($h) => $h['heure'] . 'h', $parHeure)) ?>,
    datasets: [{ label: 'CA TTC', data: <?= json_encode(array_map(fn($h) => (float) $h['ttc'], $parHeure)) ?>,
                 backgroundColor: '#C8A84B', maxBarThickness: 34 }]
  },
  options: { plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + ADHUB.eur(x.raw); } } } },
             scales: { x: { grid: { display: false } }, y: { beginAtZero: true, ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } } }
});
<?php endif; ?>

<?php if ($categories): ?>
new Chart(document.getElementById('chartCategories'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_column($categories, 'categorie')) ?>,
    datasets: [{
      data: <?= json_encode(array_map(fn($c) => (float) $c['ttc'], $categories)) ?>,
      backgroundColor: <?= json_encode(array_map(function ($c, $i) {
          $palette = ['#1F5FAD', '#C8A84B', '#0F7B55', '#7C5CC4', '#D9673B', '#2BA0B5', '#0E1C2B', '#81B29A', '#E3B5A4', '#9CA3AF', '#B5838D', '#457B9D'];
          return $palette[$i % count($palette)];
      }, $categories, array_keys($categories))) ?>
    }]
  },
  options: { cutout: '62%', plugins: { legend: { position: ADHUB.legendPos() }, tooltip: { mode: 'nearest', callbacks: { label: function (x) { return ' ' + ADHUB.eur(x.raw); } } } } }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
