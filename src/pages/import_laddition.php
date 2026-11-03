<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/export_laddition.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

/**
 * Import de l'export Excel « Détails par service » de L'Addition, pour un restaurant.
 * Étape 1 : dépôt du fichier et aperçu. Étape 2 : confirmation, écriture des journées.
 * Réutilisable à chaque nouvel export : les journées déjà importées sont mises à jour.
 */

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);
if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}
require_project_access($id, 'dashboard');
require_project_edit($id);

$cleSession = 'import_laddition_' . $id;
$erreurs = [];
$apercu  = $_SESSION[$cleSession] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!csrf_valide()) {
        $erreurs[] = "La page a expiré, recommencez.";
    } elseif ($action === 'analyser') {
        $apercu = null;
        unset($_SESSION[$cleSession]);
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $erreurs[] = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? "Le fichier est trop lourd pour le serveur."
                : "Choisissez le fichier Excel exporté depuis L'Addition.";
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $erreurs[] = "Le fichier doit être au format .xlsx.";
        } else {
            try {
                $res = parser_export_laddition(xlsxRows(file_get_contents($f['tmp_name'])));
                if ($res['erreurs']) {
                    $erreurs = $res['erreurs'];
                } else {
                    $res['fichier'] = basename($f['name']);
                    $_SESSION[$cleSession] = $apercu = $res;
                }
            } catch (Throwable $e) {
                $erreurs[] = "Lecture impossible : " . $e->getMessage();
            }
        }
    } elseif ($action === 'confirmer' && $apercu) {
        $r = importer_export_laddition($id, $apercu['jours'], (int) current_user()['id'], !empty($_POST['remplacer_manuel']));
        unset($_SESSION[$cleSession]);
        $_SESSION[$cleSession . '_ok'] = $r;
        header("Location: /pages/import_laddition.php?id={$id}&ok=1");
        exit;
    } elseif ($action === 'annuler') {
        unset($_SESSION[$cleSession]);
        $apercu = null;
    }
}

$resultat = isset($_GET['ok']) ? ($_SESSION[$cleSession . '_ok'] ?? null) : null;
unset($_SESSION[$cleSession . '_ok']);

// Aperçu : totaux du fichier et journées déjà présentes pour CE restaurant
$stats = null;
if ($apercu) {
    $jours = $apercu['jours'];
    $dates = array_keys($jours);
    $existantes = [];
    $stmt = get_db()->prepare(
        "SELECT entry_date, source FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :du AND :au"
    );
    $stmt->execute(['pid' => $id, 'du' => $dates[0], 'au' => end($dates)]);
    foreach ($stmt->fetchAll() as $e) {
        if (isset($jours[$e['entry_date']])) $existantes[$e['entry_date']] = $e['source'];
    }
    $ca  = array_sum(array_column($jours, 'ca_ttc'));
    $cv  = array_sum(array_column($jours, 'couverts'));
    $cmd = array_sum(array_column($jours, 'commandes'));
    $stats = [
        'du' => $dates[0], 'au' => end($dates), 'nb' => count($jours),
        'ca' => $ca, 'couverts' => $cv,
        'panier' => $cv > 0 ? $ca / $cv : null,
        'ticket' => $cmd > 0 ? $ca / $cmd : null,
        'manuelles' => array_keys(array_filter($existantes, fn($s) => $s === 'manuel')),
        'maj' => count(array_filter($existantes, fn($s) => $s !== 'manuel')),
    ];
}

$pageTitle = $project['name'] . " — Import L'Addition";
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'saisie',
      '<a href="/pages/saisie_quotidienne.php?id=' . $id . '" class="btn btn-sm btn-outline-dark">Saisie quotidienne</a>') ?>

<h5 class="text-muted mb-3">Import d'un export L'Addition</h5>

<?php foreach ($erreurs as $e): ?><div class="alert alert-danger"><?= h($e) ?></div><?php endforeach; ?>

