<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'fiche');

// La fiche contient les infos capitalistiques (associés, détentions) :
// modification réservée à qui a le droit d'écriture sur ce projet.
$canEdit = can_edit_project($id);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_project_edit($id);
}

$errors = [];

// Mise à jour des infos du projet (identité + patrimoine + documents)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $name   = trim($_POST['name'] ?? '');
    $typeId = (int) ($_POST['project_type_id'] ?? 0);
    $siret  = trim($_POST['siret_siren'] ?? '') ?: null;

    $details = [
        'adresse'         => trim($_POST['adresse'] ?? '') ?: null,
        'surface_m2'      => $_POST['surface_m2'] !== '' ? (float) str_replace(',', '.', $_POST['surface_m2']) : null,
        'valeur_bien'     => $_POST['valeur_bien'] !== '' ? (float) str_replace(',', '.', $_POST['valeur_bien']) : null,
        'montant_emprunt' => $_POST['montant_emprunt'] !== '' ? (float) str_replace(',', '.', $_POST['montant_emprunt']) : null,
        'solde_bancaire'  => ($_POST['solde_bancaire'] ?? '') !== '' ? (float) str_replace([' ', ','], ['', '.'], $_POST['solde_bancaire']) : null,
        'solde_maj_le'    => ($_POST['solde_maj_le'] ?? '') !== '' ? $_POST['solde_maj_le'] : null,
        'lots_total'      => ($_POST['lots_total'] ?? '') !== '' ? (int) $_POST['lots_total'] : null,
        'lots_loues'      => ($_POST['lots_loues'] ?? '') !== '' ? (int) $_POST['lots_loues'] : null,
        'affaires_en_cours' => ($_POST['affaires_en_cours'] ?? '') !== '' ? (int) $_POST['affaires_en_cours'] : null,
        'documents_url'   => trim($_POST['documents_url'] ?? '') ?: null,
        'description'     => trim($_POST['description'] ?? '') ?: null,
    ];

    if ($name === '') {
        $errors[] = "Le nom du projet est obligatoire.";
    }

    if (!$errors) {
        update_project($id, $name, $typeId, $siret, $details);
        header("Location: /pages/project_detail.php?id={$id}&updated=1");
        exit;
    }
}

// Archivage
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'archive') {
    archive_project($id);
    header('Location: /pages/projects.php?archived=1');
    exit;
}

// Ajout d'un associé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_associate') {
    $assocName = trim($_POST['assoc_name'] ?? '');
    $assocRole = trim($_POST['assoc_role'] ?? '') ?: null;
    $assocPct  = $_POST['assoc_percentage'] !== '' ? (float) str_replace(',', '.', $_POST['assoc_percentage']) : null;
    $assocMail = trim($_POST['assoc_email'] ?? '') ?: null;

    if ($assocName === '') {
        $errors[] = "Le nom de l'associé est obligatoire.";
    } else {
        add_associate($id, $assocName, $assocRole, $assocPct, $assocMail);
        header("Location: /pages/project_detail.php?id={$id}&assoc_added=1");
        exit;
    }
}

// Suppression d'un associé
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_associate') {
    delete_associate((int) $_POST['assoc_id'], $id);
    header("Location: /pages/project_detail.php?id={$id}&assoc_deleted=1");
    exit;
}

// Création d'un lien vers un autre projet (détention ou murs/exploitation)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create_link') {
    $targetId   = (int) ($_POST['target_id'] ?? 0);
    $linkType   = $_POST['link_type'] ?? '';
    $percRaw    = trim($_POST['percentage'] ?? '');
    $percentage = $percRaw === '' ? null : (float) str_replace(',', '.', $percRaw);

    if ($targetId <= 0) {
        $errors[] = "Choisis un projet cible pour le lien.";
    }
    if (!in_array($linkType, ['detention', 'murs_exploitation'], true)) {
        $errors[] = "Type de lien invalide.";
    }

    if (!$errors) {
        create_project_link($id, $targetId, $linkType, $percentage);
        header("Location: /pages/project_detail.php?id={$id}&linked=1");
        exit;
    }
}

$types         = get_all_project_types();
$otherProjects = get_projects_except($id);
$links         = get_project_links($id);
$associates    = get_associates($id);

// Total détenu par des tiers déjà connus (liens + associés), pour repérer d'un
// coup d'œil si la répartition du capital est incomplète.
$totalPct = 0.0;
foreach ($links as $l) {
    if ($l['link_type'] === 'detention' && $l['percentage'] !== null) {
        $totalPct += (float) $l['percentage'];
    }
}
foreach ($associates as $a) {
    if ($a['percentage'] !== null) {
        $totalPct += (float) $a['percentage'];
    }
}

