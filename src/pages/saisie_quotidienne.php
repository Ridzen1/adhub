<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
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

$errors = [];
$user   = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_project_edit($id);
    $action = $_POST['action'] ?? '';

    if ($action === 'save_day') {
        $date     = $_POST['entry_date'] ?? '';
        $caRaw    = trim($_POST['ca_ttc'] ?? '');
        $cvRaw    = trim($_POST['couverts'] ?? '');
        $notes    = trim($_POST['notes'] ?? '') ?: null;

        $ca = (float) str_replace([' ', ','], ['', '.'], $caRaw);
        $cv = (int) $cvRaw;

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
            $errors[] = "Date invalide.";
        }
        if ($date > date('Y-m-d')) {
            $errors[] = "On ne peut pas saisir une date future.";
        }
        if ($ca < 0 || $cv < 0) {
            $errors[] = "Le chiffre d'affaires et les couverts ne peuvent pas être négatifs.";
        }

        if (!$errors) {
            upsert_daily_entry($id, $date, $ca, $cv, $notes, 'manuel', $user['id']);
            header("Location: /pages/saisie_quotidienne.php?id={$id}&saved=1");
            exit;
        }
    }

    if ($action === 'delete_day') {
        delete_daily_entry($id, $_POST['entry_date'] ?? '');
        header("Location: /pages/saisie_quotidienne.php?id={$id}&deleted=1");
        exit;
    }
}

// Mois affiché : celui demandé, sinon le mois courant
$year  = (int) ($_GET['y'] ?? date('Y'));
$month = (int) ($_GET['m'] ?? date('n'));
if ($month < 1 || $month > 12) { $month = (int) date('n'); }

$entries  = get_month_entries($id, $year, $month);
$summary  = get_month_summary($id, $year, $month);
$missing  = get_missing_days($id, $year, $month);

// Pré-remplissage : la date la plus récente non encore saisie, sinon aujourd'hui
$defaultDate = date('Y-m-d');
if (!get_daily_entry($id, date('Y-m-d', strtotime('-1 day')))) {
    $defaultDate = date('Y-m-d', strtotime('-1 day'));
}

$moisLabel = format_mois_fr($year, $month);

$prevMonth = $month === 1 ? 12 : $month - 1;
$prevYear  = $month === 1 ? $year - 1 : $year;
$nextMonth = $month === 12 ? 1 : $month + 1;
$nextYear  = $month === 12 ? $year + 1 : $year;

$pageTitle = $project['name'] . ' — Saisie quotidienne';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
  <div>
    <a href="/pages/import_caisse.php?id=<?= $id ?>" class="btn btn-sm btn-dark">Importer un rapport</a>
    <a href="/pages/project_dashboard.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Dashboard</a>
    <a href="/pages/project_documents.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Documents</a>
  </div>
</div>

<?php if (isset($_GET['saved'])): ?><div class="alert alert-success">Journée enregistrée.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-secondary">Journée supprimée.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($canEdit): ?>
  <div class="card mb-4">
    <div class="card-header">Saisir une journée</div>
    <div class="card-body">
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="action" value="save_day">
        <div class="col-md-3">
          <label class="form-label">Date</label>
          <input type="date" name="entry_date" class="form-control"
                 value="<?= $defaultDate ?>" max="<?= date('Y-m-d') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Chiffre d'affaires TTC (€)</label>
          <input type="text" name="ca_ttc" class="form-control" placeholder="ex : 1850" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Couverts</label>
          <input type="number" name="couverts" class="form-control" min="0" placeholder="ex : 66" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Note (optionnel)</label>
          <input type="text" name="notes" class="form-control" placeholder="ex : soirée privatisée">
        </div>
        <div class="col-md-1">
          <button type="submit" class="btn btn-dark w-100">Valider</button>
        </div>
      </form>
      <div class="form-text mt-2">
        Le ticket moyen se calcule tout seul. Saisir une date déjà remplie remplace la valeur précédente.
      </div>
    </div>
  </div>
