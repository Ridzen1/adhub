<?php
/**
 * Cartes « Restaurants » : CA d'hier, CA du mois vs N-1, objectif, ventes des 30 derniers jours.
 * Utilisées par la vue d'ensemble (direction) et par « Mes sociétés » (associés, équipes).
 *
 * Chaque restaurant a sa propre carte et son propre graphique à sa propre échelle :
 * jamais d'addition ni de comparaison entre restaurants, seulement avec lui-même à N-1.
 * Pas de trésorerie non plus : les restaurants n'en remontent pas.
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/financials.php';
require_once __DIR__ . '/indicateurs.php';
require_once __DIR__ . '/objectifs.php';
require_once __DIR__ . '/ui.php';

/**
 * Affiche le titre de la famille, une carte par restaurant et le script des graphiques.
 * $restaurants : lignes de projets (id, name), déjà limitées à ce que l'utilisateur peut voir.
 */
function afficher_cartes_restaurants(array $restaurants): void
{
    if (!$restaurants) {
        return;
    }

    $ids   = array_map(fn($p) => (int) $p['id'], $restaurants);
    $bMois = bornes_periode('mois');
    $bHier = bornes_periode('jour');

    $aujourdhui = new DateTimeImmutable('today');
    $quotidien  = ca_quotidien_societes(
        $ids,
        $aujourdhui->modify('-30 days')->format('Y-m-d'),
        $aujourdhui->modify('-1 day')->format('Y-m-d')
    );

    $caMois   = ca_par_societe($ids, $bMois['debut'], $bMois['fin']);
    $caMoisN1 = ca_par_societe($ids, $bMois['n1_debut'], $bMois['n1_fin']);
    $caHier   = ca_par_societe($ids, $bHier['debut'], $bHier['fin']);
    $caHierN1 = ca_par_societe($ids, $bHier['n1_debut'], $bHier['n1_fin']);

    $moisLabel  = format_mois_fr((int) date('Y'), (int) date('n'));
    $peutFixer  = is_direction(); // seule la direction fixe les objectifs
    $objectifsOk = objectifs_disponibles();
    $series     = [];
    ?>
<div class="family-head mb-3">
  <span class="family-icon t-resto"><?= icon('utensils', 18) ?></span>
  <div>
    <div class="family-title">Restaurants</div>
    <div class="family-sub">Chaque restaurant est suivi séparément et comparé uniquement à lui-même l'an dernier.</div>
  </div>
</div>
<div class="row row-cols-1 row-cols-xl-2 g-4 mb-4">
  <?php foreach ($restaurants as $p):
    $pid      = (int) $p['id'];
    $mois     = $caMois[$pid] ?? 0.0;
    $hier     = $caHier[$pid] ?? null;
    $objectif = $objectifsOk ? etat_objectif($pid) : null;
    if (array_sum($quotidien['series'][$pid] ?? []) > 0) {
        $series[$pid] = $quotidien['series'][$pid];
    }
  ?>
    <div class="col">
      <div class="card h-100 resto-card">
        <div class="card-header">
          <a href="/pages/project_dashboard.php?id=<?= $pid ?>" class="row-link"><?= h($p['name']) ?></a>
        </div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-6">
              <div class="kpi-label">CA hier</div>
              <div class="resto-value num"><?= $hier !== null ? eur($hier) : '<span class="text-muted fw-normal">—</span>' ?></div>
              <div class="kpi-foot mt-1">
                <?= $hier !== null && isset($caHierN1[$pid])
                    ? delta(ecart_pct($hier, $caHierN1[$pid]), false, 'vs même jour N-1')
                    : '<span>' . date('d/m', strtotime($bHier['debut'])) . '</span>' ?>
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-label">CA · <?= h($moisLabel) ?></div>
              <div class="resto-value num"><?= $mois > 0 ? eur($mois) : '<span class="text-muted fw-normal fs-6">pas de saisie</span>' ?></div>
              <div class="kpi-foot mt-1">
                <?= isset($caMoisN1[$pid]) ? delta(ecart_pct($mois, $caMoisN1[$pid]), false, 'vs N-1') : '<span>Pas de comparaison N-1</span>' ?>
              </div>
            </div>
          </div>
          <?php if ($objectif): ?>
            <div class="mb-3"><?= bloc_objectif($objectif, $p['name'], true) ?></div>
          <?php elseif ($objectifsOk): ?>
            <div class="goal-empty mb-3"><?= icon('target', 14) ?> Pas d'objectif ce mois-ci<?= $peutFixer ? ' · <a href="/pages/objectifs.php?id=' . $pid . '#fixer">en fixer un</a>' : '' ?></div>
          <?php endif; ?>
          <div class="kpi-label mb-1">30 derniers jours · TTC</div>
          <?php if (isset($series[$pid])): ?>
            <div class="chart-box xs"><canvas id="chartResto<?= $pid ?>"></canvas></div>
          <?php else: ?>
            <div class="text-muted small py-3">Aucune vente remontée sur les 30 derniers jours.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var c = ADHUB.colors;
  var labels = <?= json_encode($quotidien['labels']) ?>;
  // Un graphique par restaurant, chacun à sa propre échelle : pas de comparaison visuelle entre eux.
  var series = <?= json_encode((object) $series) ?>;
  Object.keys(series).forEach(function (id) {
    var el = document.getElementById('chartResto' + id);
    if (!el) return;
    new Chart(el, {
      type: 'bar',
      data: { labels: labels, datasets: [{ label: 'CA', data: series[id], backgroundColor: c.green, maxBarThickness: 14 }] },
      options: {
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + ADHUB.eur(x.raw); } } } },
        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 16 } },
                  y: { beginAtZero: true, ticks: { maxTicksLimit: 4, callback: function (v) { return ADHUB.eurShort(v); } } } }
      }
    });
  });
})();
</script>
<?php
}
