<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/financials.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/periodes.php';
require_once __DIR__ . '/../inc/indicateurs.php';
require_once __DIR__ . '/../inc/objectifs.php';
require_once __DIR__ . '/../inc/qalimo.php';
require_once __DIR__ . '/../inc/ui.php';
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

// Les restaurants ont un bloc d'indicateurs quotidiens en plus du suivi mensuel.
$estRestaurant = is_restaurant($project);
$kpisResto     = $estRestaurant ? get_restaurant_kpis($id) : null;


$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_entry') {
    require_project_edit($id); // bloque un POST forgé par un utilisateur en lecture seule

    $period   = $_POST['period_date'] ?? '';
    $revenus  = (float) str_replace(',', '.', $_POST['revenus'] ?? '0');
    $charges  = (float) str_replace(',', '.', $_POST['charges'] ?? '0');
    $notes    = trim($_POST['notes'] ?? '') ?: null;

    if (!preg_match('/^\d{4}-\d{2}$/', $period)) {
        $errors[] = "Choisis un mois valide.";
    } else {
        upsert_financial_entry($id, $period . '-01', $revenus, $charges, $notes);
        header("Location: /pages/project_dashboard.php?id={$id}&saved=1");
        exit;
    }
}

$entries = get_financial_entries($id);
$latest  = get_latest_entry($id);
$ytd     = get_ytd_summary($id, (int) date('Y'));

$latestNet = $latest ? ((float) $latest['revenus'] - (float) $latest['charges']) : null;
$ytdNet    = (float) $ytd['revenus'] - (float) $ytd['charges'];
$ytdMarge  = (float) $ytd['revenus'] > 0 ? $ytdNet / (float) $ytd['revenus'] * 100 : null;

// Mois précédent le dernier mois saisi, pour la variation du résultat
$avantDernier = count($entries) >= 2 ? $entries[count($entries) - 2] : null;
$ecartNetMois = $avantDernier ? $latestNet - ((float) $avantDernier['revenus'] - (float) $avantDernier['charges']) : null;

// Cumul N-1 à la même date (même nombre de mois), pour situer l'année en cours
$moisSaisisAnnee = $latest && substr($latest['period_date'], 0, 4) === date('Y') ? (int) substr($latest['period_date'], 5, 2) : 0;
$ytdN1 = $moisSaisisAnnee ? resultat_annee([$id], (int) date('Y') - 1, $moisSaisisAnnee) : null;
$varYtd = $ytdN1 && $ytdN1['mois'] ? ecart_pct($ytdNet, $ytdN1['revenus'] - $ytdN1['charges']) : null;

// Les 12 derniers mois pour le graphique
$entries12    = array_slice($entries, -12);
$chartLabels  = array_map(fn($e) => mois_court($e['period_date']), $entries12);
$chartRevenus = array_map(fn($e) => (float) $e['revenus'], $entries12);
$chartCharges = array_map(fn($e) => (float) $e['charges'], $entries12);
$chartNet     = array_map(fn($e) => (float) $e['revenus'] - (float) $e['charges'], $entries12);

// Restaurant : activité récente, comparée à N-1 (même jour de la semaine)
if ($estRestaurant) {
    $compHier  = comparatif_periode($id, 'jour');
    $compSem   = comparatif_periode($id, 'semaine');
    $compMois  = comparatif_periode($id, 'mois');
    $aujourdhui = new DateTimeImmutable('today');
    $serie30   = serie_comparee($id, $aujourdhui->modify('-30 days')->format('Y-m-d'), $aujourdhui->modify('-1 day')->format('Y-m-d'),
                                $aujourdhui->modify('-30 days')->modify('-' . DECALAGE_N1_JOURS . ' days')->format('Y-m-d'));
    $mixStmt = get_db()->prepare(
        "SELECT SUM(ca_solide) AS solide, SUM(ca_liquide) AS liquide FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :d AND :f"
    );
    $mixStmt->execute(['pid' => $id, 'd' => $compMois['bornes']['debut'], 'f' => $compMois['bornes']['fin']]);
    $mix = $mixStmt->fetch();
    $mixTotal = (float) $mix['solide'] + (float) $mix['liquide'];
    $partSolide = $mixTotal > 0 ? (float) $mix['solide'] / $mixTotal * 100 : null;

    // Projection du mois : CA moyen par jour calendaire écoulé × nombre de jours du mois.
    // Les jours de fermeture sont ainsi lissés ; à partir de 3 jours, sinon c'est du bruit.
    $joursEcoules = (int) $aujourdhui->format('j') - (get_daily_entry($id, $aujourdhui->format('Y-m-d')) ? 0 : 1);
    $projection = $joursEcoules >= 3
        ? $compMois['actuel']['ca'] / $joursEcoules * (int) $aujourdhui->format('t')
        : null;

    // Objectif du mois fixé par la direction (null si aucun, ou migration 016 absente)
    $objectif = etat_objectif($id);
}

