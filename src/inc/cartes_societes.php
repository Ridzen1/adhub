<?php
/**
 * Cartes « Immobilier locatif » (SCI) et « Marchands de biens » (MDB) de « Mes sociétés » :
 * même présentation que les cartes restaurants (src/inc/cartes_restaurants.php).
 *
 * Chaque société a sa propre carte et son propre graphique à sa propre échelle :
 * jamais d'addition ni de comparaison entre sociétés, seulement avec elle-même (mois précédent, N-1).
 * La trésorerie est gardée : contrairement aux restaurants, ces sociétés en remontent une.
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/daily.php';
require_once __DIR__ . '/financials.php';
require_once __DIR__ . '/indicateurs.php';
require_once __DIR__ . '/qalimo.php';
require_once __DIR__ . '/ui.php';

/** Familles affichées en cartes détaillées, dans l'ordre de la vue d'ensemble */
const FAMILLES_CARTES = [
    'sci' => ['titre' => 'Immobilier locatif', 'icone' => 'home',      'classe' => 't-sci'],
    'mdb' => ['titre' => 'Marchands de biens', 'icone' => 'briefcase', 'classe' => 't-mdb'],
];

/** Famille d'une société pour ces cartes (null : pas de carte détaillée) */
function famille_carte(?string $typeCode): ?string
{
    return match ($typeCode) {
        'immobilier', 'restauration_murs' => 'sci',
        'mdb'                             => 'mdb',
        default                           => null,
    };
}

/** Saisies mensuelles des 12 derniers mois (mois courant inclus), indexées par société puis par mois */
function saisies_12_mois(array $ids): array
{
    if (!$ids) {
        return [];
    }
    $in   = implode(',', array_map('intval', $ids));
    $stmt = get_db()->prepare(
        "SELECT project_id, DATE_FORMAT(period_date, '%Y-%m') AS mois, revenus, charges
         FROM financial_entries
         WHERE project_id IN ($in) AND period_date >= :d"
    );
    $stmt->execute(['d' => (new DateTimeImmutable('first day of this month'))->modify('-11 months')->format('Y-m-d')]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['project_id']][$r['mois']] = ['revenus' => (float) $r['revenus'], 'charges' => (float) $r['charges']];
    }
    return $out;
}

/**
 * Affiche, pour une famille (sci ou mdb), le titre, une carte par société et le script des graphiques.
 * $societes : lignes de projets, déjà limitées à celles dont l'utilisateur voit le tableau de bord.
 */
