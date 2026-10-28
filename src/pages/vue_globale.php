<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/charges.php';
require_once __DIR__ . '/../inc/indicateurs.php';
require_once __DIR__ . '/../inc/pennylane.php';
require_once __DIR__ . '/../inc/ui.php';
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
    $classe = (float) $p['solde_bancaire'] < 0 ? 'text-danger fw-semibold' : 'fw-semibold';
    $alerte = solde_est_perime($p['solde_maj_le'])
        ? '<span class="stale" title="Mis à jour le ' . date('d/m/Y', strtotime($p['solde_maj_le'])) . '">ancien</span>'
        : '';
    return '<span class="' . $classe . '">' . eur((float) $p['solde_bancaire']) . '</span>' . $alerte;
}

function afficher_occupation(array $p): string
{
    if ($p['lots_total'] === null || (int) $p['lots_total'] === 0) {
        return '<span class="text-muted">—</span>';
    }
    $loues = (int) ($p['lots_loues'] ?? 0);
    $total = (int) $p['lots_total'];
    $taux  = $loues / $total * 100;
    $etat  = $taux >= 100 ? 'ok' : ($taux >= 75 ? 'warn' : 'bad');
    return '<div class="d-flex align-items-center justify-content-end gap-2">'
         . '<span class="' . ($loues < $total ? 'text-danger fw-semibold' : '') . '">' . $loues . '/' . $total . '</span>'
         . '<div class="meter ' . $etat . '" style="width:70px"><span style="width:' . round($taux) . '%"></span></div>'
         . '</div>';
}

function total_solde(array $liste): float
{
    $t = 0.0;
    foreach ($liste as $p) {
        $t += (float) ($p['solde_bancaire'] ?? 0);
    }
    return $t;
}

// --- Indicateurs consolidés -------------------------------------------------
$totalGeneral = total_solde($projects);
$nbPerimes    = count(array_filter($projects, fn($p) => $p['solde_bancaire'] !== null && solde_est_perime($p['solde_maj_le'])));
$nbSansSolde  = count(array_filter($projects, fn($p) => $p['solde_bancaire'] === null));
$nbNegatifs   = count(array_filter($projects, fn($p) => $p['solde_bancaire'] !== null && (float) $p['solde_bancaire'] < 0));

// Restaurants : chacun a ses propres indicateurs, jamais additionnés ni mis côte
// à côte dans un même graphique (ils ne proposent pas les mêmes services).
// La seule comparaison faite est celle de chaque restaurant avec lui-même à N-1.
$idsRestos = array_map(fn($p) => (int) $p['id'], $groupes['restaurants']);
$bMois = bornes_periode('mois');
$bHier = bornes_periode('jour');

$aujourdhui = new DateTimeImmutable('today');
$du30 = $aujourdhui->modify('-30 days')->format('Y-m-d');
$au30 = $aujourdhui->modify('-1 day')->format('Y-m-d');
$quotidien = ca_quotidien_societes($idsRestos, $du30, $au30);

$caMoisParResto   = ca_par_societe($idsRestos, $bMois['debut'], $bMois['fin']);
$caN1ParResto     = ca_par_societe($idsRestos, $bMois['n1_debut'], $bMois['n1_fin']);
$caHierParResto   = ca_par_societe($idsRestos, $bHier['debut'], $bHier['fin']);
$caHierN1ParResto = ca_par_societe($idsRestos, $bHier['n1_debut'], $bHier['n1_fin']);

$lotsTotal = 0;
$lotsLoues = 0;
foreach ($groupes['sci'] as $p) {
    $lotsTotal += (int) ($p['lots_total'] ?? 0);
    $lotsLoues += (int) ($p['lots_loues'] ?? 0);
}
$tauxOccupation = $lotsTotal > 0 ? $lotsLoues / $lotsTotal * 100 : null;

$tousIds  = array_map(fn($p) => (int) $p['id'], $projects);
$annee    = (int) date('Y');
$ytd      = resultat_annee($tousIds, $annee);
$ytdNet   = $ytd['revenus'] - $ytd['charges'];
$marge    = $ytd['revenus'] > 0 ? $ytdNet / $ytd['revenus'] * 100 : null;
$dernierMois = (int) get_db()->query("SELECT COALESCE(MAX(MONTH(period_date)), 0) FROM financial_entries WHERE YEAR(period_date) = " . $annee)->fetchColumn();
$ytdN1    = $dernierMois ? resultat_annee($tousIds, $annee - 1, $dernierMois) : null;
$varNet   = $ytdN1 ? ecart_pct($ytdNet, $ytdN1['revenus'] - $ytdN1['charges']) : null;

