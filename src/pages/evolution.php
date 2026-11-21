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
if (!in_array($vue, ['jour', 'semaine', 'mois', 'semaines', 'annee'], true)) {
    $vue = 'jour';
}
// Vues « historique » : une ligne par semaine ou par mois, chacune comparée à N-1
$historique = in_array($vue, ['semaines', 'annee'], true);

if ($historique) {
    $annee = (int) ($_GET['annee'] ?? date('Y'));
    if ($annee < 2000 || $annee > (int) date('Y')) {
        $annee = (int) date('Y');
    }
    $lignes = $vue === 'annee' ? comparaison_mois_annee($id, $annee) : comparaison_semaines($id, 12);
    $totCa = array_sum(array_column($lignes, 'ca'));
    $totN1 = array_sum(array_column($lignes, 'ca_n1'));
    $sansN1 = array_merge([], ...array_column($lignes, 'jours_sans_n1'));
} else {
    $comp  = comparatif_periode($id, $vue);
    $serie = serie_comparee($id, $comp['bornes']['debut'], $comp['bornes']['fin'], $comp['bornes']['n1_debut']);
}

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
  <?php foreach (['jour' => 'Hier', 'semaine' => 'Semaine', 'mois' => 'Mois', 'semaines' => '12 semaines', 'annee' => 'Mois par mois'] as $k => $lab): ?>
    <li class="nav-item">
      <a class="nav-link <?= $vue === $k ? 'active' : '' ?>"
         href="?id=<?= $id ?>&vue=<?= $k ?>"><?= $lab ?></a>
    </li>
  <?php endforeach; ?>
</ul>

<?php if ($historique): ?>

<?php if ($vue === 'annee'): ?>
  <div class="d-flex align-items-center gap-2 mb-3">
    <a class="btn btn-sm btn-outline-secondary" href="?id=<?= $id ?>&vue=annee&annee=<?= $annee - 1 ?>">&lsaquo; <?= $annee - 1 ?></a>
    <strong><?= $annee ?></strong>
    <?php if ($annee < (int) date('Y')): ?>
      <a class="btn btn-sm btn-outline-secondary" href="?id=<?= $id ?>&vue=annee&annee=<?= $annee + 1 ?>"><?= $annee + 1 ?> &rsaquo;</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="kpi-grid">
  <?= kpi($vue === 'annee' ? "CA $annee (à date)" : 'CA des 12 semaines', fmt_euro($totCa), [
        'hero' => true, 'icon' => 'receipt',
        'foot' => $totN1 > 0 ? delta(variation($totCa, $totN1)) . '<span>vs ' . fmt_euro($totN1) . ' N-1</span>' : '<span>Pas de données N-1</span>',
      ]) ?>
</div>

<?php if ($sansN1): ?>
  <div class="alert alert-secondary small"><?= h(note_jours_sans_n1($sansN1, 6)) ?></div>
<?php endif; ?>

