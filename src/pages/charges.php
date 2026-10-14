<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/charges.php';
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

function lire_charge_du_formulaire(array $post): array
{
    return [
        'label'         => trim($post['label'] ?? ''),
        'category'      => $post['category'] ?? 'autre',
        'fournisseur'   => trim($post['fournisseur'] ?? '') ?: null,
        'montant'       => (float) str_replace([' ', ','], ['', '.'], $post['montant'] ?? '0'),
        'frequence'     => $post['frequence'] ?? 'mensuel',
        'date_reference'=> $post['date_reference'] ?? '',
        'preavis_jours' => (int) ($post['preavis_jours'] ?? 0),
        'date_fin'      => ($post['date_fin'] ?? '') !== '' ? $post['date_fin'] : null,
        'notes'         => trim($post['notes'] ?? '') ?: null,
    ];
}

function valider_charge(array $d): array
{
    $e = [];
    if ($d['label'] === '') {
        $e[] = "Le libellé est obligatoire.";
    }
    if (!isset(CHARGE_CATEGORIES[$d['category']])) {
        $e[] = "Catégorie invalide.";
    }
    if (!isset(CHARGE_FREQUENCES[$d['frequence']])) {
        $e[] = "Fréquence invalide.";
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['date_reference']) || !strtotime($d['date_reference'])) {
        $e[] = "Date d'échéance invalide.";
    }
    if ($d['montant'] < 0) {
        $e[] = "Le montant ne peut pas être négatif.";
    }
    if ($d['preavis_jours'] < 0 || $d['preavis_jours'] > 365) {
        $e[] = "Le préavis doit être compris entre 0 et 365 jours.";
    }
    return $e;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_project_edit($id);
    $action = $_POST['action'] ?? '';

    if ($action === 'create' || $action === 'update') {
        $data = lire_charge_du_formulaire($_POST);
        $errors = valider_charge($data);

        if (!$errors) {
            if ($action === 'create') {
                create_charge($id, $data);
                header("Location: /pages/charges.php?id={$id}&created=1");
            } else {
                update_charge((int) $_POST['charge_id'], $id, $data);
                header("Location: /pages/charges.php?id={$id}&updated=1");
            }
            exit;
        }
    }

    if ($action === 'toggle') {
        set_charge_active((int) $_POST['charge_id'], $id, (int) $_POST['active'] === 1);
        header("Location: /pages/charges.php?id={$id}&toggled=1");
        exit;
    }

    if ($action === 'delete') {
        delete_charge((int) $_POST['charge_id'], $id);
        header("Location: /pages/charges.php?id={$id}&deleted=1");
        exit;
    }
}

$inclureInactives = isset($_GET['inactives']);
$charges = get_charges($id, $inclureInactives);
$totalAnnuel = 0.0;
foreach ($charges as $c) {
    if ($c['is_active']) { $totalAnnuel += $c['cout_annuel']; }
}

$alertes = array_filter($charges, fn($c) => $c['alerte'] !== null && $c['is_active']);

$pageTitle = $project['name'] . ' — Charges fixes';
require __DIR__ . '/../inc/layout_header.php';

function badge_alerte(array $c): string
{
    $jours = $c['jours_avant_limite'];
    switch ($c['alerte']) {
        case 'depasse':
            return '<span class="badge bg-danger">Préavis dépassé</span>';
        case 'urgent':
            return '<span class="badge bg-danger">Résilier sous ' . $jours . ' j</span>';
        case 'a_venir':
            return '<span class="badge bg-warning text-dark">Préavis dans ' . $jours . ' j</span>';
        default:
            return '';
    }
}
?>

<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
  <div>
    <a href="/pages/project_dashboard.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Dashboard</a>
    <a href="/pages/project_documents.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Documents</a>
  </div>
</div>

<?php if (isset($_GET['created'])): ?><div class="alert alert-success">Charge ajoutée.</div><?php endif; ?>
<?php if (isset($_GET['updated'])): ?><div class="alert alert-success">Charge mise à jour.</div><?php endif; ?>
<?php if (isset($_GET['deleted'])): ?><div class="alert alert-secondary">Charge supprimée.</div><?php endif; ?>
<?php if (isset($_GET['toggled'])): ?><div class="alert alert-secondary">Charge mise à jour.</div><?php endif; ?>
<?php foreach ($errors as $e): ?><div class="alert alert-danger"><?= htmlspecialchars($e) ?></div><?php endforeach; ?>