// Factures fournisseurs restant à payer d'après le dernier export Pennylane
try {
    $plProjets = pennylane_par_projet($annee . '-01-01', $annee . '-12-31');
    $plImport  = pennylane_dernier_import();
} catch (PDOException $e) {
    $plProjets = []; // migration 013 pas encore passée
    $plImport  = null;
}
$plReste = array_sum(array_column($plProjets, 'reste'));
$plEchu  = array_sum(array_column($plProjets, 'echu'));
$nomsProjets = array_column($projects, 'name', 'id');
uasort($plProjets, fn($a, $b) => $b['echu'] <=> $a['echu']);
$plRetards = array_filter($plProjets, fn($r, $pid) => $r['echu'] > 0.009 && isset($nomsProjets[$pid]), ARRAY_FILTER_USE_BOTH);

$urgentes = array_values(array_filter($alertes, fn($a) => in_array($a['alerte'], ['depasse', 'urgent'], true)));

$sections = [
    'restaurants' => ['titre' => 'Restaurants',          'icone' => 'utensils',  'classe' => 't-resto'],
    'sci'         => ['titre' => 'Immobilier locatif',   'icone' => 'home',      'classe' => 't-sci'],
    'mdb'         => ['titre' => 'Marchands de biens',   'icone' => 'briefcase', 'classe' => 't-mdb'],
    'autres'      => ['titre' => 'Holdings et autres',   'icone' => 'layers',    'classe' => 't-autres'],
];

// Répartition de la trésorerie, dans l'ordre des sections
$couleursFamille = ['restaurants' => '#0F7B55', 'sci' => '#1F5FAD', 'mdb' => '#A86A05', 'autres' => '#C8A84B'];
$repartition = [];
foreach ($sections as $cle => $s) {
    if ($groupes[$cle]) {
        $repartition[] = ['titre' => $s['titre'], 'total' => total_solde($groupes[$cle]), 'couleur' => $couleursFamille[$cle]];
    }
}

$moisLabel = format_mois_fr((int) date('Y'), (int) date('n'));

$pageTitle = "Vue d'ensemble";
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header(
    "Vue d'ensemble",
    count($projects) . ' sociétés actives · données au ' . date('d/m/Y'),
    '<a href="/pages/projects.php" class="btn btn-sm btn-outline-dark">' . icon('plus', 14) . ' Nouvelle société</a>',
    'AD Holding'
) ?>

<div class="kpi-grid">
  <?= kpi('Trésorerie consolidée', eur($totalGeneral), [
        'hero' => true, 'icon' => 'bank',
        'foot' => '<span>' . count($projects) . ' sociétés</span>'
                . ($nbNegatifs ? '<span style="color:#FCA5A5">' . $nbNegatifs . ' à découvert</span>' : '')
                . ($nbPerimes ? '<span>' . $nbPerimes . ' solde' . ($nbPerimes > 1 ? 's' : '') . ' ancien' . ($nbPerimes > 1 ? 's' : '') . '</span>' : '')
                . ($nbSansSolde && is_direction() ? '<a href="/pages/admin_tresorerie.php" style="color:inherit">' . $nbSansSolde . ' sans solde</a>' : ''),
      ]) ?>
  <?= kpi("Taux d'occupation", $tauxOccupation !== null ? pourcent($tauxOccupation) : '—', [
        'icon' => 'home',
        'foot' => $lotsTotal ? $lotsLoues . ' lots loués sur ' . $lotsTotal
                . ($lotsTotal - $lotsLoues > 0 ? ' · <span class="text-danger fw-semibold">' . ($lotsTotal - $lotsLoues) . ' vacant' . ($lotsTotal - $lotsLoues > 1 ? 's' : '') . '</span>' : '')
                : 'Aucun lot renseigné',
      ]) ?>
  <?= kpi('Résultat net ' . $annee, eur($ytdNet), [
        'icon' => 'trend',
        'foot' => ($marge !== null ? 'Marge ' . pourcent($marge, 1) : '')
                . ($varNet !== null ? ' ' . delta($varNet, false, 'vs N-1') : '')
                . ($dernierMois ? '<span>à fin ' . format_mois_fr($annee, $dernierMois) . '</span>' : ''),
      ]) ?>
  <?php if ($plImport): ?>
    <?= kpi('Fournisseurs à payer', eur($plReste), [
          'icon' => 'receipt', 'href' => '#retards-fournisseurs',
          'foot' => ($plEchu > 0.009 ? '<span class="text-danger fw-semibold">' . eur($plEchu) . ' échus</span> ' : '')
                  . '<span>Pennylane au ' . date('d/m', strtotime($plImport['imported_at'])) . '</span>',
        ]) ?>
  <?php endif; ?>
