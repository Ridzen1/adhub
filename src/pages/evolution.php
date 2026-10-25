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

<?= company_header($project, 'evolution') ?>

<ul class="nav nav-pills mb-3">
  <?php foreach (['jour' => 'Hier', 'semaine' => 'Semaine', 'mois' => 'Mois'] as $k => $lab): ?>
    <li class="nav-item">
      <a class="nav-link <?= $vue === $k ? 'active' : '' ?>"
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

<div class="kpi-grid">
  <?= kpi("Chiffre d'affaires", fmt_euro($comp['actuel']['ca']), [
        'hero' => true, 'icon' => 'receipt',
        'foot' => $comp['n1_disponible'] ? delta($comp['var_ca']) . '<span>vs ' . fmt_euro($comp['n1']['ca']) . ' N-1</span>' : '',
      ]) ?>
  <?= kpi('Couverts', number_format($comp['actuel']['couverts'], 0, ',', "\u{202F}"), [
        'icon' => 'users',
        'foot' => $comp['n1_disponible'] ? delta($comp['var_couverts']) . '<span>vs ' . number_format($comp['n1']['couverts'], 0, ',', "\u{202F}") . '</span>' : '',
      ]) ?>
  <?= kpi('Panier moyen', $comp['actuel']['ticket_moyen'] !== null ? eur($comp['actuel']['ticket_moyen'], 2) : '—', [
        'icon' => 'wallet',
        'foot' => $comp['n1_disponible'] ? delta($comp['var_ticket']) . '<span>vs ' . ($comp['n1']['ticket_moyen'] !== null ? eur($comp['n1']['ticket_moyen'], 2) : '—') . '</span>' : '',
      ]) ?>
  <?= kpi('Jours saisis', (string) $comp['actuel']['jours_saisis'], [
        'icon' => 'calendar', 'foot' => $comp['n1_disponible'] ? '<span>' . $comp['n1']['jours_saisis'] . ' sur la période N-1</span>' : '',
      ]) ?>
</div>

<?php if ($comp['actuel']['jours_saisis'] === 0): ?>
  <div class="alert alert-warning">
    Aucune saisie sur cette période.
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>">Saisir les journées</a>.
  </div>
<?php elseif (count($serie) > 1): ?>
  <div class="card mb-4">
    <div class="card-header">
      Chiffre d'affaires jour par jour
      <span class="legend-inline"><span><i style="background:#1F5FAD"></i>Période</span><span><i style="background:#CBD2DB"></i>N-1</span></span>
    </div>
    <div class="card-body"><div class="chart-box"><canvas id="chartPeriode"></canvas></div></div>
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
<script>
new Chart(document.getElementById('chartPeriode'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($serie, 'libelle')) ?>,
    datasets: [
      {
        label: 'CA',
        data: <?= json_encode(array_column($serie, 'ca')) ?>,
        backgroundColor: '#1F5FAD',
        maxBarThickness: 28
      },
      {
        label: 'CA N-1',
        data: <?= json_encode(array_column($serie, 'ca_n1')) ?>,
        backgroundColor: '#CBD2DB',
        maxBarThickness: 28
      }
    ]
  },
  options: {
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
    scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
              y: { beginAtZero: true, ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