// Immobilier : occupation et trésorerie de la fiche
$lotsTotal = (int) ($project['lots_total'] ?? 0);
$lotsLoues = (int) ($project['lots_loues'] ?? 0);

// Gestion locative Qalimo : biens, baux et loyers (null si la société n'a aucun bien relié)
$immo = qalimo_resume_projet($id);

$metaHtml = [];
if (!empty($project['siret_siren'])) {
    $metaHtml[] = 'SIREN ' . h($project['siret_siren']);
}
if (!empty($project['adresse'])) {
    $metaHtml[] = h($project['adresse']);
}
if ($project['solde_bancaire'] !== null) {
    $metaHtml[] = 'Trésorerie <strong class="text-body">' . eur((float) $project['solde_bancaire']) . '</strong>'
                . ($project['solde_maj_le'] ? ' au ' . date('d/m/Y', strtotime($project['solde_maj_le'])) : '');
}

$actions = '';
if ($estRestaurant && $canEdit) {
    $actions = '<a href="/pages/saisie_quotidienne.php?id=' . $id . '" class="btn btn-sm btn-dark">' . icon('plus', 14) . ' Saisir une journée</a>';
}

$pageTitle = $project['name'] . ' — Tableau de bord';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'dashboard', $actions, implode('<span class="text-muted">·</span>', $metaHtml)) ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Saisie enregistrée.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<?php if ($estRestaurant): ?>
  <h6 class="text-muted mb-3">Activité</h6>
  <div class="kpi-grid">
    <?= kpi('CA ' . ($compHier['actuel']['jours_saisis'] ? 'd\'hier' : 'hier'), $compHier['actuel']['jours_saisis'] ? eur($compHier['actuel']['ca']) : '<span class="text-muted" style="font-size:1rem">Pas de saisie</span>', [
          'icon' => 'calendar', 'hero' => true,
          'foot' => '<span>' . date('d/m', strtotime($compHier['bornes']['debut'])) . '</span>' . delta($compHier['var_ca'], false, 'vs N-1'),
        ]) ?>
    <?= kpi('CA de la semaine', eur($compSem['actuel']['ca']), [
          'icon' => 'trend',
          'foot' => $compSem['n1_disponible'] ? delta($compSem['var_ca'], false, 'vs N-1') : '<span>' . $compSem['actuel']['jours_saisis'] . ' j. saisis</span>',
        ]) ?>
    <?= kpi('CA du mois', eur($compMois['actuel']['ca']), [
          'icon' => 'receipt', 'spark' => 'sparkMois',
          'foot' => ($compMois['n1_disponible'] ? delta($compMois['var_ca'], false, 'vs N-1') : '')
                  . ($projection ? '<span>Projection ' . eur_court($projection) . '</span>' : ''),
        ]) ?>
    <?php if ($compMois['actuel']['couverts'] > 0): ?>
      <?= kpi('Couverts du mois', number_format($compMois['actuel']['couverts'], 0, ',', "\u{202F}"), [
            'icon' => 'users',
            'foot' => 'Panier moyen <strong class="text-body">' . eur($compMois['actuel']['ticket_moyen'], 2) . '</strong>'
                    . ($compMois['n1_disponible'] ? ' ' . delta($compMois['var_ticket']) : ''),
          ]) ?>
    <?php endif; ?>
    <?php if ($partSolide !== null): ?>
      <?= kpi('Solide / liquide', pourcent($partSolide) . ' <small>/ ' . pourcent(100 - $partSolide) . '</small>', [
            'icon' => 'pie',
            'foot' => '<div class="meter" style="flex:1;min-width:80px"><span style="width:' . round($partSolide) . '%;background:#0F7B55"></span></div>',
          ]) ?>
    <?php endif; ?>
  </div>

  <?php if ($objectif): ?>
    <div class="card mb-4">
      <div class="card-body">
        <?= bloc_objectif($objectif, $project['name']) ?>
        <div class="text-end mt-2 small"><a href="/pages/objectifs.php?id=<?= $id ?>">Tous les objectifs →</a></div>
      </div>
    </div>
  <?php elseif (is_direction() && objectifs_disponibles()): ?>
    <div class="goal-empty mb-4">
      <?= icon('target', 16) ?> Aucun objectif de CA pour ce mois.
      <a href="/pages/objectifs.php?id=<?= $id ?>#fixer">Fixer un objectif</a>
    </div>
  <?php endif; ?>

  <div class="card mb-4">
    <div class="card-header">
      Chiffre d'affaires quotidien
      <span class="legend-inline">
        <span><i style="background:#1F5FAD"></i>30 derniers jours</span>
        <span><i style="background:#CBD2DB"></i>Même jour N-1</span>
        <a href="/pages/analyse_caisse.php?id=<?= $id ?>" class="ms-2">Analyse détaillée →</a>
      </span>
    </div>
    <div class="card-body">
      <?php if (array_sum(array_column($serie30, 'ca')) > 0): ?>
        <div class="chart-box"><canvas id="chartResto"></canvas></div>
      <?php else: ?>
        <div class="empty-state">
          Aucune donnée quotidienne sur les 30 derniers jours.
          <?php if ($canEdit): ?><a href="/pages/saisie_quotidienne.php?id=<?= $id ?>">Commencer la saisie</a>.<?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <h6 class="text-muted mb-3">Suivi mensuel</h6>