</div>

<?php if ($urgentes): ?>
  <div class="card border-danger mb-4">
    <div class="card-header bg-danger">
      <span class="d-flex align-items-center gap-2"><?= icon('alert', 16) ?> Échéances de contrats à traiter</span>
      <span class="badge bg-danger"><?= count($urgentes) ?></span>
    </div>
    <div class="card-body p-0">
      <table class="table">
        <tbody>
          <?php foreach (array_slice($urgentes, 0, 8) as $a): ?>
            <tr>
              <td style="width:28%">
                <a href="/pages/charges.php?id=<?= (int) $a['project_id'] ?>" class="row-link"><?= h($a['project_name']) ?></a>
              </td>
              <td><?= h($a['label']) ?></td>
              <td class="text-end">
                <?php if ($a['alerte'] === 'depasse'): ?>
                  <span class="badge bg-danger">Préavis dépassé</span>
                <?php else: ?>
                  <span class="badge bg-danger">Résilier avant le <?= date('d/m/Y', strtotime($a['date_limite_resiliation'])) ?></span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (count($urgentes) > 8): ?>
        <div class="text-muted small px-4 py-2">et <?= count($urgentes) - 8 ?> autre(s) échéance(s).</div>
      <?php endif; ?>
    </div>
  </div>
<?php endif; ?>

<?php if ($idsRestos): ?>
<div class="family-head mb-3">
  <span class="family-icon t-resto"><?= icon('utensils', 18) ?></span>
  <div>
    <div class="family-title">Restaurants</div>
    <div class="family-sub">Chaque restaurant est suivi séparément et comparé uniquement à lui-même l'an dernier.</div>
  </div>
</div>
<div class="row row-cols-1 row-cols-xl-2 g-4 mb-4">
  <?php foreach ($groupes['restaurants'] as $p):
    $pid    = (int) $p['id'];
    $caMois = $caMoisParResto[$pid] ?? 0.0;
    $caHier = $caHierParResto[$pid] ?? null;
  ?>
    <div class="col">
      <div class="card h-100 resto-card">
        <div class="card-header">
          <a href="/pages/project_dashboard.php?id=<?= $pid ?>" class="row-link"><?= h($p['name']) ?></a>
          <span class="card-hint">Trésorerie <?= afficher_solde($p) ?></span>
        </div>
        <div class="card-body">
          <div class="row g-3 mb-3">
            <div class="col-6">
              <div class="kpi-label">CA hier</div>
              <div class="resto-value num"><?= $caHier !== null ? eur($caHier) : '<span class="text-muted fw-normal">—</span>' ?></div>
              <div class="kpi-foot mt-1">
                <?= $caHier !== null && isset($caHierN1ParResto[$pid])
                    ? delta(ecart_pct($caHier, $caHierN1ParResto[$pid]), false, 'vs même jour N-1')
                    : '<span>' . date('d/m', strtotime($bHier['debut'])) . '</span>' ?>
              </div>
            </div>
            <div class="col-6">
              <div class="kpi-label">CA · <?= h($moisLabel) ?></div>
              <div class="resto-value num"><?= $caMois > 0 ? eur($caMois) : '<span class="text-muted fw-normal fs-6">pas de saisie</span>' ?></div>
              <div class="kpi-foot mt-1">
                <?= isset($caN1ParResto[$pid]) ? delta(ecart_pct($caMois, $caN1ParResto[$pid]), false, 'vs N-1') : '<span>Pas de comparaison N-1</span>' ?>
              </div>
            </div>
          </div>
          <div class="kpi-label mb-1">30 derniers jours · TTC</div>
          <?php if (array_sum($quotidien['series'][$pid] ?? []) > 0): ?>
            <div class="chart-box xs"><canvas id="chartResto<?= $pid ?>"></canvas></div>
          <?php else: ?>
            <div class="text-muted small py-3">Aucune vente remontée sur les 30 derniers jours.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-4 mb-4">
<?php if ($plRetards): ?>
  <div class="col-lg-8">
