<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/periodes.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'dashboard');

$vue = $_GET['vue'] ?? 'jour';
if (!in_array($vue, ['jour', 'semaine', 'mois'], true)) {
    $vue = 'jour';
}

$comp  = comparatif_periode($id, $vue);
$serie = serie_comparee($id, $comp['bornes']['debut'], $comp['bornes']['fin'], $comp['bornes']['n1_debut']);

/**
 * Affiche une variation en % avec sa couleur.
 * Une hausse de charges n'est pas une bonne nouvelle, mais ici on ne suit que
 * du CA / des couverts / du ticket moyen : la hausse est toujours positive.
 */
function badge_variation(?float $v): string
{
    if ($v === null) {
        return '<span class="text-muted small">—</span>';
    }
    $classe = $v >= 0 ? 'text-success' : 'text-danger';
    $signe  = $v >= 0 ? '+' : '';
    return '<span class="' . $classe . ' fw-semibold">'
         . $signe . number_format($v, 1, ',', ' ') . ' %</span>';
}

function fmt_euro(?float $v): string
{
    return $v === null ? '—' : number_format($v, 0, ',', ' ') . ' €';
}

$pageTitle = $project['name'] . ' — Évolution';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
  <div>
    <a href="/pages/project_dashboard.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Dashboard</a>
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Saisie</a>
  </div>
</div>

<ul class="nav nav-pills mb-4">
  <?php foreach (['jour' => 'Hier', 'semaine' => 'Semaine', 'mois' => 'Mois'] as $k => $lab): ?>
    <li class="nav-item">
      <a class="nav-link <?= $vue === $k ? 'active' : '' ?>"
         style="<?= $vue === $k ? 'background:#0E1C2B' : '' ?>"
         href="?id=<?= $id ?>&vue=<?= $k ?>"><?= $lab ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<p class="text-muted">
  <?= htmlspecialchars($comp['libelle']) ?>
  &middot; comparé au
  <?= date('d/m/Y', strtotime($comp['bornes']['n1_debut'])) ?>
  <?php if ($comp['bornes']['n1_debut'] !== $comp['bornes']['n1_fin']): ?>
    – <?= date('d/m/Y', strtotime($comp['bornes']['n1_fin'])) ?>
  <?php endif; ?>
</p>

<?php if (!$comp['n1_disponible']): ?>
  <div class="alert alert-secondary">
    Aucune donnée sur la période de référence de l'an dernier : les comparaisons ne sont pas
    affichées. Elles apparaîtront une fois que l'outil aura un an d'historique.
  </div>
<?php endif; ?>

<div class="row mb-4">
  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Chiffre d'affaires</div>
      <div class="fs-3 fw-bold"><?= fmt_euro($comp['actuel']['ca']) ?></div>
      <?php if ($comp['n1_disponible']): ?>
        <div class="small">
          <?= badge_variation($comp['var_ca']) ?>
          <span class="text-muted">vs <?= fmt_euro($comp['n1']['ca']) ?> l'an dernier</span>
        </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Couverts</div>
      <div class="fs-3 fw-bold"><?= number_format($comp['actuel']['couverts'], 0, ',', ' ') ?></div>
      <?php if ($comp['n1_disponible']): ?>
        <div class="small">
          <?= badge_variation($comp['var_couverts']) ?>
          <span class="text-muted">vs <?= number_format($comp['n1']['couverts'], 0, ',', ' ') ?></span>
        </div>
      <?php endif; ?>
    </div></div>
  </div>

  <div class="col-md-4">
    <div class="card h-100"><div class="card-body">
      <div class="text-muted small">Ticket moyen</div>
      <div class="fs-3 fw-bold">
        <?= $comp['actuel']['ticket_moyen'] !== null
              ? number_format($comp['actuel']['ticket_moyen'], 2, ',', ' ') . ' €'
              : '—' ?>
      </div>
      <?php if ($comp['n1_disponible']): ?>
        <div class="small">
          <?= badge_variation($comp['var_ticket']) ?>
          <span class="text-muted">
            vs <?= $comp['n1']['ticket_moyen'] !== null
                    ? number_format($comp['n1']['ticket_moyen'], 2, ',', ' ') . ' €'
                    : '—' ?>
          </span>
        </div>
      <?php endif; ?>
    </div></div>
  </div>
</div>

<?php if ($comp['actuel']['jours_saisis'] === 0): ?>
  <div class="alert alert-warning">
    Aucune saisie sur cette période.
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>">Saisir les journées</a>.
  </div>
<?php elseif (count($serie) > 1): ?>
  <div class="card mb-4">
    <div class="card-body">
      <h6 class="mb-3">Chiffre d'affaires jour par jour</h6>
      <canvas id="chartPeriode" height="80"></canvas>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th>Jour</th>
          <th class="text-end">CA</th>
          <th class="text-end">CA N-1</th>
          <th class="text-end">Écart</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($serie) as $s):
          $ecart = variation($s['ca'], $s['ca_n1']); ?>
          <tr>
            <td><?= htmlspecialchars($s['libelle']) ?></td>
            <td class="text-end"><?= $s['ca'] > 0 ? fmt_euro($s['ca']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-end text-muted"><?= $s['ca_n1'] > 0 ? fmt_euro($s['ca_n1']) : '—' ?></td>
            <td class="text-end"><?= badge_variation($ecart) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-light border mt-4 small">
  <strong>Méthode de comparaison :</strong>
  <?php if ($vue === 'mois'): ?>
    le mois en cours est comparé au même mois de l'an dernier, tronqué au même nombre de jours
    écoulés — sinon un mois entamé paraîtrait toujours en forte baisse.
  <?php else: ?>
    la référence est prise 364 jours plus tôt (52 semaines), et non à la même date, pour comparer
    un vendredi à un vendredi. Le jour de la semaine pèse davantage que la date dans l'activité
    d'un restaurant.
  <?php endif; ?>
</div>

<?php if ($comp['actuel']['jours_saisis'] > 0 && count($serie) > 1): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
new Chart(document.getElementById('chartPeriode'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($serie, 'libelle')) ?>,
    datasets: [
      {
        label: 'CA',
        data: <?= json_encode(array_column($serie, 'ca')) ?>,
        backgroundColor: '#2A78D6',
        borderRadius: 4
      },
      {
        label: 'CA N-1',
        data: <?= json_encode(array_column($serie, 'ca_n1')) ?>,
        backgroundColor: '#C9D6E5',
        borderRadius: 4
      }
    ]
  },
  options: {
    responsive: true,
    plugins: { legend: { position: 'bottom' } },
    scales: { y: { beginAtZero: true } }
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