<?php endif; ?>

<?php if ($immo):
  $moisImmo = $immo['mois'];
  $tauxEnc  = $moisImmo['appele'] > 0 ? $moisImmo['encaisse'] / $moisImmo['appele'] * 100 : null;
  $vacants  = $immo['total'] - $immo['loues'];
  $ech      = $immo['echeance']; ?>
  <h6 class="text-muted mb-3">Immobilier <span class="fw-normal small">· Qalimo<?= $immo['sync'] ? ', mis à jour le ' . date('d/m à H\hi', strtotime($immo['sync'])) : '' ?></span></h6>
  <div class="kpi-grid">
    <?= kpi('Occupation', $immo['loues'] . ' <small>/ ' . $immo['total'] . ' lots</small>', [
          'icon' => 'home', 'hero' => true,
          'foot' => '<span>' . pourcent($immo['loues'] / $immo['total'] * 100) . '</span>'
                  . ($vacants ? '<span>' . $vacants . ' vacant' . ($vacants > 1 ? 's' : '') . '</span>' : '<span>Tout est loué</span>')
                  . ($immo['preavis'] ? '<span>' . $immo['preavis'] . ' préavis</span>' : ''),
        ]) ?>
    <?= kpi('Loyers appelés · ' . format_mois_fr((int) date('Y'), (int) date('n')), eur($moisImmo['appele']), [
          'icon' => 'receipt',
          'foot' => 'Encaissés <strong class="text-body">' . eur($moisImmo['encaisse']) . '</strong>'
                  . ($tauxEnc !== null ? '<span>' . pourcent(min($tauxEnc, 999)) . '</span>' : ''),
        ]) ?>
    <?= kpi('Impayés locataires', '<span class="' . ($immo['impayes'] > 0 ? 'text-danger' : '') . '">' . eur($immo['impayes']) . '</span>', [
          'icon' => 'alert',
          'foot' => $immo['nb_impayes'] ? '<span>' . $immo['nb_impayes'] . ' bail' . ($immo['nb_impayes'] > 1 ? 'x' : '') . ' en retard</span>' : '<span>Aucun retard</span>',
        ]) ?>
    <?= kpi('Prochaine échéance de bail', $ech ? date('d/m/Y', strtotime($ech['fin'])) : '—', [
          'icon' => 'calendar',
          'foot' => $ech ? '<span>' . h($ech['nom']) . '</span>' : '',
        ]) ?>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">
          Loyers et charges
          <span class="legend-inline">
            <span><i style="background:#CBD2DB"></i>Appelés</span>
            <span><i style="background:#1F5FAD"></i>Encaissés</span>
          </span>
        </div>
        <div class="card-body"><div class="chart-box"><canvas id="chartLoyers"></canvas></div></div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header">Biens <span class="card-hint"><?= $immo['total'] ?> lots</span></div>
        <div class="card-body p-0" style="max-height:360px;overflow:auto">
          <table class="table align-middle mb-0">
            <thead style="position:sticky;top:0;z-index:1">
              <tr><th>Bien</th><th>Locataire</th><th class="text-end">Loyer + ch.</th><th class="text-end">Échéance</th><th class="text-end">Solde</th></tr>
            </thead>
            <tbody>
              <?php foreach ($immo['lignes'] as $l):
                $loue = $l['bail_id'] !== null;
                $solde = $l['solde'] !== null ? (float) $l['solde'] : null; ?>
                <tr>
                  <td>
                    <div class="fw-semibold"><?= h($l['nom']) ?></div>
                    <div class="small text-muted"><?= h(QALIMO_TYPES[$l['type']] ?? ucfirst((string) $l['type'])) ?><?= $l['surface'] ? ' · ' . (float) $l['surface'] . ' m²' : '' ?></div>
                  </td>
                  <td>
                    <?php if (!$loue): ?>
                      <span class="text-danger fw-semibold">Vacant</span>
                    <?php else: ?>
                      <?= h($l['locataire']) ?>
                      <?php if ((int) $l['preavis'] === 1): ?><span class="stale">préavis</span><?php endif; ?>
                    <?php endif; ?>
                  </td>
                  <td class="text-end"><?= $loue ? eur((float) $l['loyer_hc'] + (float) $l['charges']) : '<span class="text-muted">' . eur((float) $l['loyer_bien'] + (float) $l['charges_bien']) . '</span>' ?></td>
                  <td class="text-end"><?= $loue && $l['fin'] ? date('d/m/Y', strtotime($l['fin'])) : '—' ?></td>
                  <td class="text-end <?= $solde !== null && $solde < -0.009 ? 'text-danger fw-semibold' : 'text-muted' ?>">
                    <?= $loue && $solde !== null ? eur($solde) : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <h6 class="text-muted mb-3">Suivi mensuel</h6>
