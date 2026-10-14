<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/charges.php';
require_direction(); // vue consolidée : direction uniquement

$projects = get_global_overview();
$groupes  = group_projects_for_overview($projects);
$alertes  = get_alertes_toutes_societes();

// Une donnée de solde qui date de plus de 30 jours est signalée : mieux vaut
// afficher "chiffre ancien" qu'un chiffre faux présenté comme à jour.
function solde_est_perime(?string $majLe): bool
{
    if ($majLe === null) {
        return false;
    }
    return (new DateTime($majLe)) < (new DateTime('-30 days'));
}

function afficher_solde(array $p): string
{
    if ($p['solde_bancaire'] === null) {
        return '<span class="text-muted">—</span>';
    }
    $montant = number_format((float) $p['solde_bancaire'], 0, ',', ' ') . ' €';
    $classe  = (float) $p['solde_bancaire'] < 0 ? 'text-danger fw-bold' : 'fw-bold';
    $alerte  = solde_est_perime($p['solde_maj_le'])
        ? ' <span class="badge bg-warning text-dark" title="Donnée non actualisée depuis plus de 30 jours">ancien</span>'
        : '';
    return '<span class="' . $classe . '">' . $montant . '</span>' . $alerte;
}

function afficher_occupation(array $p): string
{
    if ($p['lots_total'] === null || (int) $p['lots_total'] === 0) {
        return '<span class="text-muted">—</span>';
    }
    $loues = (int) ($p['lots_loues'] ?? 0);
    $total = (int) $p['lots_total'];
    $classe = $loues < $total ? 'text-danger' : '';
    return '<span class="' . $classe . '">' . $loues . '/' . $total . ' logements loués</span>';
}

// Totaux de trésorerie par famille, pour la ligne de synthèse en haut
function total_solde(array $liste): float
{
    $t = 0.0;
    foreach ($liste as $p) {
        $t += (float) ($p['solde_bancaire'] ?? 0);
    }
    return $t;
}

$totalGeneral = total_solde($projects);

$pageTitle = 'Vue globale';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="mb-0">Vue d'ensemble</h1>
  <div class="text-end">
    <div class="text-muted small">Trésorerie totale</div>
    <div class="fs-4 fw-bold <?= $totalGeneral < 0 ? 'text-danger' : '' ?>">
      <?= number_format($totalGeneral, 0, ',', ' ') ?> €
    </div>
  </div>
</div>

<?php
$urgentes = array_filter($alertes, fn($a) => in_array($a['alerte'], ['depasse', 'urgent'], true));
if ($urgentes):
?>
  <div class="card border-danger mb-4">
    <div class="card-header bg-danger text-white">
      Échéances de contrats à traiter (<?= count($urgentes) ?>)
    </div>
    <div class="card-body">
      <table class="table table-sm mb-0">
        <tbody>
          <?php foreach (array_slice($urgentes, 0, 8) as $a): ?>
            <tr>
              <td style="width:28%">
                <a href="/pages/charges.php?id=<?= (int) $a['project_id'] ?>"
                   class="text-decoration-none fw-semibold text-dark">
                  <?= htmlspecialchars($a['project_name']) ?>
                </a>
              </td>
              <td><?= htmlspecialchars($a['label']) ?></td>
              <td class="text-end">
                <?php if ($a['alerte'] === 'depasse'): ?>
                  <span class="badge bg-danger">Préavis dépassé</span>
                <?php else: ?>
                  <span class="badge bg-danger">
                    Résilier avant le <?= date('d/m/Y', strtotime($a['date_limite_resiliation'])) ?>
                  </span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($urgentes) > 8): ?>
        <div class="text-muted small mt-2">
          et <?= count($urgentes) - 8 ?> autre(s) échéance(s).
        </div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php
$sections = [
  [
    'cle'     => 'sci',
    'titre'   => 'Immobilier locatif',
    'sigle'   => 'SCI',
    'couleur' => '#EAF1FB',
    'texte'   => '#185FA5',
    'colonnes'=> ['Compte bancaire', "Taux d'occupation"],
  ],
  [
    'cle'     => 'restaurants',
    'titre'   => 'Restaurants',
    'sigle'   => '🍴',
    'couleur' => '#EAF5EE',
    'texte'   => '#2D6A4F',
    'colonnes'=> ['Trésorerie', 'CA du mois'],
  ],
  [
    'cle'     => 'mdb',
    'titre'   => 'Marchands de biens',
    'sigle'   => 'MDB',
    'couleur' => '#F8EFE0',
    'texte'   => '#8A6D1E',
    'colonnes'=> ['Trésorerie', 'Affaires en cours'],
  ],
  [
    'cle'     => 'autres',
    'titre'   => 'Autres structures',
    'sigle'   => '·',
    'couleur' => '#F0F0EC',
    'texte'   => '#6B7280',
    'colonnes'=> ['Trésorerie', ''],
  ],
];
?>

<?php foreach ($sections as $s):
  $liste = $groupes[$s['cle']];
  if (!$liste) continue; // on n'affiche pas une famille vide
?>
  <div class="card mb-4">
    <div class="card-body">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <div class="d-flex align-items-center gap-2">
          <span style="display:inline-flex;align-items:center;justify-content:center;
                       width:34px;height:34px;border-radius:8px;font-weight:bold;font-size:13px;
                       background:<?= $s['couleur'] ?>;color:<?= $s['texte'] ?>">
            <?= $s['sigle'] ?>
          </span>
          <strong><?= htmlspecialchars($s['titre']) ?></strong>
        </div>
        <span class="text-muted small">
          <?= count($liste) ?> société<?= count($liste) > 1 ? 's' : '' ?>
          &middot; <?= number_format(total_solde($liste), 0, ',', ' ') ?> €
        </span>
      </div>

      <table class="table table-sm mb-0">
        <thead>
          <tr>
            <th style="width:45%">Société</th>
            <th class="text-end"><?= htmlspecialchars($s['colonnes'][0]) ?></th>
            <th class="text-end"><?= htmlspecialchars($s['colonnes'][1]) ?></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($liste as $p): ?>
            <tr>
              <td>
                <a href="/pages/project_dashboard.php?id=<?= (int) $p['id'] ?>"
                   class="text-decoration-none fw-semibold text-dark">
                  <?= htmlspecialchars($p['name']) ?>
                </a>
              </td>
              <td class="text-end"><?= afficher_solde($p) ?></td>
              <td class="text-end">
                <?php if ($s['cle'] === 'sci'): ?>
                  <?= afficher_occupation($p) ?>
                <?php elseif ($s['cle'] === 'restaurants'): ?>
                  <?= (float) $p['ca_mois'] > 0
                        ? number_format((float) $p['ca_mois'], 0, ',', ' ') . ' €'
                        : '<span class="text-muted">pas de saisie</span>' ?>
                <?php elseif ($s['cle'] === 'mdb'): ?>
                  <?= $p['affaires_en_cours'] !== null
                        ? (int) $p['affaires_en_cours']
                        : '<span class="text-muted">—</span>' ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$projects): ?>
  <div class="alert alert-secondary">
    Aucune société active. <a href="/pages/projects.php">Créer une société</a>.
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
