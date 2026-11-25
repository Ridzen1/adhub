<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/biens.php';
require_once __DIR__ . '/../inc/ui.php';
require_direction();

/**
 * Biens et prêts : la direction dépose le fichier Excel « Suivi des emprunts »,
 * puis vérifie à quelle société ADHUB correspond chaque nom du fichier.
 */

$erreur   = null;
$resultat = $_SESSION['biens_resultat'] ?? null;
unset($_SESSION['biens_resultat']);

if (!biens_tables_pretes()) {
    $pageTitle = 'Biens et prêts';
    require __DIR__ . '/../inc/layout_header.php';
    echo page_header('Biens et prêts', '', '', 'Administration');
    echo '<div class="alert alert-warning">Passez d\'abord la migration <code>027_biens_prets.sql</code> '
       . 'dans phpMyAdmin (base de production), puis rechargez cette page.</div>';
    require __DIR__ . '/../inc/layout_footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $erreur = "La page a expiré, recommencez.";
    } elseif (($_POST['action'] ?? '') === 'importer') {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $erreur = "Choisissez le fichier Excel de suivi des emprunts.";
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $erreur = "Le fichier doit être au format .xlsx.";
        } else {
            try {
                $_SESSION['biens_resultat'] = biens_importer_xlsx(
                    file_get_contents($f['tmp_name']),
                    basename($f['name']),
                    (int) current_user()['id']
                );
                header('Location: /pages/admin_biens.php');
                exit;
            } catch (Throwable $e) {
                $erreur = "Import impossible : " . $e->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'correspondances') {
        foreach ((array) ($_POST['projet'] ?? []) as $societe => $projectId) {
            biens_definir_correspondance((string) $societe, (int) $projectId ?: null);
        }
        header('Location: /pages/admin_biens.php?enregistre=1');
        exit;
    }
}

biens_relier_automatiquement();
$correspondances = biens_correspondances();
$import   = biens_dernier_import();
$projets  = get_db()->query("SELECT id, name FROM projects WHERE status = 'active' ORDER BY name")->fetchAll();
$nonRelies = count(array_filter($correspondances, fn($c) => $c['project_id'] === null));
$nomsProjets = array_column($projets, 'name', 'id');

$prets = get_db()->query(
    "SELECT pr.*, bs.project_id FROM prets pr LEFT JOIN biens_societes bs ON bs.societe = pr.societe ORDER BY pr.ref"
)->fetchAll();
$totalCrd = 0.0;
foreach ($prets as &$p) {
    $p['situation'] = pret_situation($p);
    $totalCrd += (float) ($p['situation']['crd'] ?? 0);
}
unset($p);
$aConfirmer = array_filter($prets, fn($p) => (int) $p['a_confirmer'] === 1);

$pageTitle = 'Biens et prêts';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Biens et prêts', "Déposez le fichier Excel de suivi des emprunts : les biens et les prêts alimentent l'onglet « Biens et prêts » de chaque société et la page Patrimoine.", '', 'Administration') ?>