<?php ?>
<div class="card h-100" id="retards-fournisseurs">
  <div class="card-header">
    Factures fournisseurs échues
    <span class="card-hint">Reste à payer d'après Pennylane</span>
  </div>
  <div class="card-body p-0">
    <table class="table mb-0 align-middle">
      <thead><tr><th>Société</th><th class="text-end">Échu</th><th class="text-end">Total à payer</th></tr></thead>
      <tbody>
        <?php foreach (array_slice($plRetards, 0, 10, true) as $pid => $r): ?>
          <tr>
            <td><a class="row-link" href="/pages/comptabilite.php?id=<?= (int) $pid ?>"><?= h($nomsProjets[$pid]) ?></a></td>
            <td class="text-end fw-semibold text-danger"><?= eur($r['echu']) ?></td>
            <td class="text-end"><?= eur($r['reste']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
  </div>
<?php endif; ?>
<?php if ($repartition): ?>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Répartition de la trésorerie</div>
      <div class="card-body d-flex flex-column">
        <div class="chart-box sm"><canvas id="chartTreso"></canvas></div>
        <div class="mt-3 d-grid gap-2" style="font-size:.85rem">
          <?php foreach ($repartition as $r): ?>
            <div class="d-flex justify-content-between">
              <span><i style="display:inline-block;width:9px;height:9px;border-radius:3px;margin-right:8px;background:<?= $r['couleur'] ?>"></i><?= h($r['titre']) ?></span>
              <span class="fw-semibold num"><?= eur($r['total']) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
<?php endif; ?>
</div>

<?php foreach ($sections as $cle => $s):
  $liste = $groupes[$cle];
  if (!$liste || $cle === 'restaurants') continue; // famille vide, ou restaurants (cartes séparées plus haut)
?>
  <div class="card mb-4">
    <div class="card-header">
      <div class="family-head">
        <span class="family-icon <?= $s['classe'] ?>"><?= icon($s['icone'], 18) ?></span>
        <div>
          <div class="family-title"><?= h($s['titre']) ?></div>
          <div class="family-sub"><?= count($liste) ?> société<?= count($liste) > 1 ? 's' : '' ?></div>
        </div>
      </div>
      <div class="family-total">
        <div class="family-sub">Trésorerie</div>
        <div class="v"><?= eur(total_solde($liste)) ?></div>
      </div>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive">
      <table class="table align-middle">
        <thead>
          <tr>
            <th style="width:34%">Société</th>
            <th class="text-end">Trésorerie</th>
            <?php if ($cle === 'sci'): ?>
              <th class="text-end">Occupation</th>
            <?php elseif ($cle === 'mdb'): ?>
              <th class="text-end">Affaires en cours</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($liste as $p): $pid = (int) $p['id']; ?>
            <tr>
              <td><a href="/pages/project_dashboard.php?id=<?= $pid ?>" class="row-link"><?= h($p['name']) ?></a></td>
              <td class="text-end"><?= afficher_solde($p) ?></td>
              <?php if ($cle === 'sci'): ?>
                <td class="text-end"><?= afficher_occupation($p) ?></td>
              <?php elseif ($cle === 'mdb'): ?>
                <td class="text-end"><?= $p['affaires_en_cours'] !== null ? (int) $p['affaires_en_cours'] : '<span class="text-muted">—</span>' ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
<?php endforeach; ?>

<?php if (!$projects): ?>
  <div class="alert alert-secondary">
    Aucune société active. <a href="/pages/projects.php">Créer une société</a>.
  </div>
<?php endif; ?>

<script>
(function () {
  var c = ADHUB.colors;
  var labels = <?= json_encode($quotidien['labels']) ?>;
  // Un graphique par restaurant, chacun à sa propre échelle : pas de comparaison visuelle entre eux.
  var series = <?= json_encode((object) $quotidien['series']) ?>;
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

  if (document.getElementById('chartTreso')) new Chart(document.getElementById('chartTreso'), {
    type: 'doughnut',
    data: {
      labels: <?= json_encode(array_column($repartition, 'titre'), JSON_UNESCAPED_UNICODE) ?>,
      datasets: [{ data: <?= json_encode(array_map(fn($r) => max(0, $r['total']), $repartition)) ?>,
                   backgroundColor: <?= json_encode(array_column($repartition, 'couleur')) ?>, borderWidth: 2, borderColor: '#fff', hoverOffset: 4 }]
    },
    options: { cutout: '68%', plugins: { legend: { display: false }, tooltip: { mode: 'nearest', callbacks: { label: function (x) { return ' ' + ADHUB.eur(x.raw); } } } } }
  });
})();
</script>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
