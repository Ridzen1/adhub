<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/documents.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'documents');
$canEdit = can_edit_project($id);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_project_edit($id);
    $action = $_POST['action'] ?? '';

    if ($action === 'dossier_societe') {
        $url = trim($_POST['documents_url'] ?? '') ?: null;
        if ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "Le lien OneDrive n'est pas une URL valide.";
        } else {
            get_db()->prepare("UPDATE projects SET documents_url = :u WHERE id = :id")
                ->execute(['u' => $url, 'id' => $id]);
            header("Location: /pages/project_documents.php?id={$id}&dossier=1");
            exit;
        }
    }

    if ($action === 'create_category') {
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['onedrive_url'] ?? '') ?: null;
        $sens  = isset($_POST['is_sensitive']);

        if ($label === '') {
            $errors[] = "Le nom de la catégorie est obligatoire.";
        } elseif ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "Le lien OneDrive n'est pas une URL valide.";
        } else {
            create_category($id, $label, $url, $sens);
            header("Location: /pages/project_documents.php?id={$id}&created=1");
            exit;
        }
    }

    if ($action === 'update_category') {
        $catId = (int) ($_POST['category_id'] ?? 0);
        $label = trim($_POST['label'] ?? '');
        $url   = trim($_POST['onedrive_url'] ?? '') ?: null;
        $sens  = isset($_POST['is_sensitive']);

        if ($label === '') {
            $errors[] = "Le nom de la catégorie est obligatoire.";
        } elseif ($url !== null && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = "Le lien OneDrive n'est pas une URL valide.";
        } else {
            update_category($catId, $id, $label, $url, $sens);
            header("Location: /pages/project_documents.php?id={$id}&updated=1");
            exit;
        }
    }

    if ($action === 'delete_category') {
        delete_category((int) $_POST['category_id'], $id);
        header("Location: /pages/project_documents.php?id={$id}&deleted=1");
        exit;
    }
}

$categories = get_categories($id);

$pageTitle = $project['name'] . ' — Documents';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'documents') ?>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Catégorie ajoutée.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Catégorie mise à jour.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-secondary">Catégorie supprimée.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if (isset($_GET['dossier'])): ?><div class="alert alert-success">Dossier OneDrive enregistré.</div><?php endif; ?>

<div class="card mb-4">
  <div class="card-body d-flex flex-wrap gap-3 align-items-center justify-content-between">
    <div>
      <h6 class="mb-1">Dossier OneDrive de la société</h6>
      <div class="text-muted small">Un seul lien pour tous les documents : les sous-dossiers (Juridique, Compta…) s'ouvrent dans OneDrive.
        Les catégories ci-dessous sont facultatives.</div>
    </div>
    <?php if (!empty($project['documents_url'])): ?>
      <a href="/pages/open_document.php?project_id=<?= $id ?>" target="_blank" rel="noopener" class="btn btn-dark">
        Ouvrir le dossier &rarr;
      </a>
    <?php else: ?>
      <span class="text-muted small">Lien non renseigné</span>
    <?php endif; ?>
  </div>
  <?php if ($canEdit): ?>
    <div class="card-footer bg-transparent">
      <form method="post" class="d-flex flex-wrap gap-2">
        <input type="hidden" name="action" value="dossier_societe">
        <input type="url" name="documents_url" class="form-control form-control-sm" style="flex:1 1 320px"
               placeholder="Collez le lien de partage du dossier OneDrive"
               value="<?= htmlspecialchars($project['documents_url'] ?? '') ?>">
        <button type="submit" class="btn btn-sm btn-outline-dark">Enregistrer</button>
      </form>
    </div>
  <?php endif; ?>
</div>

<div class="row row-cols-1 row-cols-md-3 g-3 mb-4">
  <?php foreach ($categories as $c): ?>
    <div class="col">
      <div class="card h-100">
        <div class="card-body">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <h6 class="mb-0"><?= htmlspecialchars($c['label']) ?></h6>
            <?php if ($c['is_sensitive']): ?>
              <span class="badge bg-warning text-dark">Sensible</span>
            <?php endif; ?>
          </div>

          <?php if (!empty($c['onedrive_url'])): ?>
            <a href="/pages/open_document.php?category_id=<?= (int) $c['id'] ?>"
               target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary">
              Ouvrir dans OneDrive &rarr;
            </a>
          <?php else: ?>
            <span class="text-muted small">Lien non renseigné</span>
          <?php endif; ?>

          <?php if ($canEdit): ?>
            <button class="btn btn-sm btn-link text-muted p-0 ms-2"
                    data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $c['id'] ?>">
              Modifier
            </button>

            <div class="collapse mt-3" id="edit-<?= (int) $c['id'] ?>">
              <form method="post" class="mb-2">
                <input type="hidden" name="action" value="update_category">
                <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                <div class="mb-2">
                  <label class="form-label small">Nom</label>
                  <input type="text" name="label" class="form-control form-control-sm"
                         value="<?= htmlspecialchars($c['label']) ?>" required>
                </div>
                <div class="mb-2">
                  <label class="form-label small">Lien OneDrive</label>
                  <input type="url" name="onedrive_url" class="form-control form-control-sm"
                         placeholder="https://..." value="<?= htmlspecialchars($c['onedrive_url'] ?? '') ?>">
                </div>
                <div class="form-check mb-2">
                  <input class="form-check-input" type="checkbox" name="is_sensitive"
                         id="sens-<?= (int) $c['id'] ?>" <?= $c['is_sensitive'] ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="sens-<?= (int) $c['id'] ?>">
                    Contenu sensible (RH, données personnelles)
                  </label>
                </div>
                <button type="submit" class="btn btn-sm btn-dark">Enregistrer</button>
              </form>
              <form method="post" onsubmit="return confirm('Supprimer cette catégorie ?');">
                <input type="hidden" name="action" value="delete_category">
                <input type="hidden" name="category_id" value="<?= (int) $c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if (!$categories): ?>
    <div class="col-12">
      <div class="alert alert-secondary mb-0">Aucune catégorie pour cette société.</div>
    </div>
  <?php endif; ?>
</div>

<?php if ($canEdit): ?>
  <div class="card mb-4">
    <div class="card-header">Ajouter une catégorie</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create_category">
        <div class="col-md-4">
          <label class="form-label">Nom</label>
          <input type="text" name="label" class="form-control" placeholder="ex : Assurances" required>
        </div>
        <div class="col-md-5">
          <label class="form-label">Lien vers le dossier OneDrive</label>
          <input type="url" name="onedrive_url" class="form-control" placeholder="https://...">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" name="is_sensitive" id="new-sens">
            <label class="form-check-label small" for="new-sens">Sensible</label>
          </div>
        </div>
        <div class="col-md-1 d-flex align-items-end">
          <button type="submit" class="btn btn-dark w-100 mb-2">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<div class="alert alert-light border">
  <strong>Rappel :</strong> les fichiers restent hébergés sur OneDrive — l'outil n'en stocke aucune copie.
  Les ouvertures de dossier sont journalisées, mais les droits de téléchargement se règlent
  dans les paramètres de partage OneDrive, pas ici.
</div>


<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