<?php endif; ?>

<div class="kpi-grid">
  <?= kpi('Résultat net · dernier mois', $latestNet === null ? '<span class="text-muted" style="font-size:1rem">Pas de donnée</span>'
        : '<span class="' . ($latestNet >= 0 ? '' : 'text-danger') . '">' . eur($latestNet) . '</span>', [
        'icon' => 'wallet', 'hero' => !$estRestaurant,
        'foot' => $latest ? '<span>' . format_mois_fr((int) substr($latest['period_date'], 0, 4), (int) substr($latest['period_date'], 5, 2)) . '</span>'
                          . delta_eur($ecartNetMois, 'vs mois préc.') : '',
      ]) ?>
  <?= kpi('Résultat cumulé ' . date('Y'), '<span class="' . ($ytdNet >= 0 ? '' : 'text-danger') . '">' . eur($ytdNet) . '</span>', [
        'icon' => 'trend',
        'foot' => delta($varYtd, false, 'vs N-1') . ($ytdMarge !== null ? '<span>Marge ' . pourcent($ytdMarge, 1) . '</span>' : ''),
      ]) ?>
  <?= kpi('Revenus ' . date('Y'), eur((float) $ytd['revenus']), [
        'icon' => 'receipt',
        'foot' => $ytdN1 && $ytdN1['mois'] ? delta(ecart_pct((float) $ytd['revenus'], $ytdN1['revenus']), false, 'vs N-1') : '',
      ]) ?>
  <?= kpi('Charges ' . date('Y'), eur((float) $ytd['charges']), [
        'icon' => 'layers',
        'foot' => $ytdN1 && $ytdN1['mois'] ? delta(ecart_pct((float) $ytd['charges'], $ytdN1['charges']), true, 'vs N-1') : '',
      ]) ?>
  <?php if ($lotsTotal > 0 && !$immo): // avec Qalimo, l'occupation est dans le bloc Immobilier ?>
    <?= kpi('Occupation', $lotsLoues . ' <small>/ ' . $lotsTotal . ' lots</small>', [
          'icon' => 'home',
          'foot' => '<div class="meter ' . ($lotsLoues >= $lotsTotal ? 'ok' : 'warn') . '" style="flex:1;min-width:80px"><span style="width:' . round($lotsLoues / $lotsTotal * 100) . '%"></span></div>'
                  . '<span>' . pourcent($lotsLoues / $lotsTotal * 100) . '</span>',
        ]) ?>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-header">
    Évolution mensuelle
    <span class="legend-inline">
      <span><i style="background:#1F5FAD"></i>Revenus</span>
      <span><i style="background:#C8A84B"></i>Charges</span>
      <span><i style="background:#0E1C2B"></i>Résultat net</span>
    </span>
  </div>
  <div class="card-body">
    <?php if (!$entries): ?>
      <div class="empty-state">Pas encore de données à afficher<?= $canEdit ? ' — ajoutez une première saisie ci-dessous' : '' ?>.</div>
    <?php else: ?>
      <div class="chart-box"><canvas id="chartEvolution"></canvas></div>
    <?php endif; ?>
  </div>
