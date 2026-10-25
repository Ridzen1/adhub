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
const COULEURS_FAMILLE = ['solide' => '#2D6A4F', 'liquide' => '#2A78D6', 'autre' => '#AAB4C4'];

$pageTitle = $project['name'] . ' — Analyse des ventes';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-3">
  <div>
    <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
    <div class="text-muted">Analyse des ventes
      <?php if ($sources): ?>
        &middot; caisse <?= htmlspecialchars(implode(' + ', array_map(fn($s) => CAISSE_SOURCES[$s], $sources))) ?>
      <?php endif; ?>
    </div>
  </div>
  <div>
    <a href="/pages/project_dashboard.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Dashboard</a>
    <a href="/pages/evolution.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Évolution</a>
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Saisie</a>
  </div>
</div>

<div class="d-flex flex-wrap gap-2 align-items-center mb-4">
  <ul class="nav nav-pills">
    <?php foreach ($presets as $k => [$lab]): ?>
      <li class="nav-item">
        <a class="nav-link py-1 <?= $periode === $k ? 'active' : '' ?>"
           style="<?= $periode === $k ? 'background:#0E1C2B' : '' ?>"
           href="?id=<?= $id ?>&periode=<?= $k ?>"><?= $lab ?></a>
      </li>
    <?php endforeach; ?>
  </ul>
  <form method="get" class="d-flex gap-1 align-items-center ms-md-3">
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
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Chiffre d'affaires TTC</div>
      <div class="fs-3 fw-bold"><?= euro($kpis['ca_ttc']) ?></div>
      <div class="small">
        <?= badge_var(variation($kpis['ca_ttc'], $kpis['n1_ca_ttc'])) ?>
        <span class="text-muted">HT <?= euro($kpis['ca_ht']) ?></span>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Couverts</div>
      <div class="fs-3 fw-bold"><?= $kpis['couverts'] > 0 ? number_format($kpis['couverts'], 0, ',', ' ') : '—' ?></div>
      <div class="small">
        <?= $kpis['couverts'] > 0 ? badge_var(variation((float) $kpis['couverts'], $kpis['n1_couverts'] !== null ? (float) $kpis['n1_couverts'] : null)) : '' ?>
        <span class="text-muted">Panier moyen <?= euro($kpis['panier_moyen'], 2) ?></span>
      </div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Additions</div>
      <div class="fs-3 fw-bold"><?= $kpis['tickets'] > 0 ? number_format($kpis['tickets'], 0, ',', ' ') : '—' ?></div>
      <div class="small text-muted">Ticket moyen <?= euro($kpis['ticket_moyen'], 2) ?></div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">CA moyen par jour ouvert</div>
      <div class="fs-3 fw-bold"><?= euro($kpis['ca_par_jour']) ?></div>
      <div class="small text-muted">
        Solide <?= pct($kpis['ca_solide'], $kpis['ca_solide'] + $kpis['ca_liquide']) ?>
        &middot; Liquide <?= pct($kpis['ca_liquide'], $kpis['ca_solide'] + $kpis['ca_liquide']) ?>
      </div>
    </div></div>
  </div>
</div>

<?php if ($kpis['remises'] > 0 || $kpis['offerts'] > 0 || $kpis['annulations'] > 0 || $kpis['pourboires'] > 0): ?>
<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="card h-100 border-warning"><div class="card-body">
      <div class="text-muted small">Remises et offerts</div>
      <div class="fs-4 fw-bold"><?= euro($kpis['remises'] + $kpis['offerts']) ?></div>
      <div class="small text-muted"><?= pct($kpis['remises'] + $kpis['offerts'], $kpis['ca_ttc'] + $kpis['remises'] + $kpis['offerts']) ?> du CA brut</div>
    </div></div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Lignes annulées</div>
      <div class="fs-4 fw-bold"><?= $kpis['annulations'] ?></div>
      <?php if ($kpis['annulations_ttc'] > 0): ?>
        <div class="small text-muted"><?= euro($kpis['annulations_ttc'], 2) ?></div>
      <?php endif; ?>
    </div></div>
  </div>
  <?php if ($kpis['pourboires'] > 0): ?>
  <div class="col-6 col-lg-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Pourboires</div>
      <div class="fs-4 fw-bold"><?= euro($kpis['pourboires'], 2) ?></div>
    </div></div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<!-- CA par jour -->
<?php if (count($parJour) > 1): ?>
<div class="card mb-4">
  <div class="card-header">Chiffre d'affaires jour par jour</div>
  <div class="card-body"><canvas id="chartJours" height="70"></canvas></div>
</div>
<?php endif; ?>

<div class="row g-3 mb-4">
  <!-- Heures -->
  <?php if ($parHeure): ?>
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Ventes par heure</div>
      <div class="card-body"><canvas id="chartHeures" height="110"></canvas></div>
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
      <div class="card-body"><canvas id="chartCategories" height="220"></canvas></div>
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
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
<?php if (count($parJour) > 1): ?>
new Chart(document.getElementById('chartJours'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($j) => date('d/m', strtotime($j['jour'])), $parJour)) ?>,
    datasets: [
      { label: 'Solide', data: <?= json_encode(array_map(fn($j) => (float) $j['ca_solide'], $parJour)) ?>, backgroundColor: '#2D6A4F', stack: 'ca' },
      { label: 'Liquide', data: <?= json_encode(array_map(fn($j) => (float) $j['ca_liquide'], $parJour)) ?>, backgroundColor: '#2A78D6', stack: 'ca' },
      { label: 'Autre / non réparti', data: <?= json_encode(array_map(
          fn($j) => max(0, round((float) $j['ca_ttc'] - (float) $j['ca_solide'] - (float) $j['ca_liquide'], 2)), $parJour)) ?>,
        backgroundColor: '#AAB4C4', stack: 'ca' }
    ]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } },
             scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true } } }
});
<?php endif; ?>

<?php if ($parHeure): ?>
new Chart(document.getElementById('chartHeures'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($h) => $h['heure'] . 'h', $parHeure)) ?>,
    datasets: [{ label: 'CA TTC', data: <?= json_encode(array_map(fn($h) => (float) $h['ttc'], $parHeure)) ?>,
                 backgroundColor: '#C8A84B', borderRadius: 4 }]
  },
  options: { responsive: true, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
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
          $palette = ['#0E1C2B', '#C8A84B', '#2D6A4F', '#2A78D6', '#8E5572', '#E07A5F', '#3D405B', '#81B29A', '#F2CC8F', '#6B7280', '#B5838D', '#457B9D'];
          return $palette[$i % count($palette)];
      }, $categories, array_keys($categories))) ?>
    }]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 12 } } } }
});
<?php endif; ?>
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