<?php if ($erreur): ?><div class="alert alert-danger"><?= h($erreur) ?></div><?php endif; ?>
<?php if (isset($_GET['enregistre'])): ?><div class="alert alert-success">Correspondances enregistrées.</div><?php endif; ?>
<?php if ($resultat): ?>
  <div class="alert alert-success">
    Import terminé : <strong><?= (int) $resultat['biens'] ?></strong> biens et <strong><?= (int) $resultat['prets'] ?></strong> prêts.
    <?php if ($resultat['retires']): ?><?= (int) $resultat['retires'] ?> ligne(s) absente(s) du fichier ont été retirées.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Déposer le fichier</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-sm-row gap-2">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="importer">
          <input type="file" name="fichier" accept=".xlsx" class="form-control" required>
          <button class="btn btn-dark text-nowrap"><?= icon('plus', 14) ?> Importer</button>
        </form>
        <p class="text-muted small mt-3 mb-0">
          Feuilles lues : « Biens », « Prêts » et « Restaurants ». Chaque ligne est reconnue par son identifiant
          (B01, P01, R01…) : redéposer le fichier met tout à jour sans doublon, et une ligne supprimée du fichier
          disparaît d'ADHUB. Le capital restant dû est recalculé chaque mois à partir du taux et de la mensualité.
        </p>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Dernier import</div>
      <div class="card-body">
        <?php if ($import): ?>
          <div class="fw-semibold"><?= date('d/m/Y H:i', strtotime($import['imported_at'])) ?></div>
          <div class="text-muted small"><?= h($import['fichier'] ?? '') ?> · <?= h($import['user_name'] ?? '') ?></div>
          <div class="mt-2"><?= (int) $import['nb_biens'] ?> biens · <?= (int) $import['nb_prets'] ?> prêts</div>
          <div class="mt-1">Capital restant dû aujourd'hui : <strong><?= eur($totalCrd) ?></strong></div>
        <?php else: ?>
          <p class="text-muted mb-0">Aucun import pour l'instant.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">
    <span>Sociétés du fichier ↔ sociétés ADHUB</span>
    <?php if ($nonRelies): ?><span class="badge bg-warning text-dark"><?= $nonRelies ?> à relier</span><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="correspondances">
      <div class="table-responsive">
        <table class="table mb-0 align-middle">
          <thead>
            <tr><th>Nom dans le fichier</th><th class="text-end">Biens</th><th class="text-end">Prêts</th><th style="min-width:220px">Société ADHUB</th></tr>
          </thead>
          <tbody>
            <?php foreach ($correspondances as $c): ?>
              <tr>
                <td class="fw-semibold"><?= h($c['societe']) ?></td>
                <td class="text-end"><?= (int) $c['nb_biens'] ?></td>
                <td class="text-end"><?= (int) $c['nb_prets'] ?></td>
                <td>
                  <select name="projet[<?= h($c['societe']) ?>]" class="form-select form-select-sm <?= $c['project_id'] === null ? 'border-warning' : '' ?>">
                    <option value="">— Non reliée —</option>
                    <?php foreach ($projets as $pr): ?>
                      <option value="<?= (int) $pr['id'] ?>" <?= (int) $c['project_id'] === (int) $pr['id'] ? 'selected' : '' ?>><?= h($pr['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$correspondances): ?>
              <tr><td colspan="4" class="text-center text-muted py-4">Les sociétés apparaîtront ici après le premier import.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($correspondances): ?>
        <div class="p-3 border-top d-flex justify-content-between align-items-center gap-3 flex-wrap">
          <span class="text-muted small">Les noms évidents sont reliés d'office ; vérifiez ceux en orange.
            Les biens en nom propre se relient à la fiche « ARNAUD DUVIVIER ».</span>
          <button class="btn btn-dark btn-sm">Enregistrer</button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php if ($prets): ?>
<div class="card mb-4">
  <div class="card-header">
    Tous les prêts
    <span class="card-hint"><?= count($prets) ?> prêts<?= $aConfirmer ? ' · ' . count($aConfirmer) . ' rattachement(s) à confirmer' : '' ?></span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th>Prêt</th><th>Société</th><th>Banque</th><th class="text-end">Taux</th>
            <th class="text-end">Mensualité</th><th class="text-end">Restant dû</th><th class="text-end">Fin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prets as $p): $s = $p['situation']; ?>
            <tr>
              <td>
                <span class="fw-semibold"><?= h($p['ref']) ?></span>
                <div class="text-muted small"><?= h($p['objet'] ?? '') ?></div>
              </td>
              <td>
                <?= $p['project_id'] ? h($nomsProjets[(int) $p['project_id']] ?? $p['societe']) : '<span class="text-warning">' . h($p['societe']) . '</span>' ?>
                <?php if ((int) $p['a_confirmer']): ?><span class="badge bg-warning text-dark">à confirmer</span><?php endif; ?>
              </td>
              <td><?= h($p['banque'] ?? '') ?></td>
              <td class="text-end num"><?= $p['taux'] !== null ? pourcent((float) $p['taux'] * 100, 2) : '—' ?></td>
              <td class="text-end num"><?= $s['mensualite'] !== null ? eur($s['mensualite'], 2) : '—' ?></td>
              <td class="text-end fw-semibold num"><?= $s['crd'] !== null ? eur($s['crd']) : '—' ?></td>
              <td class="text-end num"><?= $p['date_fin'] ? date('m/Y', strtotime($p['date_fin'])) . ($p['date_fin_estimee'] ? '*' : '') : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