<?php if ($totCa > 0 || $totN1 > 0): ?>
  <div class="card mb-4">
    <div class="card-header">
      Chiffre d'affaires <?= $vue === 'annee' ? 'par mois' : 'par semaine' ?>
      <span class="legend-inline"><span><i style="background:var(--ad-blue)"></i><?= $vue === 'annee' ? $annee : 'Cette année' ?></span><span><i style="background:var(--ad-chart-grey)"></i>N-1</span></span>
    </div>
    <div class="card-body"><div class="chart-box"><canvas id="chartHisto"></canvas></div></div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="card-body p-0">
    <div class="table-responsive">
    <table class="table table-sm mb-0">
      <thead>
        <tr>
          <th><?= $vue === 'annee' ? 'Mois' : 'Semaine' ?></th>
          <th class="text-end">CA</th>
          <th>Comparé à</th>
          <th class="text-end">CA N-1</th>
          <th class="text-end">Écart</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($lignes) as $l): ?>
          <tr>
            <td>
              <?= h($l['libelle']) ?>
              <?php if ($l['en_cours']): ?><span class="text-muted small">(au <?= date('d/m', strtotime($l['fin_calcul'])) ?>)</span><?php endif; ?>
            </td>
            <td class="text-end"><?= $l['ca'] > 0 ? fmt_euro($l['ca']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-muted small"><?= h(libelle_jour($l['n1_debut'], true)) ?> → <?= h(libelle_jour($l['n1_fin'], true)) ?></td>
            <td class="text-end text-muted"><?= $l['ca_n1'] > 0 ? fmt_euro($l['ca_n1']) : '—' ?></td>
            <td class="text-end">
              <?= badge_variation($l['var']) ?>
              <?php if ($l['jours_sans_n1'] && $l['ca_n1'] > 0): ?>
                <span class="text-muted small d-block" title="<?= h(note_jours_sans_n1($l['jours_sans_n1'], 10)) ?>"><?= count($l['jours_sans_n1']) ?> j. sans N-1</span>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>
</div>

<div class="alert alert-light border mt-4 small">
  <strong>Méthode de comparaison :</strong>
  chaque jour est comparé au même jour de la semaine l'an dernier, 364 jours plus tôt (52 semaines).
  <?= $vue === 'annee'
      ? 'Un mois est donc comparé à une période de même longueur décalée d\'un ou deux jours (novembre 2026 ↔ 2 novembre – 1er décembre 2025), avec autant de samedis et de dimanches des deux côtés.'
      : 'Une semaine du lundi au dimanche est comparée à la semaine du lundi au dimanche de même rang l\'an dernier.' ?>
  Le mois en cours s'arrête à hier, des deux côtés.
</div>

<script>
new Chart(document.getElementById('chartHisto'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_map(fn($l) => $vue === 'annee' ? ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'][(int) substr($l['cle'], 5, 2) - 1] : 'S' . substr($l['cle'], -2), $lignes)) ?>,
    datasets: [
      { label: 'CA', data: <?= json_encode(array_column($lignes, 'ca')) ?>, backgroundColor: ADHUB.colors.blue, maxBarThickness: 28 },
      { label: 'CA N-1', data: <?= json_encode(array_column($lignes, 'ca_n1')) ?>, backgroundColor: ADHUB.colors.grey, maxBarThickness: 28 }
    ]
  },
  options: {
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
    scales: { x: { grid: { display: false } },
              y: { beginAtZero: true, ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
  }
});
</script>

<?php else: ?>

<p class="text-muted">
  <?= htmlspecialchars($comp['libelle']) ?>
  &middot; comparé au
  <?= h(libelle_jour($comp['bornes']['n1_debut'], true)) ?>
  <?php if ($comp['bornes']['n1_debut'] !== $comp['bornes']['n1_fin']): ?>
    → <?= h(libelle_jour($comp['bornes']['n1_fin'], true)) ?>
  <?php endif; ?>
</p>

<?php if ($comp['n1_disponible'] && $comp['jours_sans_n1']): ?>
  <div class="alert alert-secondary small"><?= h(note_jours_sans_n1($comp['jours_sans_n1'])) ?> Ces jours comptent pour 0 € côté N-1.</div>
<?php endif; ?>

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
      <span class="legend-inline"><span><i style="background:var(--ad-blue)"></i>Période</span><span><i style="background:var(--ad-chart-grey)"></i>N-1</span></span>
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
          <th>Comparé au</th>
          <th class="text-end">CA N-1</th>
          <th class="text-end">Écart</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach (array_reverse($serie) as $s):
          $ecart = variation($s['ca'], $s['ca_n1']); ?>
          <tr>
            <td><?= h($s['jour']) ?></td>
            <td class="text-end"><?= $s['ca'] > 0 ? fmt_euro($s['ca']) : '<span class="text-muted">—</span>' ?></td>
            <td class="text-muted small"><?= h($s['jour_n1']) ?></td>
            <td class="text-end text-muted"><?= $s['n1_manquant'] ? '<span class="small">pas de données</span>' : fmt_euro($s['ca_n1']) ?></td>
            <td class="text-end"><?= badge_variation($ecart) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="alert alert-light border mt-4 small">
  <strong>Méthode de comparaison :</strong>
  la référence est prise 364 jours plus tôt (52 semaines), et non à la même date, pour comparer
  un vendredi à un vendredi. Le jour de la semaine pèse davantage que la date dans l'activité
  d'un restaurant.
  <?php if ($vue === 'mois'): ?>
    Le mois en cours est comparé au même nombre de jours l'an dernier, décalé d'un ou deux jours
    pour garder autant de samedis et de dimanches des deux côtés.
  <?php endif; ?>
</div>

<?php endif; /* fin vues jour / semaine / mois */ ?>

<?php if (!$historique && $comp['actuel']['jours_saisis'] > 0 && count($serie) > 1): ?>
<script>
new Chart(document.getElementById('chartPeriode'), {
  type: 'bar',
  data: {
    labels: <?= json_encode(array_column($serie, 'jour')) ?>,
    datasets: [
      {
        label: 'CA',
        data: <?= json_encode(array_column($serie, 'ca')) ?>,
        backgroundColor: ADHUB.colors.blue,
        maxBarThickness: 28
      },
      {
        label: 'CA N-1',
        data: <?= json_encode(array_column($serie, 'ca_n1')) ?>,
        backgroundColor: ADHUB.colors.grey,
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