function afficher_cartes_famille(string $famille, array $societes): void
{
    if (!$societes || !isset(FAMILLES_CARTES[$famille])) {
        return;
    }
    $f   = FAMILLES_CARTES[$famille];
    $ids = array_map(fn($p) => (int) $p['id'], $societes);

    // Axe des 12 derniers mois, commun aux graphiques (chacun garde sa propre échelle)
    $debut  = (new DateTimeImmutable('first day of this month'))->modify('-11 months');
    $moisCles = [];
    $labels   = [];
    for ($i = 0; $i < 12; $i++) {
        $m = $debut->modify("+$i months");
        $moisCles[] = $m->format('Y-m');
        $labels[]   = mois_court($m->format('Y-m-d'));
    }
    $saisies = saisies_12_mois($ids);
    $annee   = (int) date('Y');
    $series  = [];
    ?>
<div class="family-head mb-3">
  <span class="family-icon <?= $f['classe'] ?>"><?= icon($f['icone'], 18) ?></span>
  <div>
    <div class="family-title"><?= h($f['titre']) ?></div>
    <div class="family-sub">Chaque société est suivie séparément et comparée uniquement à elle-même.</div>
  </div>
</div>
<div class="row row-cols-1 row-cols-xl-2 g-4 mb-4">
  <?php foreach ($societes as $p):
    $pid    = (int) $p['id'];
    $latest = get_latest_entry($pid);
    $net    = $latest ? (float) $latest['revenus'] - (float) $latest['charges'] : null;

    // Résultat du dernier mois saisi, comparé au même mois de l'année précédente
    $netN1 = null;
    if ($latest) {
        $n1 = get_db()->prepare("SELECT revenus, charges FROM financial_entries WHERE project_id = :pid AND period_date = :d");
        $n1->execute(['pid' => $pid, 'd' => date('Y-m-d', strtotime($latest['period_date'] . ' -1 year'))]);
        if ($r = $n1->fetch()) {
            $netN1 = (float) $r['revenus'] - (float) $r['charges'];
        }
    }

    // Cumul de l'année, comparé à la même période N-1
    $moisAnnee = $latest && substr($latest['period_date'], 0, 4) === (string) $annee ? (int) substr($latest['period_date'], 5, 2) : 0;
    $ytd       = $moisAnnee ? resultat_annee([$pid], $annee) : null;
    $ytdNet    = $ytd ? $ytd['revenus'] - $ytd['charges'] : null;
    $ytdN1     = $moisAnnee ? resultat_annee([$pid], $annee - 1, $moisAnnee) : null;
    $varYtd    = $ytdN1 && $ytdN1['mois'] ? ecart_pct($ytdNet, $ytdN1['revenus'] - $ytdN1['charges']) : null;

    // Occupation : Qalimo si la société y est reliée, sinon la fiche
    $immo = $famille === 'sci' ? qalimo_resume_projet($pid) : null;
    $lotsTotal = $immo ? (int) $immo['total'] : (int) ($p['lots_total'] ?? 0);
    $lotsLoues = $immo ? (int) $immo['loues'] : (int) ($p['lots_loues'] ?? 0);

    $soldePerime = $p['solde_maj_le'] !== null && new DateTime($p['solde_maj_le']) < new DateTime('-30 days');

    $serie = ['revenus' => [], 'charges' => []];
    foreach ($moisCles as $m) {
        $serie['revenus'][] = $saisies[$pid][$m]['revenus'] ?? null;
        $serie['charges'][] = $saisies[$pid][$m]['charges'] ?? null;
    }
    if (isset($saisies[$pid])) {
        $series[$pid] = $serie;
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
              <div class="kpi-label">Trésorerie</div>
              <div class="resto-value num <?= $p['solde_bancaire'] !== null && (float) $p['solde_bancaire'] < 0 ? 'text-danger' : '' ?>">
                <?= $p['solde_bancaire'] !== null ? eur((float) $p['solde_bancaire']) : '<span class="text-muted fw-normal fs-6">non renseignée</span>' ?>
              </div>
              <div class="kpi-foot mt-1">
                <?php if ($p['solde_bancaire'] !== null && $p['solde_maj_le']): ?>
                  <span>au <?= date('d/m/Y', strtotime($p['solde_maj_le'])) ?></span><?= $soldePerime ? '<span class="stale">ancien</span>' : '' ?>
                <?php endif; ?>
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-label">Résultat net<?= $latest ? ' · ' . h(mois_court($latest['period_date'])) : '' ?></div>
              <div class="resto-value num <?= $net !== null && $net < 0 ? 'text-danger' : '' ?>">
                <?= $net !== null ? eur($net) : '<span class="text-muted fw-normal fs-6">pas de saisie</span>' ?>
              </div>
              <div class="kpi-foot mt-1">
                <?= $net !== null && $netN1 !== null ? delta(ecart_pct($net, $netN1), false, 'vs N-1') : ($net !== null ? '<span>Pas de comparaison N-1</span>' : '') ?>
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-label">Résultat <?= $annee ?></div>
              <div class="fw-bold fs-5 num <?= $ytdNet !== null && $ytdNet < 0 ? 'text-danger' : '' ?>">
                <?= $ytdNet !== null ? eur($ytdNet) : '<span class="text-muted fw-normal fs-6">—</span>' ?>
              </div>
              <div class="kpi-foot mt-1">
                <?= $varYtd !== null ? delta($varYtd, false, 'vs N-1') : ($moisAnnee ? '<span>à fin ' . h(format_mois_fr($annee, $moisAnnee)) . '</span>' : '') ?>
              </div>
            </div>
            <div class="col-6">
              <?php if ($famille === 'sci'): ?>
                <div class="kpi-label">Occupation</div>
                <?php if ($lotsTotal > 0):
                  $taux = $lotsLoues / $lotsTotal * 100;
                  $etat = $taux >= 100 ? 'ok' : ($taux >= 75 ? 'warn' : 'bad');
                ?>
                  <div class="fw-bold fs-5 num <?= $lotsLoues < $lotsTotal ? 'text-danger' : '' ?>"><?= $lotsLoues ?>/<?= $lotsTotal ?> <span class="fs-6 fw-normal text-muted">lots loués</span></div>
                  <div class="meter <?= $etat ?> mt-2"><span style="width:<?= round($taux) ?>%"></span></div>
                  <?php if ($immo && $immo['impayes'] > 0.009): ?>
                    <div class="kpi-foot mt-1"><span class="text-danger fw-semibold"><?= eur($immo['impayes']) ?> d'impayés</span></div>
                  <?php endif; ?>
                <?php else: ?>
                  <div class="fw-bold fs-5"><span class="text-muted fw-normal fs-6">—</span></div>
                <?php endif; ?>
              <?php else: ?>
                <div class="kpi-label">Affaires en cours</div>
                <div class="fw-bold fs-5 num"><?= $p['affaires_en_cours'] !== null ? (int) $p['affaires_en_cours'] : '<span class="text-muted fw-normal fs-6">—</span>' ?></div>
              <?php endif; ?>
            </div>
          </div>
          <div class="kpi-label mb-1">12 derniers mois · revenus et charges</div>
          <?php if (isset($series[$pid])): ?>
            <div class="chart-box xs"><canvas id="chartSoc<?= $pid ?>"></canvas></div>
          <?php else: ?>
            <div class="text-muted small py-3">Aucune saisie mensuelle sur les 12 derniers mois.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<script>
(function () {
  var c = ADHUB.colors;
  var labels = <?= json_encode($labels, JSON_UNESCAPED_UNICODE) ?>;
  // Un graphique par société, chacun à sa propre échelle : pas de comparaison visuelle entre elles.
  var series = <?= json_encode((object) $series) ?>;
  Object.keys(series).forEach(function (id) {
    var el = document.getElementById('chartSoc' + id);
    if (!el) return;
    new Chart(el, {
      type: 'bar',
      data: { labels: labels, datasets: [
        { label: 'Revenus', data: series[id].revenus, backgroundColor: c.blue, maxBarThickness: 12 },
        { label: 'Charges', data: series[id].charges, backgroundColor: c.grey, maxBarThickness: 12 }
      ] },
      options: {
        plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw || 0); } } } },
        scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                  y: { beginAtZero: true, ticks: { maxTicksLimit: 4, callback: function (v) { return ADHUB.eurShort(v); } } } }
      }
    });
  });
})();
</script>
<?php
}
