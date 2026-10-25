<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/financials.php';
require_once __DIR__ . '/../inc/daily.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'dashboard');
$canEdit = can_edit_project($id);

// Les restaurants ont un bloc d'indicateurs quotidiens en plus du suivi mensuel.
$estRestaurant = is_restaurant($project);
$kpisResto     = $estRestaurant ? get_restaurant_kpis($id) : null;
$joursResto    = $estRestaurant ? get_recent_days($id, 7) : [];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_entry') {
    require_project_edit($id); // bloque un POST forgé par un utilisateur en lecture seule

    $period   = $_POST['period_date'] ?? '';
    $revenus  = (float) str_replace(',', '.', $_POST['revenus'] ?? '0');
    $charges  = (float) str_replace(',', '.', $_POST['charges'] ?? '0');
    $notes    = trim($_POST['notes'] ?? '') ?: null;

    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        $errors[] = "Choisis un mois valide.";
    } else {
        upsert_financial_entry($id, $period . '-01', $revenus, $charges, $notes);
        header("Location: /pages/project_dashboard.php?id={$id}&saved=1");
        exit;
    }
}

$entries = get_financial_entries($id);
$latest  = get_latest_entry($id);
$ytd     = get_ytd_summary($id, (int) date('Y'));

$latestNet = $latest ? ((float) $latest['revenus'] - (float) $latest['charges']) : null;
$ytdNet    = (float) $ytd['revenus'] - (float) $ytd['charges'];

// Données pour le graphique (Chart.js)
$chartLabels  = array_map(fn($e) => date('M Y', strtotime($e['period_date'])), $entries);
$chartRevenus = array_map(fn($e) => (float) $e['revenus'], $entries);
$chartCharges = array_map(fn($e) => (float) $e['charges'], $entries);
$chartNet     = array_map(fn($e) => (float) $e['revenus'] - (float) $e['charges'], $entries);

$pageTitle = $project['name'] . ' — Dashboard';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
  <div>
    <?php if ($estRestaurant): ?>
      <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>" class="btn btn-sm btn-dark">Saisie quotidienne</a>
      <a href="/pages/evolution.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Évolution</a>
      <a href="/pages/analyse_caisse.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Analyse des ventes</a>
    <?php endif; ?>
    <a href="/pages/project_detail.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Fiche</a>
    <a href="/pages/charges.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Charges</a>
    <a href="/pages/project_documents.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Documents</a>
  </div>
</div>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Saisie enregistrée.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<?php if ($estRestaurant): ?>
  <h5 class="text-muted mb-3">Activité quotidienne</h5>
  <div class="row mb-4">
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">
          CA <?= $kpisResto['jour'] ? date('d/m', strtotime($kpisResto['jour']['entry_date'])) : '' ?>
        </div>
        <div class="fs-4 fw-bold">
          <?= $kpisResto['jour']
              ? number_format((float) $kpisResto['jour']['ca_ttc'], 0, ',', ' ') . ' €'
              : '<span class="text-muted fs-6">Pas de saisie</span>' ?>
        </div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Couverts du jour</div>
        <div class="fs-4 fw-bold">
          <?= $kpisResto['jour'] ? (int) $kpisResto['jour']['couverts'] : '—' ?>
        </div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">Ticket moyen du jour</div>
        <div class="fs-4 fw-bold">
          <?= $kpisResto['jour_ticket'] !== null
              ? number_format($kpisResto['jour_ticket'], 2, ',', ' ') . ' €'
              : '—' ?>
        </div>
      </div></div>
    </div>
    <div class="col-md-3">
      <div class="card h-100"><div class="card-body">
        <div class="text-muted small">CA du mois (<?= $kpisResto['mois_jours_saisis'] ?> j. saisis)</div>
        <div class="fs-4 fw-bold"><?= number_format($kpisResto['mois_ca'], 0, ',', ' ') ?> €</div>
        <div class="text-muted small">
          Ticket moyen :
          <?= $kpisResto['mois_ticket'] !== null
              ? number_format($kpisResto['mois_ticket'], 2, ',', ' ') . ' €'
              : '—' ?>
        </div>
      </div></div>
    </div>
  </div>

  <?php if ($joursResto): ?>
    <div class="card mb-4">
      <div class="card-header">Chiffre d'affaires — 7 derniers jours saisis</div>
      <div class="card-body">
        <canvas id="chartResto" height="80"></canvas>
      </div>
    </div>
  <?php else: ?>
    <div class="alert alert-secondary">
      Aucune donnée quotidienne pour l'instant.
      <?php if ($canEdit): ?>
        <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>">Commencer la saisie</a>.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <h5 class="text-muted mb-3">Suivi mensuel</h5>
<?php endif; ?>