</div>

<div class="row g-4">
  <?php if ($canEdit): ?>
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Saisie mensuelle</div>
      <div class="card-body">
        <form method="post" class="row g-3">
          <input type="hidden" name="action" value="save_entry">
          <div class="col-12">
            <label class="form-label">Mois</label>
            <input type="month" name="period_date" class="form-control" value="<?= date('Y-m') ?>" required>
          </div>
          <div class="col-6">
            <label class="form-label">Revenus (€)</label>
            <input type="text" name="revenus" class="form-control" placeholder="ex : 4200" inputmode="decimal">
          </div>
          <div class="col-6">
            <label class="form-label">Charges (€)</label>
            <input type="text" name="charges" class="form-control" placeholder="ex : 2600" inputmode="decimal">
          </div>
          <div class="col-12">
            <label class="form-label">Notes (optionnel)</label>
            <input type="text" name="notes" class="form-control">
          </div>
          <div class="col-12">
            <button type="submit" class="btn btn-dark w-100">Enregistrer</button>
          </div>
        </form>
        <div class="form-text mt-2">Un mois déjà saisi est remplacé.</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="col-lg-<?= $canEdit ? '8' : '12' ?>">
    <div class="card">
      <div class="card-header">Historique <span class="card-hint"><?= count($entries) ?> mois</span></div>
      <div class="card-body p-0" style="max-height:460px;overflow:auto">
        <table class="table">
          <thead style="position:sticky;top:0;z-index:1">
            <tr><th>Mois</th><th class="text-end">Revenus</th><th class="text-end">Charges</th><th class="text-end">Résultat net</th><th class="text-end">Marge</th></tr>
          </thead>
          <tbody>
            <?php foreach (array_reverse($entries) as $e):
              $net = (float) $e['revenus'] - (float) $e['charges'];
              $m   = (float) $e['revenus'] > 0 ? $net / (float) $e['revenus'] * 100 : null; ?>
              <tr>
                <td class="fw-semibold"><?= h(ucfirst(format_mois_fr((int) substr($e['period_date'], 0, 4), (int) substr($e['period_date'], 5, 2)))) ?></td>
                <td class="text-end"><?= eur((float) $e['revenus']) ?></td>
                <td class="text-end"><?= eur((float) $e['charges']) ?></td>
                <td class="text-end fw-semibold <?= $net >= 0 ? 'text-success' : 'text-danger' ?>"><?= eur($net) ?></td>
                <td class="text-end text-muted"><?= pourcent($m, 1) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$entries): ?>
              <tr><td colspan="5" class="text-center text-muted py-4">Aucune saisie.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php if ($estRestaurant && array_sum(array_column($serie30, 'ca')) > 0): ?>
