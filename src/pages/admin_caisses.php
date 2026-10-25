<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/caisses.php';
require_direction();

/**
 * Suivi des caisses : synchronisations de la nuit, établissements reliés, et
 * classement des catégories en solide / liquide (utilisé pour le food cost).
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'familles') {
    foreach ($_POST['famille'] ?? [] as $source => $cats) {
        foreach ((array) $cats as $categorie => $famille) {
            if ($famille !== '' && isset(CAISSE_SOURCES[$source])) {
                caisse_definir_famille($source, (string) $categorie, $famille);
            }
        }
    }
    header('Location: /pages/admin_caisses.php?saved=1');
    exit;
}

$synchros   = caisse_dernieres_synchros(60);
$categories = caisse_categories_vues();
$liens      = get_db()->query(
    "SELECT ce.*, p.name AS project_name FROM caisse_etablissements ce
     JOIN projects p ON p.id = ce.project_id ORDER BY p.name"
)->fetchAll();
$nbAClasser = count(array_filter($categories, fn($c) => $c['famille'] === null));

$pageTitle = 'Caisses';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Caisses', "Synchronisation nocturne de L'Addition et Popina.", '', 'Administration') ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">
    Classement enregistré. Il s'applique aux prochaines synchronisations ; pour recalculer
    des journées passées, relancer le script sur la période voulue.
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-header">Établissements reliés</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Société</th><th>Caisse</th><th>Établissement</th><th>N° caisse</th></tr></thead>
      <tbody>
        <?php foreach ($liens as $l): ?>
          <tr>
            <td><a href="/pages/analyse_caisse.php?id=<?= (int) $l['project_id'] ?>"><?= htmlspecialchars($l['project_name']) ?></a></td>
            <td><?= CAISSE_SOURCES[$l['source']] ?></td>
            <td><?= htmlspecialchars($l['nom'] ?? '') ?></td>
            <td class="text-muted"><?= (int) $l['id_externe'] ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$liens): ?>
          <tr><td colspan="4" class="text-center text-muted py-3">
            Aucun établissement relié : renseigner <code>etablissements</code> dans
            <code>config/caisses.local.php</code>, puis lancer une synchronisation.
          </td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card mb-4">
  <div class="card-header d-flex justify-content-between">
    <span>Catégories de caisse</span>
    <?php if ($nbAClasser): ?><span class="badge bg-warning text-dark"><?= $nbAClasser ?> à classer</span><?php endif; ?>
  </div>
  <div class="card-body p-0">
    <form method="post">
      <input type="hidden" name="action" value="familles">
      <table class="table table-sm mb-0 align-middle">
        <thead><tr><th>Caisse</th><th>Catégorie</th><th class="text-end">CA total</th><th>Vue le</th><th style="width:180px">Famille</th></tr></thead>
        <tbody>
          <?php foreach ($categories as $c): ?>
            <tr class="<?= $c['famille'] === null ? 'table-warning' : '' ?>">
              <td><?= CAISSE_SOURCES[$c['source']] ?></td>
              <td><?= htmlspecialchars($c['categorie']) ?></td>
              <td class="text-end"><?= number_format((float) $c['ttc'], 0, ',', ' ') ?> €</td>
              <td class="text-muted small"><?= $c['vu_le'] ? date('d/m/Y', strtotime($c['vu_le'])) : '—' ?></td>
              <td>
                <select class="form-select form-select-sm"
                        name="famille[<?= htmlspecialchars($c['source']) ?>][<?= htmlspecialchars($c['categorie']) ?>]">
                  <option value="">— à classer —</option>
                  <?php foreach (['solide' => 'Solide (cuisine)', 'liquide' => 'Liquide (bar)', 'autre' => 'Autre'] as $k => $lab): ?>
                    <option value="<?= $k ?>" <?= $c['famille'] === $k ? 'selected' : '' ?>><?= $lab ?></option>
                  <?php endforeach; ?>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$categories): ?>
            <tr><td colspan="5" class="text-center text-muted py-3">Aucune vente synchronisée pour l'instant.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
      <?php if ($categories): ?>
        <div class="p-2"><button class="btn btn-sm btn-dark">Enregistrer</button></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Dernières synchronisations</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Date</th><th>Caisse</th><th>Compte</th><th>Période</th><th class="text-end">Lignes</th><th>Statut</th></tr></thead>
      <tbody>
        <?php foreach ($synchros as $s): ?>
          <tr>
            <td><?= date('d/m/Y H:i', strtotime($s['run_at'])) ?></td>
            <td><?= htmlspecialchars(CAISSE_SOURCES[$s['source']] ?? $s['source']) ?></td>
            <td><?= htmlspecialchars($s['account']) ?></td>
            <td class="small">
              <?= date('d/m', strtotime($s['date_from'])) ?>
              <?= $s['date_to'] !== $s['date_from'] ? '→ ' . date('d/m', strtotime($s['date_to'])) : '' ?>
            </td>
            <td class="text-end"><?= (int) $s['nb_lines'] ?></td>
            <td>
              <?php if ($s['status'] === 'ok'): ?>
                <span class="badge bg-success">OK</span>
              <?php else: ?>
                <span class="badge bg-danger">Erreur</span>
                <div class="small text-danger"><?= htmlspecialchars($s['message'] ?? '') ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$synchros): ?>
          <tr><td colspan="6" class="text-center text-muted py-3">Aucune synchronisation pour l'instant.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