$pageTitle = $project['name'];
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'fiche') ?>

<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Projet mis à jour.</div><?php endif; ?>
<?php if (isset($_GET['linked'])): ?><div class="alert alert-success">Lien créé.</div><?php endif; ?>
<?php if (isset($_GET['assoc_added'])): ?><div class="alert alert-success">Associé ajouté.</div><?php endif; ?>
<?php if (isset($_GET['assoc_deleted'])): ?><div class="alert alert-secondary">Associé retiré.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<div class="row">
  <!-- Colonne gauche : identité, patrimoine, documents -->
  <div class="col-md-6">

    <div class="card mb-4">
      <div class="card-header">Identité</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="update">

          <div class="col-12">
            <label class="form-label">Nom</label>
            <input type="text" name="name" class="form-control" value="<?= htmlspecialchars($project['name']) ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label">Type</label>
            <select name="project_type_id" class="form-select" required>
              <?php foreach ($types as $t): ?>
                <option value="<?= (int) $t['id'] ?>" <?= (int) $t['id'] === (int) $project['project_type_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($t['label']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6">
            <label class="form-label">SIRET / SIREN</label>
            <input type="text" name="siret_siren" class="form-control" value="<?= htmlspecialchars($project['siret_siren'] ?? '') ?>">
          </div>
          <div class="col-12">
            <span class="badge <?= $project['status'] === 'active' ? 'bg-success' : 'badge-archived' ?>">
              <?= $project['status'] === 'active' ? 'Actif' : 'Archivé' ?>
            </span>
          </div>

          <div class="col-12"><hr class="my-1"></div>
          <div class="col-12"><h6 class="text-muted mb-0">Patrimoine (si applicable)</h6></div>

          <div class="col-12">
            <label class="form-label">Adresse du bien</label>
            <input type="text" name="adresse" class="form-control" value="<?= htmlspecialchars($project['adresse'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Surface (m²)</label>
            <input type="text" name="surface_m2" class="form-control" value="<?= htmlspecialchars($project['surface_m2'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Valeur du bien (€)</label>
            <input type="text" name="valeur_bien" class="form-control" value="<?= htmlspecialchars($project['valeur_bien'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Emprunt restant (€)</label>
            <input type="text" name="montant_emprunt" class="form-control" value="<?= htmlspecialchars($project['montant_emprunt'] ?? '') ?>">
          </div>

          <div class="col-12"><hr class="my-1"></div>
          <div class="col-12">
            <h6 class="text-muted mb-0">Indicateurs de pilotage</h6>
            <div class="form-text">Ces valeurs alimentent directement la vue globale d'Arnaud.</div>
          </div>

          <div class="col-6">
            <label class="form-label">Solde du compte bancaire (€)</label>
            <input type="text" name="solde_bancaire" class="form-control"
                   value="<?= htmlspecialchars($project['solde_bancaire'] ?? '') ?>">
          </div>
          <div class="col-6">
            <label class="form-label">Solde arrêté au</label>
            <input type="date" name="solde_maj_le" class="form-control"
                   value="<?= htmlspecialchars($project['solde_maj_le'] ?? '') ?>">
            <div class="form-text">Au-delà de 30 jours, la vue globale signale un chiffre ancien.</div>
          </div>

          <div class="col-4">
            <label class="form-label">Logements — total</label>
            <input type="number" name="lots_total" class="form-control" min="0"
                   value="<?= htmlspecialchars($project['lots_total'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Logements — loués</label>
            <input type="number" name="lots_loues" class="form-control" min="0"
                   value="<?= htmlspecialchars($project['lots_loues'] ?? '') ?>">
          </div>
          <div class="col-4">
            <label class="form-label">Affaires en cours (MDB)</label>
            <input type="number" name="affaires_en_cours" class="form-control" min="0"
                   value="<?= htmlspecialchars($project['affaires_en_cours'] ?? '') ?>">
          </div>

          <div class="col-12"><hr class="my-1"></div>

          <div class="col-12">
            <label class="form-label">Lien vers les documents (OneDrive)</label>
            <input type="url" name="documents_url" class="form-control" placeholder="https://..." value="<?= htmlspecialchars($project['documents_url'] ?? '') ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Notes / description</label>
            <textarea name="description" class="form-control" rows="2"><?= htmlspecialchars($project['description'] ?? '') ?></textarea>
          </div>

          <div class="col-12 d-flex gap-2 mt-2">
            <button type="submit" class="btn btn-dark">Enregistrer</button>
            <?php if (!empty($project['documents_url'])): ?>
              <a href="<?= htmlspecialchars($project['documents_url']) ?>" target="_blank" rel="noopener" class="btn btn-outline-dark">
                Ouvrir les documents
              </a>
            <?php endif; ?>
          </div>
        </form>

        <?php if ($project['status'] === 'active'): ?>
          <form method="post" onsubmit="return confirm('Archiver ce projet ?');" class="mt-3">
            <input type="hidden" name="action" value="archive">
            <button type="submit" class="btn btn-outline-secondary btn-sm">Archiver ce projet</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- Colonne droite : associés + liens capitalistiques -->
  <div class="col-md-6">

    <div class="card mb-4">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Associés</span>
        <?php if ($totalPct > 0): ?>
          <span class="badge <?= $totalPct > 100.5 ? 'bg-danger' : 'bg-secondary' ?>">
            <?= number_format($totalPct, 1, ',', ' ') ?> % réparti
          </span>
        <?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$associates): ?>
          <p class="text-muted">Aucun associé renseigné pour l'instant (au-delà des liens capitalistiques ci-contre).</p>
        <?php else: ?>
          <ul class="list-group mb-3">
            <?php foreach ($associates as $a): ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span>
                  <?= htmlspecialchars($a['name']) ?>
                  <?php if ($a['role']): ?><span class="text-muted small"> — <?= htmlspecialchars($a['role']) ?></span><?php endif; ?>
                  <?php if ($a['contact_email']): ?><br><span class="text-muted small"><?= htmlspecialchars($a['contact_email']) ?></span><?php endif; ?>
                </span>
                <span class="d-flex align-items-center gap-2">
                  <?php if ($a['percentage'] !== null): ?>
                    <span class="badge badge-type text-white"><?= htmlspecialchars($a['percentage']) ?> %</span>
                  <?php endif; ?>
                  <form method="post" onsubmit="return confirm('Retirer cet associé ?');">
                    <input type="hidden" name="action" value="delete_associate">
                    <input type="hidden" name="assoc_id" value="<?= (int) $a['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger">&times;</button>
                  </form>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="add_associate">
          <div class="col-12">
            <label class="form-label">Nom</label>
            <input type="text" name="assoc_name" class="form-control" required>
          </div>
          <div class="col-6">
            <label class="form-label">Rôle</label>
            <input type="text" name="assoc_role" class="form-control" placeholder="ex: associé, gérant">
          </div>
          <div class="col-6">
            <label class="form-label">%</label>
            <input type="text" name="assoc_percentage" class="form-control" placeholder="ex: 49">
          </div>
          <div class="col-12">
            <label class="form-label">Email (optionnel — utile pour un futur compte)</label>
            <input type="email" name="assoc_email" class="form-control">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-dark btn-sm">Ajouter l'associé</button>
          </div>
        </form>
      </div>
    </div>

    <div class="card mb-4">
      <div class="card-header">Liens avec d'autres projets du groupe</div>
      <div class="card-body">
        <?php if (!$links): ?>
          <p class="text-muted">Aucun lien pour l'instant.</p>
        <?php else: ?>
          <ul class="list-group mb-3">
            <?php foreach ($links as $l):
              $otherName = ((int) $l['project_id_a'] === $id) ? $l['project_b_name'] : $l['project_a_name'];
              $typeLabel = $l['link_type'] === 'detention' ? 'Détention' : 'Murs / Exploitation';
            ?>
              <li class="list-group-item d-flex justify-content-between align-items-center">
                <span><?= htmlspecialchars($otherName) ?> — <?= $typeLabel ?></span>
                <?php if ($l['percentage'] !== null): ?>
                  <span class="badge badge-type text-white"><?= htmlspecialchars($l['percentage']) ?> %</span>
                <?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <form method="post" class="row g-2">
          <input type="hidden" name="action" value="create_link">
          <div class="col-12">
            <label class="form-label">Relier à</label>
            <select name="target_id" class="form-select" required>
              <option value="">— Choisir un projet —</option>
              <?php foreach ($otherProjects as $op): ?>
                <option value="<?= (int) $op['id'] ?>"><?= htmlspecialchars($op['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-7">
            <label class="form-label">Type de lien</label>
            <select name="link_type" class="form-select" required>
              <option value="detention">Détention (holding &rarr; filiale)</option>
              <option value="murs_exploitation">Murs / Exploitation (SCI &harr; SAS)</option>
            </select>
          </div>
          <div class="col-5">
            <label class="form-label">% (si détention)</label>
            <input type="text" name="percentage" class="form-control" placeholder="ex: 51">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-dark btn-sm">Ajouter le lien</button>
          </div>
        </form>
      </div>
    </div>

  </div>
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