<?php if ($resultat): ?>
  <div class="alert alert-success">
    Import terminé : <strong><?= (int) $resultat['ecrites'] ?></strong> journées enregistrées.
    <?php if ($resultat['conservees']): ?>
      <?= count($resultat['conservees']) ?> journées saisies à la main ont été conservées telles quelles.
    <?php endif; ?>
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>">Voir la saisie quotidienne</a>
  </div>
<?php endif; ?>

<?php if (!$apercu): ?>
  <div class="card mb-4">
    <div class="card-body">
      <form method="post" enctype="multipart/form-data">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="analyser">
        <label class="form-label">Fichier Excel « Détails par service »</label>
        <input type="file" name="fichier" accept=".xlsx" class="form-control mb-2" required>
        <div class="form-text mb-3">
          Dans le back-office L'Addition : Statistiques → Détails par service, choisir la période,
          puis exporter en Excel. Vous pouvez reprendre une période déjà importée : les journées
          seront mises à jour.
        </div>
        <button type="submit" class="btn btn-dark">Analyser le fichier</button>
      </form>
    </div>
  </div>
<?php else: ?>
  <div class="card border-dark mb-4">
    <div class="card-header bg-dark text-white">
      Vérification avant enregistrement — <?= h($apercu['fichier']) ?>
    </div>
    <div class="card-body">
      <?php if ($apercu['etablissement'] && stripos($project['name'], $apercu['etablissement']) === false): ?>
        <div class="alert alert-warning">
          Le fichier concerne l'établissement « <?= h($apercu['etablissement']) ?> » dans L'Addition.
          Vérifiez qu'il s'agit bien de <?= h($project['name']) ?> avant de confirmer.
        </div>
      <?php endif; ?>

      <table class="table table-sm mb-3">
        <tr><td class="text-muted" style="width:40%">Période</td>
            <td>du <?= date('d/m/Y', strtotime($stats['du'])) ?> au <?= date('d/m/Y', strtotime($stats['au'])) ?></td></tr>
        <tr><td class="text-muted">Journées avec du chiffre d'affaires</td><td class="fw-bold"><?= $stats['nb'] ?></td></tr>
        <tr><td class="text-muted">Chiffre d'affaires TTC</td><td class="fw-bold"><?= eur($stats['ca']) ?></td></tr>
        <tr><td class="text-muted">Couverts</td><td><?= number_format($stats['couverts'], 0, ',', ' ') ?></td></tr>
        <tr><td class="text-muted">Panier moyen (par couvert)</td><td><?= eur($stats['panier'], 2) ?></td></tr>
        <tr><td class="text-muted">Ticket moyen (par addition)</td><td><?= eur($stats['ticket'], 2) ?></td></tr>
        <tr><td class="text-muted">Journées déjà présentes, mises à jour</td><td><?= $stats['maj'] ?></td></tr>
      </table>

      <?php if ($apercu['ignores']): ?>
        <p class="small text-muted">
          Lignes ignorées :
          <?= h(implode(', ', array_map(fn($i) => date('d/m/Y', strtotime($i[0])) . ' (' . $i[1] . ')', $apercu['ignores']))) ?>.
        </p>
      <?php endif; ?>

      <form method="post" class="d-flex flex-wrap gap-3 align-items-center">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="confirmer">
        <?php if ($stats['manuelles']): ?>
          <div class="form-check w-100">
            <input class="form-check-input" type="checkbox" name="remplacer_manuel" value="1" id="rm">
            <label class="form-check-label" for="rm">
              Remplacer aussi les <?= count($stats['manuelles']) ?> journées saisies à la main
              (sinon elles sont conservées)
            </label>
          </div>
        <?php endif; ?>
        <button type="submit" class="btn btn-dark">Enregistrer <?= $stats['nb'] ?> journées</button>
      </form>
      <form method="post" class="mt-2">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="annuler">
        <button type="submit" class="btn btn-link px-0">Annuler</button>
      </form>
    </div>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