<?php if ($alertes): ?>
  <div class="card border-danger mb-4">
    <div class="card-header bg-danger text-white">Échéances à surveiller</div>
    <div class="card-body">
      <ul class="mb-0">
        <?php foreach ($alertes as $a): ?>
          <li class="mb-1">
            <strong><?= htmlspecialchars($a['label']) ?></strong> —
            <?php if ($a['alerte'] === 'depasse'): ?>
              le préavis est dépassé (limite au <?= date('d/m/Y', strtotime($a['date_limite_resiliation'])) ?>),
              le contrat se reconduit le <?= date('d/m/Y', strtotime($a['prochaine_echeance'])) ?>.
            <?php else: ?>
              résiliation possible jusqu'au
              <strong><?= date('d/m/Y', strtotime($a['date_limite_resiliation'])) ?></strong>
              (<?= $a['jours_avant_limite'] ?> jours), reconduction le
              <?= date('d/m/Y', strtotime($a['prochaine_echeance'])) ?>.
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
<?php endif; ?>

<div class="row mb-4">
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Total charges / an</div>
      <div class="fs-4 fw-bold"><?= number_format($totalAnnuel, 0, ',', ' ') ?> €</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Équivalent mensuel</div>
      <div class="fs-4 fw-bold"><?= number_format($totalAnnuel / 12, 0, ',', ' ') ?> €</div>
    </div></div>
  </div>
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Charges suivies</div>
      <div class="fs-4 fw-bold"><?= count(array_filter($charges, fn($c) => $c['is_active'])) ?></div>
    </div></div>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Charges fixes</span>
    <a class="small" href="?id=<?= $id ?><?= $inclureInactives ? '' : '&inactives=1' ?>">
      <?= $inclureInactives ? 'Masquer les inactives' : 'Afficher les inactives' ?>
    </a>
  </div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0 align-middle">
      <thead>
        <tr>
          <th>Libellé</th>
          <th>Catégorie</th>
          <th class="text-end">Montant</th>
          <th>Fréquence</th>
          <th>Prochaine échéance</th>
          <th>Préavis</th>
          <?php if ($canEdit): ?><th></th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($charges as $c): ?>
          <tr class="<?= $c['is_active'] ? '' : 'table-secondary' ?>">
            <td>
              <strong><?= htmlspecialchars($c['label']) ?></strong>
              <?php if ($c['fournisseur']): ?>
                <div class="text-muted small"><?= htmlspecialchars($c['fournisseur']) ?></div>
              <?php endif; ?>
              <?php if (!$c['is_active']): ?>
                <span class="badge bg-secondary">Inactive</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= htmlspecialchars(CHARGE_CATEGORIES[$c['category']] ?? $c['category']) ?></td>
            <td class="text-end">
              <?= number_format((float) $c['montant'], 2, ',', ' ') ?> €
              <div class="text-muted small"><?= number_format($c['cout_annuel'], 0, ',', ' ') ?> €/an</div>
            </td>
            <td class="small"><?= CHARGE_FREQUENCES[$c['frequence']]['label'] ?? $c['frequence'] ?></td>
            <td class="small">
              <?= date('d/m/Y', strtotime($c['prochaine_echeance'])) ?>
              <div class="text-muted">dans <?= $c['jours_avant_echeance'] ?> j</div>
            </td>
            <td class="small">
              <?php if ((int) $c['preavis_jours'] > 0): ?>
                <?= (int) $c['preavis_jours'] ?> j
                <div><?= badge_alerte($c) ?></div>
              <?php else: ?>
                <span class="text-muted">—</span>
              <?php endif; ?>
            </td>
            <?php if ($canEdit): ?>
              <td class="text-end" style="white-space:nowrap">
                <button class="btn btn-sm btn-link p-0 me-2"
                        data-bs-toggle="collapse" data-bs-target="#edit-<?= (int) $c['id'] ?>">Modifier</button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="charge_id" value="<?= (int) $c['id'] ?>">
                  <input type="hidden" name="active" value="<?= $c['is_active'] ? 0 : 1 ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary">
                    <?= $c['is_active'] ? 'Désactiver' : 'Réactiver' ?>
                  </button>
                </form>
              </td>
            <?php endif; ?>
          </tr>

          <?php if ($canEdit): ?>
            <tr class="collapse" id="edit-<?= (int) $c['id'] ?>">
              <td colspan="7" class="bg-light">
                <form method="post" class="row g-2">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="charge_id" value="<?= (int) $c['id'] ?>">
                  <div class="col-md-3">
                    <label class="form-label small">Libellé</label>
                    <input type="text" name="label" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['label']) ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small">Catégorie</label>
                    <select name="category" class="form-select form-select-sm">
                      <?php foreach (CHARGE_CATEGORIES as $k => $lab): ?>
                        <option value="<?= $k ?>" <?= $c['category'] === $k ? 'selected' : '' ?>>
                          <?= htmlspecialchars($lab) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small">Fournisseur</label>
                    <input type="text" name="fournisseur" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['fournisseur'] ?? '') ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small">Montant (€)</label>
                    <input type="text" name="montant" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['montant']) ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Fréquence</label>
                    <select name="frequence" class="form-select form-select-sm">
                      <?php foreach (CHARGE_FREQUENCES as $k => $f): ?>
                        <option value="<?= $k ?>" <?= $c['frequence'] === $k ? 'selected' : '' ?>>
                          <?= $f['label'] ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Prochaine échéance connue</label>
                    <input type="date" name="date_reference" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['date_reference']) ?>" required>
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small">Préavis (jours)</label>
                    <input type="number" name="preavis_jours" class="form-control form-control-sm"
                           min="0" max="365" value="<?= (int) $c['preavis_jours'] ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label small">Fin de contrat</label>
                    <input type="date" name="date_fin" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['date_fin'] ?? '') ?>">
                  </div>
                  <div class="col-md-3">
                    <label class="form-label small">Notes</label>
                    <input type="text" name="notes" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($c['notes'] ?? '') ?>">
                  </div>
                  <div class="col-md-2 d-flex align-items-end gap-2">
                    <button type="submit" class="btn btn-sm btn-dark">Enregistrer</button>
                  </div>
                </form>
                <form method="post" class="mt-2" onsubmit="return confirm('Supprimer définitivement cette charge ?');">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="charge_id" value="<?= (int) $c['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger">Supprimer</button>
                </form>
              </td>
            </tr>
          <?php endif; ?>
        <?php endforeach; ?>

        <?php if (!$charges): ?>
          <tr><td colspan="<?= $canEdit ? 7 : 6 ?>" class="text-center text-muted py-3">
            Aucune charge fixe enregistrée.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($canEdit): ?>
  <div class="card mb-4">
    <div class="card-header">Ajouter une charge fixe</div>
    <div class="card-body">
      <form method="post" class="row g-3">
        <input type="hidden" name="action" value="create">
        <div class="col-md-3">
          <label class="form-label">Libellé</label>
          <input type="text" name="label" class="form-control" placeholder="ex : Assurance PNO" required>
        </div>
        <div class="col-md-2">
          <label class="form-label">Catégorie</label>
          <select name="category" class="form-select">
            <?php foreach (CHARGE_CATEGORIES as $k => $lab): ?>
              <option value="<?= $k ?>"><?= htmlspecialchars($lab) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-2">
          <label class="form-label">Fournisseur</label>
          <input type="text" name="fournisseur" class="form-control">
        </div>
        <div class="col-md-2">
          <label class="form-label">Montant (€)</label>
          <input type="text" name="montant" class="form-control" placeholder="ex : 480" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Fréquence</label>
          <select name="frequence" class="form-select">
            <?php foreach (CHARGE_FREQUENCES as $k => $f): ?>
              <option value="<?= $k ?>" <?= $k === 'mensuel' ? 'selected' : '' ?>><?= $f['label'] ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="col-md-3">
          <label class="form-label">Prochaine échéance</label>
          <input type="date" name="date_reference" class="form-control" required>
          <div class="form-text">Les suivantes se calculent automatiquement.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Préavis (jours)</label>
          <input type="number" name="preavis_jours" class="form-control" min="0" max="365" value="0">
          <div class="form-text">0 si aucun.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Fin de contrat</label>
          <input type="date" name="date_fin" class="form-control">
        </div>
        <div class="col-md-3">
          <label class="form-label">Notes</label>
          <input type="text" name="notes" class="form-control">
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <button type="submit" class="btn btn-dark w-100 mb-1">Ajouter</button>
        </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
