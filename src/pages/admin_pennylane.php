<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/pennylane.php';
require_once __DIR__ . '/../inc/ui.php';
require_direction();

/**
 * Import manuel des données Pennylane : la direction dépose l'export Excel,
 * puis relie chaque société Pennylane à sa société ADHUB.
 */

$erreur = null;
$resultat = $_SESSION['pennylane_resultat'] ?? null;
unset($_SESSION['pennylane_resultat']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $erreur = "La page a expiré, recommencez.";
    } elseif (($_POST['action'] ?? '') === 'importer') {
        $f = $_FILES['fichier'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            $erreur = $f && in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
                ? "Le fichier est trop lourd pour le serveur."
                : "Choisissez le fichier Excel exporté depuis Pennylane.";
        } elseif (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            $erreur = "Le fichier doit être au format .xlsx.";
        } else {
            try {
                $_SESSION['pennylane_resultat'] = pennylane_importer_xlsx(
                    file_get_contents($f['tmp_name']),
                    basename($f['name']),
                    (int) current_user()['id']
                );
                header('Location: /pages/admin_pennylane.php?importe=1');
                exit;
            } catch (Throwable $e) {
                $erreur = "Import impossible : " . $e->getMessage();
            }
        }
    } elseif (($_POST['action'] ?? '') === 'correspondances') {
        foreach ((array) ($_POST['projet'] ?? []) as $societe => $projectId) {
            pennylane_definir_correspondance((string) $societe, (int) $projectId ?: null);
        }
        header('Location: /pages/admin_pennylane.php?enregistre=1');
        exit;
    }
}

// Données arrivées par le script SQL (phpMyAdmin) : on les rattache aussi
pennylane_relier_automatiquement();

$correspondances = pennylane_correspondances();
$imports  = pennylane_derniers_imports(8);
$projets  = get_db()->query("SELECT id, name FROM projects WHERE status = 'active' ORDER BY name")->fetchAll();
$nonRelies = count(array_filter($correspondances, fn($c) => $c['project_id'] === null && ($c['nb_factures'] + $c['nb_transactions']) > 0));

$pageTitle = 'Import Pennylane';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Import Pennylane', "Déposez l'export Excel de Pennylane : les factures et les opérations bancaires alimentent l'onglet Comptabilité de chaque société.", '', 'Administration') ?>

<?php if ($erreur): ?><div class="alert alert-danger"><?= h($erreur) ?></div><?php endif; ?>
<?php if (isset($_GET['enregistre'])): ?><div class="alert alert-success">Correspondances enregistrées.</div><?php endif; ?>
<?php if ($resultat): ?>
  <div class="alert alert-success">
    Import terminé : <strong><?= number_format($resultat['factures'], 0, ',', ' ') ?></strong> factures et
    <strong><?= number_format($resultat['transactions'], 0, ',', ' ') ?></strong> opérations bancaires
    pour <?= count($resultat['societes']) ?> sociétés
    <?php if ($resultat['du']): ?>(du <?= date('d/m/Y', strtotime($resultat['du'])) ?> au <?= date('d/m/Y', strtotime($resultat['au'])) ?>)<?php endif; ?>.
    <?php if ($resultat['ignorees']): ?><?= (int) $resultat['ignorees'] ?> lignes sans identifiant Pennylane ont été ignorées.<?php endif; ?>
  </div>
<?php endif; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Déposer un export</div>
      <div class="card-body">
        <form method="post" enctype="multipart/form-data" class="d-flex flex-column flex-sm-row gap-2">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="importer">
          <input type="file" name="fichier" accept=".xlsx" class="form-control" required>
          <button class="btn btn-dark text-nowrap"><?= icon('plus', 14) ?> Importer</button>
        </form>
        <p class="text-muted small mt-3 mb-0">
          Le fichier doit contenir les feuilles « Factures fournisseurs », « Factures clients » et
          « Transactions bancaires ». Réimporter un export plus récent met les lignes à jour, sans doublon :
          chaque ligne est reconnue par son identifiant Pennylane.
          Le script SQL généré avec l'export peut aussi être passé dans phpMyAdmin, le résultat est le même.
        </p>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Derniers imports</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0">
          <tbody>
            <?php foreach ($imports as $i): ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= date('d/m/Y H:i', strtotime($i['imported_at'])) ?></div>
                  <div class="text-muted small"><?= h($i['user_name'] ?? '') ?></div>
                </td>
                <td class="text-end small">
                  <?= number_format((int) $i['nb_factures'], 0, ',', ' ') ?> factures<br>
                  <?= number_format((int) $i['nb_transactions'], 0, ',', ' ') ?> opérations
                </td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$imports): ?>
              <tr><td class="text-center text-muted py-4">Aucun import pour l'instant.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header">
    <span>Sociétés Pennylane ↔ sociétés ADHUB</span>
    <?php if ($nonRelies): ?><span class="badge bg-warning text-dark"><?= $nonRelies ?> à relier</span><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <form method="post">
      <?= csrf_champ() ?>
      <input type="hidden" name="action" value="correspondances">
      <table class="table mb-0 align-middle">
        <thead>
          <tr><th>Nom dans Pennylane</th><th class="text-end">Factures</th><th class="text-end">Opérations</th><th style="min-width:220px">Société ADHUB</th></tr>
        </thead>
        <tbody>
          <?php foreach ($correspondances as $c): ?>
            <tr>
              <td class="fw-semibold"><?= h($c['societe']) ?></td>
              <td class="text-end"><?= number_format((int) $c['nb_factures'], 0, ',', ' ') ?></td>
              <td class="text-end"><?= number_format((int) $c['nb_transactions'], 0, ',', ' ') ?></td>
              <td>
                <select name="projet[<?= h($c['societe']) ?>]" class="form-select form-select-sm <?= $c['project_id'] === null ? 'border-warning' : '' ?>">
                  <option value="">— Non reliée —</option>
                  <?php foreach ($projets as $p): ?>
                    <option value="<?= (int) $p['id'] ?>" <?= (int) $c['project_id'] === (int) $p['id'] ? 'selected' : '' ?>><?= h($p['name']) ?></option>
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
      <?php if ($correspondances): ?>
        <div class="p-3 border-top d-flex justify-content-between align-items-center gap-3 flex-wrap">
          <span class="text-muted small">Les noms évidents sont reliés d'office ; vérifiez ceux en orange.</span>
          <button class="btn btn-dark btn-sm">Enregistrer</button>
        </div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
