<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/biens.php';
require_once __DIR__ . '/../inc/ui.php';
require_direction(); // montants des prêts : direction uniquement

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);
if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

$biens   = biens_du_projet($id);
$prets   = prets_du_projet($id);
$alertes = prets_alertes($id);

$valeur = null;
foreach ($biens as $b) {
    if ($b['valeur'] !== null) {
        $valeur = ($valeur ?? 0.0) + (float) $b['valeur'];
    }
}
$crd = null;
$mensualites = 0.0;
foreach ($prets as $p) {
    if ($p['situation']['crd'] !== null) {
        $crd = ($crd ?? 0.0) + $p['situation']['crd'];
    }
    $mensualites += (float) ($p['situation']['mensualite'] ?? 0);
}

const PHASES = [
    'differe'       => ['Différé', 'bg-warning text-dark'],
    'amortissement' => ['En cours', 'bg-secondary'],
    'fige'          => ['En déblocage', 'bg-warning text-dark'],
    'termine'       => ['Remboursé', 'bg-success'],
    'inconnu'       => ['—', 'bg-light text-dark'],
];

$pageTitle = $project['name'] . ' — Biens et prêts';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'biens') ?>

<?php if (!biens_tables_pretes()): ?>
  <div class="alert alert-warning">Passez d'abord la migration <code>027_biens_prets.sql</code> dans phpMyAdmin.</div>
<?php elseif (!$biens && !$prets): ?>
  <div class="alert alert-secondary">Aucun bien ni prêt pour cette société.
    Le fichier de suivi des emprunts se dépose dans <a href="/pages/admin_biens.php">Administration → Biens et prêts</a>.</div>
<?php else: ?>

<div class="kpi-grid">
  <?php if ($biens): ?>
    <?= kpi('Valeur des biens', $valeur !== null ? eur($valeur) : '—', ['icon' => 'home', 'foot' => '<span>' . count($biens) . ' bien' . (count($biens) > 1 ? 's' : '') . '</span>']) ?>
  <?php endif; ?>
  <?= kpi('Capital restant dû', $crd !== null ? eur($crd) : '—', ['hero' => true, 'icon' => 'bank', 'foot' => '<span>' . count($prets) . ' prêt' . (count($prets) > 1 ? 's' : '') . ' · recalculé au ' . date('d/m/Y') . '</span>']) ?>
  <?= kpi('Mensualités', eur($mensualites), ['icon' => 'calendar', 'foot' => '<span>Hors assurance, ce mois-ci</span>']) ?>
  <?php if ($biens && $valeur !== null): ?>
    <?= kpi('Valeur nette', eur($valeur - (float) $crd), ['icon' => 'trend', 'foot' => '<span>Biens moins capital restant dû</span>']) ?>
  <?php endif; ?>
</div>

<?php if ($alertes): ?>
  <div class="card border-warning mb-4">
    <div class="card-header">Échéances à venir</div>
    <div class="card-body p-0">
      <table class="table mb-0">
        <tbody>
          <?php foreach ($alertes as $a): ?>
            <tr>
              <td style="width:130px" class="fw-semibold"><?= date('d/m/Y', strtotime($a['date'])) ?></td>
              <td><?= h($a['pret']['ref']) ?> · <?= h($a['pret']['banque'] ?? '') ?></td>
              <td><?= h($a['texte']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php if ($prets): ?>
<div class="card mb-4">
  <div class="card-header">Prêts <span class="card-hint">Capital restant dû recalculé chaque mois</span></div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th>Prêt</th><th>Banque</th><th class="text-end">Taux</th><th class="text-end">Mensualité</th>
            <th class="text-end">Restant dû</th><th class="text-end">Fin</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($prets as $p): $s = $p['situation']; [$phaseLabel, $phaseClasse] = PHASES[$s['phase']]; ?>
            <tr>
              <td>
                <span class="fw-semibold"><?= h($p['objet'] ?? $p['ref']) ?></span>
                <span class="badge <?= $phaseClasse ?>"><?= $phaseLabel ?></span>
                <?php if ((int) $p['a_confirmer']): ?><span class="badge bg-warning text-dark">rattachement à confirmer</span><?php endif; ?>
                <div class="text-muted small">
                  <?= h($p['ref']) ?><?= $p['bien_adresse'] || $p['bien_libelle'] ? ' · ' . h($p['bien_adresse'] ?: $p['bien_libelle']) : '' ?>
                  <?= $p['numero'] ? ' · n° ' . h($p['numero']) : '' ?>
                </div>
              </td>
              <td><?= h($p['banque'] ?? '') ?></td>
              <td class="text-end num"><?= $p['taux'] !== null ? pourcent((float) $p['taux'] * 100, 2) : '—' ?></td>
              <td class="text-end num">
                <?= $s['mensualite'] !== null ? eur($s['mensualite'], 2) : '—' ?>
                <?php if ($s['phase'] === 'differe' && $p['mensualite_post_differe'] !== null): ?>
                  <div class="text-muted small">puis <?= eur((float) $p['mensualite_post_differe'], 2) ?> après le <?= date('d/m/Y', strtotime($p['fin_differe'])) ?></div>
                <?php endif; ?>
              </td>
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

<?php if ($biens): ?>
<div class="card mb-4">
  <div class="card-header">Biens</div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead><tr><th>Bien</th><th>Adresse</th><th class="text-end">Valeur</th></tr></thead>
        <tbody>
          <?php foreach ($biens as $b): ?>
            <tr>
              <td class="fw-semibold"><?= h($b['type_bien'] ?? $b['ref']) ?></td>
              <td><?= h($b['adresse'] ?? '') ?></td>
              <td class="text-end num"><?= $b['valeur'] !== null ? eur((float) $b['valeur']) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php endif; ?>

<p class="text-muted small">* Date de fin estimée dans le fichier. Le capital restant dû part du capital à la date d'ancrage
  du fichier, puis retire chaque mensualité au taux du prêt. Assurance non comprise.</p>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