<div class="row mb-4">
  <div class="col-md-4">
    <div class="card text-center h-100">
      <div class="card-body">
        <div class="text-muted small">Résultat net — dernier mois saisi</div>
        <?php if ($latestNet === null): ?>
          <div class="fs-4 text-muted">Pas de donnée</div>
        <?php else: ?>
          <div class="display-6 <?= $latestNet >= 0 ? 'text-success' : 'text-danger' ?>">
            <?= number_format($latestNet, 0, ',', ' ') ?> €
          </div>
          <span class="badge <?= $latestNet >= 0 ? 'bg-success' : 'bg-danger' ?>">
            <?= $latestNet >= 0 ? 'Rentable' : 'Non rentable' ?>
          </span>
          <div class="text-muted small mt-1"><?= date('F Y', strtotime($latest['period_date'])) ?></div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center h-100">
      <div class="card-body">
        <div class="text-muted small">Résultat net cumulé — <?= date('Y') ?></div>
        <div class="display-6 <?= $ytdNet >= 0 ? 'text-success' : 'text-danger' ?>">
          <?= number_format($ytdNet, 0, ',', ' ') ?> €
        </div>
      </div>
    </div>
  </div>
  <div class="col-md-4">
    <div class="card text-center h-100">
      <div class="card-body">
        <div class="text-muted small">Revenus / Charges — cumul <?= date('Y') ?></div>
        <div class="fs-5 mt-2">
          <span class="text-success"><?= number_format((float) $ytd['revenus'], 0, ',', ' ') ?> €</span>
          /
          <span class="text-danger"><?= number_format((float) $ytd['charges'], 0, ',', ' ') ?> €</span>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">Évolution mensuelle</div>
  <div class="card-body">
    <?php if (!$entries): ?>
      <p class="text-muted mb-0">Pas encore de données à afficher — ajoute une première saisie ci-dessous.</p>
    <?php else: ?>
      <canvas id="chartEvolution" height="90"></canvas>
    <?php endif; ?>
  </div>
</div>

<div class="row">
  <?php if ($canEdit): ?>
  <div class="col-md-5">
    <div class="card mb-4">
      <div class="card-header">Ajouter / modifier une saisie mensuelle</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_entry">
          <div class="col-12">
            <label class="form-label">Mois</label>
            <input type="month" name="period_date" class="form-control" value="<?= date('Y-m') ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label">Revenus (€)</label>
            <input type="text" name="revenus" class="form-control" placeholder="ex: 4200">
          </div>
          <div class="col-6">
            <label class="form-label">Charges (€)</label>
            <input type="text" name="charges" class="form-control" placeholder="ex: 2600">
          </div>
          <div class="col-12">
            <label class="form-label">Notes (optionnel)</label>
            <input type="text" name="notes" class="form-control">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-dark">Enregistrer</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-md-<?= $canEdit ? '7' : '12' ?>">
    <div class="card mb-4">
      <div class="card-header">Historique</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <thead>
            <tr><th>Mois</th><th>Revenus</th><th>Charges</th><th>Résultat net</th></tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($entries) as $e):
              $net = (float) $e['revenus'] - (float) $e['charges']; ?>
              <tr>
                <td><?= date('m/Y', strtotime($e['period_date'])) ?></td>
                <td><?= number_format((float) $e['revenus'], 0, ',', ' ') ?> €</td>
                <td><?= number_format((float) $e['charges'], 0, ',', ' ') ?> €</td>
                <td class="<?= $net >= 0 ? 'text-success' : 'text-danger' ?>">
                  <?= number_format($net, 0, ',', ' ') ?> €
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$entries): ?>
              <tr><td colspan="4" class="text-center text-muted">Aucune saisie.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if ($entries || $joursResto): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<?php endif; ?>

<?php if ($joursResto): ?>
<script>
new Chart(document.getElementById('chartResto'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(
      fn($j) => date('d/m', strtotime($j['entry_date'])),
      $joursResto
    )) ?>,
    datasets: [{
      label: 'CA TTC',
      data: <?= json_encode(array_map(fn($j) => (float) $j['ca_ttc'], $joursResto)) ?>,
      backgroundColor: '#2A78D6',
      borderRadius: 4
    }]
  },
  options: { responsive: true, plugins: { legend: { display: false } } }
});
</script>
<?php endif; ?>

<?php if ($entries): ?>
<script>
new Chart(document.getElementById('chartEvolution'), {
  type: 'line',
  data: {
    labels: <?= json_encode($chartLabels) ?>,
    datasets: [
      { label: 'Revenus', data: <?= json_encode($chartRevenus) ?>, borderColor: '#2D6A4F', backgroundColor: 'transparent', tension: 0.2 },
      { label: 'Charges', data: <?= json_encode($chartCharges) ?>, borderColor: '#C8A84B', backgroundColor: 'transparent', tension: 0.2 },
      { label: 'Résultat net', data: <?= json_encode($chartNet) ?>, borderColor: '#0E1C2B', backgroundColor: 'transparent', borderDash: [6,4], tension: 0.2 }
    ]
  },
  options: { responsive: true, plugins: { legend: { position: 'bottom' } } }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
