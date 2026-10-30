<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/pennylane.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

/**
 * Comptabilité d'une société, d'après le dernier export Pennylane importé :
 * factures fournisseurs à payer, achats, flux bancaires et pièces à justifier.
 */

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);
if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}
require_project_access($id, 'dashboard');

$noms = pennylane_noms_projet($id);
// Restaurant : les factures fournisseurs ne sont pas montrées (elles restent comptées dans les charges
// mensuelles du tableau de bord) ; seuls les comptes et la banque apparaissent ici
$estRestaurant = is_restaurant($project);

[$inA, $pA] = pl_in($noms, 'a');
[$inB, $pB] = pl_in($noms, 'b');
$annees = $noms ? array_map('intval', array_column(pl_requete(
    "SELECT DISTINCT YEAR(date_facture) AS a FROM pennylane_factures WHERE societe IN ($inA) AND date_facture IS NOT NULL
     UNION SELECT DISTINCT YEAR(date_operation) FROM pennylane_transactions WHERE societe IN ($inB)
     ORDER BY a DESC", $pA + $pB), 'a')) : [];
$annee = (int) ($_GET['annee'] ?? ($annees[0] ?? date('Y')));
if ($annees && !in_array($annee, $annees, true)) {
    $annee = $annees[0];
}
$du = $annee . '-01-01';
$au = $annee . '-12-31';

$s        = pennylane_synthese($noms, $du, $au);
$parMois  = pennylane_par_mois($noms, $du, $annee < (int) date('Y') ? $au : date('Y-m-d'));
$top      = pennylane_top_fournisseurs($noms, $du, $au, 8);
$ouvertes = pennylane_factures_ouvertes($noms, 'fournisseur', 40);
$aEncaisser = pennylane_factures_ouvertes($noms, 'client', 20);
$operations = pennylane_dernieres_transactions($noms, 25);
$import   = pennylane_dernier_import();
$comptes  = pennylane_comptes_projet($noms);
$tresoMois = pennylane_tresorerie_mensuelle($noms);
$tva      = pennylane_tva_projet($noms);
$avecBanque = $s['nb_transactions'] > 0;
$aujourdhui = date('Y-m-d');

$f = $s['fournisseur'];
$totalTop = array_sum(array_map(fn($t) => (float) $t['ttc'], $top)) ?: 1;

function libelle_statut_operation(?string $statut): string
{
    return match ($statut) {
        'À justifier'       => '<span class="badge bg-warning text-dark">À justifier</span>',
        'validation_needed' => '<span class="badge bg-secondary">À valider</span>',
        'Complète'          => '<span class="badge bg-success">Complète</span>',
        null, ''            => '',
        default             => '<span class="badge bg-secondary">' . h($statut) . '</span>',
    };
}

$actions = '';
if (count($annees) > 1) {
    $actions .= '<form method="get" class="m-0"><input type="hidden" name="id" value="' . $id . '">'
              . '<select name="annee" class="form-select form-select-sm" onchange="this.form.submit()">';
    foreach ($annees as $a) {
        $actions .= '<option value="' . $a . '"' . ($a === $annee ? ' selected' : '') . '>' . $a . '</option>';
    }
    $actions .= '</select></form>';
}
if (is_direction()) {
    $actions .= '<a href="/pages/admin_pennylane.php" class="btn btn-sm btn-outline-dark">Importer un export</a>';
}
$meta = $import ? 'Données Pennylane du ' . date('d/m/Y', strtotime($import['imported_at'])) : 'Données Pennylane';

$pageTitle = $project['name'] . ' — Comptabilité';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'compta', $actions, $meta) ?>

<?php if (!$noms): ?>
  <div class="card"><div class="card-body empty-state">
    Aucune société Pennylane n'est reliée à <?= h($project['name']) ?>.
    <?php if (is_direction()): ?>Reliez-la dans <a href="/pages/admin_pennylane.php">Administration → Import Pennylane</a>.<?php endif; ?>
  </div></div>
<?php else: ?>

<?php if ($estRestaurant && !$avecBanque && !$comptes && !$tresoMois): ?>
  <div class="card"><div class="card-body empty-state">
    Pas de compte bancaire de <?= h($project['name']) ?> dans l'export Pennylane.
    Les ventes du restaurant sont suivies par la caisse, dans le tableau de bord.
  </div></div>
<?php else: ?>

<div class="kpi-grid">
  <?php if (!$estRestaurant): ?>
  <?= kpi('Fournisseurs à payer', eur($f['reste']), [
        'hero' => true, 'icon' => 'receipt',
        'foot' => '<span>' . $f['nb_reste'] . ' facture' . ($f['nb_reste'] > 1 ? 's' : '') . '</span>'
                . ($f['echu'] > 0.009 ? '<span style="color:#FCA5A5">dont ' . eur($f['echu']) . ' échus</span>' : '<span>rien d\'échu</span>'),
      ]) ?>
  <?= kpi('Achats HT ' . $annee, eur($f['ht']), [
        'icon' => 'layers',
        'foot' => $f['nb'] . ' facture' . ($f['nb'] > 1 ? 's' : '') . ' fournisseurs',
      ]) ?>
  <?php endif; ?>
  <?php if ($avecBanque): ?>
    <?= kpi('Encaissements ' . $annee, eur($s['encaissements']), [
          'icon' => 'bank',
          'foot' => 'Solde des flux <strong class="' . ($s['encaissements'] - $s['decaissements'] < 0 ? 'text-danger' : 'text-success') . '">'
                  . eur($s['encaissements'] - $s['decaissements']) . '</strong>',
        ]) ?>
    <?= kpi('Décaissements ' . $annee, eur($s['decaissements']), [
          'icon' => 'wallet',
          'foot' => $s['nb_transactions'] . ' opérations bancaires',
        ]) ?>
    <?= kpi('Opérations à justifier', (string) $s['a_justifier'], [
          'icon' => 'alert', 'class' => $s['a_justifier'] ? 'kpi-warn' : '',
          'foot' => $s['nb_transactions'] ? pourcent($s['a_justifier'] / $s['nb_transactions'] * 100) . ' des opérations sans justificatif' : '',
        ]) ?>
  <?php elseif ($estRestaurant): ?>
  <?php elseif (!$s['client']['nb']): $moisActifs = count(array_filter($parMois, fn($m) => $m['achats'] > 0)) ?: 1; ?>
    <?= kpi('Factures en retard', (string) $f['nb_echues'], [
          'icon' => 'alert', 'class' => $f['nb_echues'] ? 'kpi-warn' : '',
          'foot' => $f['nb_echues'] ? 'échéance dépassée, non soldées' : 'Aucune échéance dépassée',
        ]) ?>
    <?= kpi('Achats HT par mois', eur($f['ht'] / $moisActifs), [
          'icon' => 'calendar',
          'foot' => 'moyenne sur ' . $moisActifs . ' mois facturés',
        ]) ?>
  <?php else: ?>
    <?= kpi('Ventes facturées HT ' . $annee, eur($s['client']['ht']), [
          'icon' => 'trend',
          'foot' => $s['client']['nb'] ? $s['client']['nb'] . ' factures clients' : 'Aucune facture client',
        ]) ?>
    <?= kpi('Clients à encaisser', eur($s['client']['reste']), [
          'icon' => 'wallet',
          'foot' => $s['client']['nb_reste'] ? $s['client']['nb_reste'] . ' factures ouvertes' : 'Tout est encaissé',
        ]) ?>
  <?php endif; ?>
</div>

<?php if ($comptes || $tresoMois): ?>
<div class="row g-4 mb-4">
  <?php if ($comptes): $totalComptes = array_sum(array_map(fn($c) => (float) $c['solde'], $comptes)); ?>
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header">
        Comptes bancaires
        <span class="text-muted small fw-normal">au <?= date('d/m/Y', strtotime($comptes[0]['releve_le'])) ?></span>
      </div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 align-middle">
          <tbody>
            <?php foreach ($comptes as $c): if ((float) $c['solde'] == 0.0 && !$c['synchronise']) { continue; } ?>
              <tr>
                <td>
                  <div class="fw-semibold"><?= h($c['banque'] ?? '') ?></div>
                  <div class="small text-muted">
                    <?= $c['iban_fin'] ? '…' . h($c['iban_fin']) : '' ?>
                    <?php if (!$c['synchronise']): ?><span class="text-warning">non synchronisé</span><?php endif; ?>
                  </div>
                </td>
                <td class="text-end fw-semibold <?= (float) $c['solde'] < 0 ? 'text-danger' : '' ?>"><?= eur((float) $c['solde'], 2) ?></td>
              </tr>
            <?php endforeach; ?>
            <tr class="table-light">
              <td class="fw-semibold">Total</td>
              <td class="text-end fw-bold"><?= eur($totalComptes, 2) ?></td>
            </tr>
            <?php foreach ($tva as $t): ?>
              <tr>
                <td class="small text-muted">TVA <?= h(mb_strtolower($t['frequence'] ?? '')) ?> en cours (<?= h(mb_strtolower($t['sens'] ?? '')) ?>)</td>
                <td class="text-end small"><?= eur((float) $t['montant']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
  <?php if ($tresoMois): ?>
  <div class="<?= $comptes ? 'col-xl-7' : 'col-12' ?>">
    <div class="card h-100">
      <div class="card-header">Trésorerie mois par mois <span class="text-muted small fw-normal">d'après Pennylane</span></div>
      <div class="card-body p-0" style="max-height:420px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Mois</th><th class="text-end">Encaissements</th><th class="text-end">Décaissements</th><th class="text-end">Solde fin</th></tr></thead>
          <tbody>
            <?php foreach (array_reverse($tresoMois) as $m): ?>
              <tr>
                <td class="text-nowrap"><?= h(mois_court($m['mois'] . '-01')) ?><?= $m['mois_en_cours'] ? ' <span class="small text-muted">en cours</span>' : '' ?></td>
                <td class="text-end text-success"><?= eur((float) $m['encaissements']) ?></td>
                <td class="text-end text-danger"><?= eur((float) $m['decaissements']) ?></td>
                <td class="text-end fw-semibold"><?= $m['solde_fin'] !== null ? eur((float) $m['solde_fin']) : '<span class="text-muted">—</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (!$estRestaurant || $avecBanque): ?>
<div class="card mb-4">
  <div class="card-header">
    <?= $estRestaurant ? 'Flux bancaires par mois' : ($avecBanque ? 'Flux bancaires et achats par mois' : 'Achats par mois') ?>
    <span class="legend-inline">
      <?php if ($avecBanque): ?>
        <span><i style="background:#0F7B55"></i>Encaissements</span>
        <span><i style="background:#C2362F"></i>Décaissements</span>
      <?php endif; ?>
      <?php if (!$estRestaurant): ?><span><i style="background:<?= $avecBanque ? '#0E1C2B' : '#1F5FAD' ?>"></i>Achats HT</span><?php endif; ?>
    </span>
  </div>
  <div class="card-body"><div class="chart-box"><canvas id="chartCompta"></canvas></div></div>
</div>
<?php endif; ?>

<?php if (!$estRestaurant): ?>
<div class="row g-4 mb-4">
  <div class="col-xl-5">
    <div class="card h-100">
      <div class="card-header">Principaux fournisseurs <span class="text-muted small fw-normal"><?= $annee ?>, TTC</span></div>
      <div class="card-body p-0">
        <table class="table mb-0">
          <tbody>
            <?php foreach ($top as $t): ?>
              <tr>
                <td>
                  <div class="fw-semibold text-truncate" style="max-width:min(260px,48vw)"><?= h($t['tiers']) ?></div>
                  <div class="meter mt-1" style="width:100%;max-width:min(260px,48vw)"><span style="width:<?= max(2, round((float) $t['ttc'] / $totalTop * 100)) ?>%"></span></div>
                </td>
                <td class="text-end text-muted small d-none d-sm-table-cell"><?= (int) $t['nb'] ?> fact.</td>
                <td class="text-end fw-semibold"><?= eur((float) $t['ttc']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$top): ?><tr><td class="text-center text-muted py-4">Aucune facture fournisseur sur <?= $annee ?>.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-xl-7">
    <div class="card h-100">
      <div class="card-header">
        Factures fournisseurs à payer
        <?php if ($f['nb_echues']): ?><span class="badge bg-danger"><?= $f['nb_echues'] ?> en retard</span><?php endif; ?>
      </div>
      <div class="card-body p-0" style="max-height:460px;overflow:auto">
        <table class="table table-sm mb-0 align-middle">
          <thead><tr><th>Échéance</th><th>Fournisseur</th><th class="text-end d-none d-sm-table-cell">TTC</th><th class="text-end">Reste</th></tr></thead>
          <tbody>
            <?php foreach ($ouvertes as $o):
              $retard = $o['echeance'] && $o['echeance'] < $aujourdhui && !$o['avoir']
                ? (int) ((strtotime($aujourdhui) - strtotime($o['echeance'])) / 86400) : 0; ?>
              <tr>
                <td class="text-nowrap">
                  <?= $o['echeance'] ? date('d/m/Y', strtotime($o['echeance'])) : '<span class="text-muted">—</span>' ?>
                  <?php if ($retard): ?><div class="small text-danger fw-semibold"><?= $retard ?> j de retard</div><?php endif; ?>
                </td>
                <td>
                  <div class="text-truncate" style="max-width:min(240px,38vw)"><?= h($o['tiers'] ?? $o['libelle'] ?? '') ?></div>
                  <div class="small text-muted"><?= $o['avoir'] ? 'Avoir ' : 'N° ' ?><?= h($o['numero'] ?? '') ?></div>
                </td>
                <td class="text-end text-muted d-none d-sm-table-cell"><?= eur((float) $o['ttc']) ?></td>
                <td class="text-end fw-semibold <?= $retard ? 'text-danger' : '' ?>"><?= eur(($o['avoir'] ? -1 : 1) * (float) $o['reste_a_payer']) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$ouvertes): ?><tr><td colspan="4" class="text-center text-muted py-4">Aucune facture en attente de paiement.</td></tr><?php endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($f['nb_reste'] > count($ouvertes)): ?>
        <div class="card-footer small text-muted"><?= count($ouvertes) ?> plus anciennes échéances sur <?= $f['nb_reste'] ?>.</div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<?php if ($aEncaisser && !$estRestaurant): ?>
<div class="card mb-4">
  <div class="card-header">Factures clients à encaisser</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0">
      <thead><tr><th>Échéance</th><th>Client</th><th>N°</th><th class="text-end">Reste</th></tr></thead>
      <tbody>
        <?php foreach ($aEncaisser as $o): ?>
          <tr>
            <td><?= $o['echeance'] ? date('d/m/Y', strtotime($o['echeance'])) : '—' ?></td>
            <td><?= h($o['tiers'] ?? '') ?></td>
            <td class="text-muted"><?= h($o['numero'] ?? '') ?></td>
            <td class="text-end fw-semibold"><?= eur((float) $o['reste_a_payer']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($avecBanque): ?>
<div class="card mb-4">
  <div class="card-header">Dernières opérations bancaires</div>
  <div class="card-body p-0">
    <table class="table table-sm mb-0 align-middle">
      <thead><tr><th>Date</th><th>Libellé</th><th class="d-none d-md-table-cell">Compte</th><th class="d-none d-sm-table-cell">Pièce</th><th class="text-end">Montant</th></tr></thead>
      <tbody>
        <?php foreach ($operations as $o): ?>
          <tr>
            <td class="text-nowrap"><?= date('d/m/Y', strtotime($o['date_operation'])) ?></td>
            <td>
              <div class="text-truncate" style="max-width:min(340px,40vw)"><?= h($o['libelle'] ?? '') ?></div>
              <?php if ($o['tiers']): ?><div class="small text-muted"><?= h($o['tiers']) ?></div><?php endif; ?>
            </td>
            <td class="d-none d-md-table-cell small text-muted"><?= h($o['compte'] ?? '') ?></td>
            <td class="d-none d-sm-table-cell"><?= libelle_statut_operation($o['statut']) ?></td>
            <td class="text-end fw-semibold text-nowrap <?= (float) $o['montant'] < 0 ? 'text-danger' : 'text-success' ?>">
              <?= ((float) $o['montant'] > 0 ? '+' : '') . eur((float) $o['montant']) ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<p class="text-muted small">
  Chiffres tirés de l'export Pennylane, tels que saisis en comptabilité.<?php if (!$estRestaurant): ?> Un « reste à payer » peut
  correspondre à une facture déjà réglée mais pas encore rapprochée de sa banque dans Pennylane.<?php endif; ?>
</p>

<?php if (!$estRestaurant || $avecBanque): ?>
<script>
(function () {
  var mois = <?= json_encode($parMois) ?>;
  var c = ADHUB.colors;
  var noms = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
  var labels = mois.map(function (m) { return noms[parseInt(m.mois.slice(5), 10) - 1]; });
  var datasets = [];
  <?php if ($avecBanque): ?>
  datasets.push({ type: 'bar', label: 'Encaissements', data: mois.map(function (m) { return m.encaissements; }), backgroundColor: c.green, maxBarThickness: 22, order: 1 });
  datasets.push({ type: 'bar', label: 'Décaissements', data: mois.map(function (m) { return m.decaissements; }), backgroundColor: c.red, maxBarThickness: 22, order: 1 });
  <?php if (!$estRestaurant): ?>
  datasets.push({ type: 'line', label: 'Achats HT', data: mois.map(function (m) { return m.achats; }), borderColor: c.navy, backgroundColor: c.navy, pointRadius: 3, pointBackgroundColor: '#fff', pointBorderWidth: 2, tension: .3, order: 0 });
  <?php endif; ?>
  <?php else: ?>
  datasets.push({ type: 'bar', label: 'Achats HT', data: mois.map(function (m) { return m.achats; }), backgroundColor: c.blue, maxBarThickness: 28 });
  <?php endif; ?>
  new Chart(document.getElementById('chartCompta'), {
    data: { labels: labels, datasets: datasets },
    options: {
      plugins: { legend: { display: false }, tooltip: { callbacks: { label: function (x) { return ' ' + x.dataset.label + ' : ' + ADHUB.eur(x.raw || 0); } } } },
      scales: { x: { grid: { display: false } }, y: { ticks: { callback: function (v) { return ADHUB.eurShort(v); } } } }
    }
  });
})();
</script>
<?php endif; ?>
<?php endif; ?>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
