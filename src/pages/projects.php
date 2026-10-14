<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_direction(); // création/archivage de sociétés : direction uniquement

$errors = [];

// Traitement du formulaire de création
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $name   = trim($_POST['name'] ?? '');
    $typeId = (int) ($_POST['project_type_id'] ?? 0);
    $siret  = trim($_POST['siret_siren'] ?? '') ?: null;

    if ($name === '') {
        $errors[] = "Le nom du projet est obligatoire.";
    }
    if ($typeId <= 0) {
        $errors[] = "Le type de projet est obligatoire.";
    }

    if (!$errors) {
        create_project($name, $typeId, $siret);
        header('Location: /pages/projects.php?created=1');
        exit;
    }
}

// Archivage rapide depuis la liste
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    archive_project((int) $_POST['id']);
    header('Location: /pages/projects.php?archived=1');
    exit;
}

$types    = get_all_project_types();
$projects = get_all_projects();

$pageTitle = 'Projets';
require __DIR__ . '/../inc/layout_header.php';
?>

<h1 class="mb-4">Projets</h1>

<?php if (isset($_GET['created'])): ?>
  <div class="alert alert-success">Projet créé.</div>
<?php endif; ?>
<?php if (isset($_GET['archived'])): ?>
  <div class="alert alert-secondary">Projet archivé.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="card mb-4">
  <div class="card-header">Nouveau projet</div>
  <div class="card-body">
    <form method="post" class="row g-3">
      <input type="hidden" name="action" value="create">
      <div class="col-md-4">
        <label class="form-label">Nom</label>
        <input type="text" name="name" class="form-control" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Type</label>
        <select name="project_type_id" class="form-select" required>
          <option value="">— Choisir —</option>
          <?php foreach ($types as $t): ?>
            <option value="<?= (int) $t['id'] ?>"><?= htmlspecialchars($t['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">SIRET / SIREN (optionnel)</label>
        <input type="text" name="siret_siren" class="form-control">
      </div>
      <div class="col-md-1 d-flex align-items-end">
        <button type="submit" class="btn btn-dark w-100">Créer</button>
      </div>
    </form>
  </div>
</div>

<table class="table table-bordered bg-white">
  <thead>
    <tr>
      <th>Nom</th>
      <th>Type</th>
      <th>SIRET / SIREN</th>
      <th>Statut</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
    <?php foreach ($projects as $p): ?>
      <tr class="<?= $p['status'] === 'archived' ? 'table-secondary' : '' ?>">
        <td><a href="/pages/project_detail.php?id=<?= (int) $p['id'] ?>"><?= htmlspecialchars($p['name']) ?></a></td>
        <td><span class="badge badge-type text-white"><?= htmlspecialchars($p['type_label']) ?></span></td>
        <td><?= htmlspecialchars($p['siret_siren'] ?? '—') ?></td>
        <td><?= $p['status'] === 'active' ? 'Actif' : 'Archivé' ?></td>
        <td>
          <?php if ($p['status'] === 'active'): ?>
            <form method="post" onsubmit="return confirm('Archiver ce projet ?');" class="d-inline">
              <input type="hidden" name="action" value="archive">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary">Archiver</button>
            </form>
          <?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$projects): ?>
      <tr><td colspan="5" class="text-center text-muted">Aucun projet pour l'instant.</td></tr>
    <?php endif; ?>
  </tbody>
</table>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