<?php endif; ?>

<div class="row mb-4">
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">CA du mois</div>
      <div class="fs-4 fw-bold"><?= number_format((float) $summary['ca_total'], 0, ',', ' ') ?> €</div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Couverts</div>
      <div class="fs-4 fw-bold"><?= number_format((int) $summary['couverts_total'], 0, ',', ' ') ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Ticket moyen</div>
      <?php $tm = ticket_moyen((float) $summary['ca_total'], (int) $summary['couverts_total']); ?>
      <div class="fs-4 fw-bold"><?= $tm !== null ? number_format($tm, 2, ',', ' ') . ' €' : '—' ?></div>
    </div></div>
  </div>
  <div class="col-md-3">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Jours saisis</div>
      <div class="fs-4 fw-bold"><?= (int) $summary['jours_saisis'] ?></div>
      <?php if ($missing): ?>
        <div class="text-danger small"><?= count($missing) ?> jour(s) manquant(s)</div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<?php if ($missing): ?>
  <div class="alert alert-warning">
    <strong>Journées non saisies :</strong>
    <?= implode(', ', array_map(fn($d) => date('d/m', strtotime($d)), $missing)) ?>.
    <span class="small">S'il s'agit de jours de fermeture, saisissez 0 pour les marquer comme traités.</span>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Historique — <?= htmlspecialchars($moisLabel) ?></span>
    <span>
      <a class="btn btn-sm btn-outline-secondary"
         href="?id=<?= $id ?>&y=<?= $prevYear ?>&m=<?= $prevMonth ?>">&larr; Mois précédent</a>
      <a class="btn btn-sm btn-outline-secondary"
         href="?id=<?= $id ?>&y=<?= $nextYear ?>&m=<?= $nextMonth ?>">Mois suivant &rarr;</a>
    </span>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th>Date</th>
          <th class="text-end">CA TTC</th>
          <th class="text-end">Couverts</th>
          <th class="text-end">Ticket moyen</th>
          <th>Source</th>
          <th>Note</th>
          <?php if ($canEdit): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($entries) as $e):
          $t = ticket_moyen((float) $e['ca_ttc'], (int) $e['couverts']);
          $anomalie = detecter_anomalie($id, $e['entry_date'], (float) $e['ca_ttc']); ?>
          <tr class="<?= $anomalie ? 'table-warning' : '' ?>">
            <td>
              <?= date('d/m/Y', strtotime($e['entry_date'])) ?>
              <?php if ($anomalie): ?>
                <span class="badge bg-warning text-dark ms-1"
                      title="<?= htmlspecialchars(libelle_anomalie($anomalie)) ?>">à vérifier</span>
              <?php endif; ?>
            </td>
            <td class="text-end"><?= number_format((float) $e['ca_ttc'], 2, ',', ' ') ?> €</td>
            <td class="text-end"><?= (int) $e['couverts'] ?></td>
            <td class="text-end"><?= $t !== null ? number_format($t, 2, ',', ' ') . ' €' : '—' ?></td>
            <td>
              <span class="badge <?= $e['source'] === 'manuel' ? 'bg-secondary' : 'bg-info text-dark' ?>">
                <?= htmlspecialchars($e['source']) ?>
              </span>
            </td>
            <td class="small text-muted"><?= htmlspecialchars($e['notes'] ?? '') ?></td>
            <?php if ($canEdit): ?>
              <td class="text-end">
                <form method="post" onsubmit="return confirm('Supprimer cette journée ?');">
                  <input type="hidden" name="action" value="delete_day">
                  <input type="hidden" name="entry_date" value="<?= htmlspecialchars($e['entry_date']) ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">×</button>
                </form>
              </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        <?php if (!$entries): ?>
          <tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="text-center text-muted py-3">
            Aucune saisie pour ce mois.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