<script>
(function () {
  var serie = <?= json_encode($serie30) ?>;
  var c = ADHUB.colors;
  new Chart(document.getElementById('chartResto'), {
    type: 'bar',
    data: {
      labels: serie.map(function (j) { return j.libelle; }),
      datasets: [
        { label: 'CA TTC', data: serie.map(function (j) { return j.ca; }), backgroundColor: c.blue, maxBarThickness: 26, order: 2 },
        { label: 'N-1', type: 'line', data: serie.map(function (j) { return j.ca_n1 || null; }), borderColor: '#AEB6C2', borderDash: [4, 4], borderWidth: 1.6, spanGaps: true, tension: .3, order: 1 }
      ]
    },
    options: {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw || 0); } } } },
      scales: { x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                y: { ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
    }
  });
  // Mini-courbe : cumul du mois jour par jour
  var cumul = [], s = 0, mois = '<?= date('Y-m') ?>';
  serie.forEach(function (j) { if (j.date.slice(0, 7) === mois) { s += j.ca; cumul.push(s); } });
  ADHUB.spark('sparkMois', cumul.length > 1 ? cumul : [0, s], c.blue);
})();
</script>
<?php endif; ?>

<?php if ($immo): ?>
<script>
(function () {
  var m = <?= json_encode(array_map(fn($k, $v) => ['mois' => mois_court($k . '-01'), 'appele' => $v['appele'], 'encaisse' => $v['encaisse']], array_keys($immo['mensuel']), $immo['mensuel']), JSON_UNESCAPED_UNICODE) ?>;
  // Les mois d'avant le premier mouvement (historique pas encore dans Qalimo) sont retirés
  while (m.length > 3 && !m[0].appele && !m[0].encaisse) m.shift();
  new Chart(document.getElementById('chartLoyers'), {
    type: 'bar',
    data: {
      labels: m.map(function (x) { return x.mois; }),
      datasets: [
        { label: 'Appelés', data: m.map(function (x) { return x.appele; }), backgroundColor: '#CBD2DB', maxBarThickness: 18 },
        { label: 'Encaissés', data: m.map(function (x) { return x.encaisse; }), backgroundColor: ADHUB.colors.blue, maxBarThickness: 18 }
      ]
    },
    options: {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
      scales: { x: { grid: { display: false } }, y: { ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
    }
  });
})();
</script>
<?php endif; ?>

<?php if ($entries): ?>
<script>
new Chart(document.getElementById('chartEvolution'), {
  data: {
    labels: <?= json_encode($chartLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { type: 'line', label: 'Résultat net', data: <?= json_encode($chartNet) ?>, borderColor: ADHUB.colors.navy, backgroundColor: ADHUB.colors.navy, pointRadius: 3, pointBackgroundColor: '#fff', pointBorderWidth: 2, tension: .3, order: 0 },
      { type: 'bar', label: 'Revenus', data: <?= json_encode($chartRevenus) ?>, backgroundColor: ADHUB.colors.blue, maxBarThickness: 22, order: 1 },
      { type: 'bar', label: 'Charges', data: <?= json_encode($chartCharges) ?>, backgroundColor: ADHUB.colors.gold, maxBarThickness: 22, order: 1 }
    ]
  },
  options: {
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw); } } } },
    scales: { x: { grid: { display: false } }, y: { ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
  }
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
