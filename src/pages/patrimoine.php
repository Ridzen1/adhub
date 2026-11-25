<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/pennylane.php';
require_once __DIR__ . '/../inc/patrimoine.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

if (!voit_patrimoine()) {
    http_response_code(403);
    $pageTitle = 'Patrimoine';
    require __DIR__ . '/../inc/layout_header.php';
    echo page_header('Patrimoine', '', '', 'Accès réservé');
    echo '<div class="alert alert-secondary">Cette page est réservée.</div>';
    require __DIR__ . '/../inc/layout_footer.php';
    exit;
}

// Un export Pennylane passé par phpMyAdmin n'a pas déclenché le calcul : on le refait ici
pennylane_relier_automatiquement();
pennylane_maj_tresorerie();

$annee   = (int) date('Y');
$seuil   = in_array($_GET['seuil'] ?? '', ['controle', 'minoritaire', 'totale'], true) ? $_GET['seuil'] : 'tout';
$famille = in_array($_GET['famille'] ?? '', ['sci', 'restaurants', 'mdb', 'holding'], true) ? $_GET['famille'] : 'tout';

$racine = patrimoine_personne_id();
$toutes = $racine ? patrimoine_lignes($racine, $annee) : [];
$lignes = patrimoine_filtrer($toutes, $seuil, $famille);

$noms = [];
foreach (get_db()->query("SELECT id, name FROM projects")->fetchAll() as $p) {
    $noms[(int) $p['id']] = $p['name'];
}

/** Somme d'une colonne en ignorant les valeurs absentes ; null si aucune valeur. */
function somme_connue(array $lignes, string $cle): ?float
{
    $vals = array_filter(array_column($lignes, $cle), fn($v) => $v !== null);
    return $vals ? array_sum($vals) : null;
}

$totTreso    = somme_connue($lignes, 'qp_tresorerie');
$totResultat = somme_connue($lignes, 'qp_resultat');
$totValeur   = somme_connue($lignes, 'qp_valeur');
$totEmprunt  = somme_connue($lignes, 'qp_emprunt');
$sansSolde   = count(array_filter($lignes, fn($l) => $l['qp_tresorerie'] === null));
$sansResultat = count(array_filter($lignes, fn($l) => $l['qp_resultat'] === null));
$avecBiens   = $totValeur !== null || $totEmprunt !== null;
$totMensuel  = somme_connue($lignes, 'qp_mensualites');
// Net immobilier : seulement les sociétés qui possèdent des biens (un restaurant a des prêts
// mais son fonds de commerce n'est pas valorisé : le compter fausserait le net)
$totNetImmo = null;
foreach ($lignes as $l) {
    if ($l['qp_valeur'] !== null) {
        $totNetImmo = ($totNetImmo ?? 0.0) + $l['qp_valeur'] - (float) ($l['qp_emprunt'] ?? 0);
    }
}

function montant_ou_tiret(?float $v): string
{
    if ($v === null) {
        return '<span class="text-muted">—</span>';
    }
    return '<span class="' . ($v < 0 ? 'text-danger ' : '') . 'num">' . eur($v) . '</span>';
}

function lien_filtre(string $seuil, string $famille): string
{
    return '/pages/patrimoine.php?' . http_build_query(array_filter(['seuil' => $seuil, 'famille' => $famille], fn($v) => $v !== 'tout'));
}

$optionsSeuil = [
    'tout'        => 'Toutes',
    'totale'      => '100 %',
    'controle'    => '50 % ou plus',
    'minoritaire' => 'Moins de 50 %',
];
$optionsFamille = [
    'tout'        => 'Toutes',
    'sci'         => 'SCI',
    'restaurants' => 'Restaurants',
    'mdb'         => 'Marchand de biens',
    'holding'     => 'Holding',
];

$pageTitle = 'Patrimoine';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header(
    "Patrimoine d'Arnaud Duvivier",
    count($lignes) . ' société' . (count($lignes) > 1 ? 's' : '') . ' détenue' . (count($lignes) > 1 ? 's' : '')
        . ' · parts réelles calculées d\'après les liens de détention',
    '',
    'Accès réservé'
) ?>

<?php if (!$racine): ?>
  <div class="alert alert-warning">La fiche « ARNAUD DUVIVIER » est introuvable. Passez la migration <code>026_organigramme_2026.sql</code>.</div>
<?php elseif (!$toutes): ?>
  <div class="alert alert-warning">Aucun lien de détention depuis la fiche d'Arnaud Duvivier. Passez la migration <code>026_organigramme_2026.sql</code>.</div>
<?php else: ?>

<div class="d-flex flex-wrap gap-4 mb-3 align-items-center">
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <span class="text-muted small">Part détenue</span>
    <?php foreach ($optionsSeuil as $cle => $label): ?>
      <a href="<?= h(lien_filtre($cle, $famille)) ?>" class="btn btn-sm <?= $seuil === $cle ? 'btn-dark' : 'btn-outline-dark' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="d-flex flex-wrap gap-2 align-items-center">
    <span class="text-muted small">Type</span>
    <?php foreach ($optionsFamille as $cle => $label): ?>
      <a href="<?= h(lien_filtre($seuil, $cle)) ?>" class="btn btn-sm <?= $famille === $cle ? 'btn-dark' : 'btn-outline-dark' ?>"><?= h($label) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="kpi-grid">
  <?= kpi('Sa part de la trésorerie', montant_ou_tiret($totTreso), [
        'hero' => true, 'icon' => 'bank',
        'foot' => '<span>Soldes bancaires × part réelle</span>'
                . ($sansSolde ? '<span>' . $sansSolde . ' sans solde</span>' : ''),
      ]) ?>
  <?= kpi('Sa part du résultat ' . $annee, montant_ou_tiret($totResultat), [
        'icon' => 'trend',
        'foot' => '<span>Estimé, avant salaires et prêts</span>'
                . ($sansResultat ? '<span>' . $sansResultat . ' sans chiffres</span>' : ''),
      ]) ?>
  <?php if ($avecBiens): ?>
    <?= kpi('Patrimoine immobilier net', montant_ou_tiret($totNetImmo), ['icon' => 'home', 'foot' => '<span>Sa part des biens moins sa part des prêts qui les financent</span>']) ?>
    <?= kpi('Sa part des biens', montant_ou_tiret($totValeur), ['icon' => 'building', 'foot' => '<span>Valeur des biens × part réelle</span>']) ?>
    <?= kpi('Sa part des prêts', montant_ou_tiret($totEmprunt), ['icon' => 'key', 'foot' => '<span>Capital restant dû × part réelle</span>'
            . ($totMensuel !== null ? '<span>' . eur($totMensuel) . ' de mensualités</span>' : '')]) ?>
  <?php else: ?>
    <?= kpi('Biens et emprunts', '<span class="text-muted">—</span>', ['icon' => 'home', 'foot' => '<span>En attente du fichier des prêts</span>']) ?>
  <?php endif; ?>
</div>

<div class="card mb-4">
  <div class="card-header">
    Détail par société
    <span class="card-hint">Montants de la société, puis ce qui revient à Arnaud</span>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th>Société</th>
            <th class="text-end">Part réelle</th>
            <th class="text-end">Trésorerie</th>
            <th class="text-end">Sa part</th>
            <th class="text-end">Résultat <?= $annee ?></th>
            <th class="text-end">Sa part</th>
            <?php if ($avecBiens): ?>
              <th class="text-end">Biens (sa part)</th>
              <th class="text-end">Restant dû (sa part)</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($lignes as $l):
            $via = array_map(function ($c) use ($noms) {
                $inter = array_slice($c, 0, -1);
                return $inter ? implode(' → ', array_map(fn($id) => $noms[$id] ?? ('#' . $id), $inter)) : 'en direct';
            }, $l['chemins']);
            $pct = $l['part'] * 100;
          ?>
            <tr>
              <td>
                <a class="row-link" href="<?= lien_societe((int) $l['id']) ?>"><?= h($l['name']) ?></a>
                <?= type_chip($l['type_code'], $l['type_label']) ?>
                <div class="text-muted small">
                  <?= $l['nom_propre'] ? 'En nom propre' : ($via === ['en direct'] ? 'En direct' : 'Via ' . h(implode(' ; ', $via))) ?>
                </div>
              </td>
              <td class="text-end fw-semibold num"><?= pourcent($pct, abs($pct - round($pct)) < 0.05 ? 0 : 1) ?></td>
              <td class="text-end"><?= montant_ou_tiret($l['solde_bancaire'] !== null ? (float) $l['solde_bancaire'] : null) ?></td>
              <td class="text-end fw-semibold"><?= montant_ou_tiret($l['qp_tresorerie']) ?></td>
              <td class="text-end"><?= montant_ou_tiret($l['resultat']) ?></td>
              <td class="text-end fw-semibold"><?= montant_ou_tiret($l['qp_resultat']) ?></td>
              <?php if ($avecBiens): ?>
                <td class="text-end"><?= montant_ou_tiret($l['qp_valeur']) ?></td>
                <td class="text-end"><?= montant_ou_tiret($l['qp_emprunt']) ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
          <?php if (!$lignes): ?>
            <tr><td colspan="8" class="text-muted">Aucune société pour ce filtre.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="text-muted small mb-4">
  <p class="mb-1"><strong>Part réelle</strong> : les parts se multiplient le long de la chaîne de détention
    (ex. 100 % d'AD Holding × 34 % de SCI DTL × 75 % de Chez Côme = 25,5 %). Les liens se modifient sur la fiche de chaque société.</p>
  <p class="mb-1"><strong>Résultat estimé</strong> : revenus moins charges suivis dans ADHUB. Les salaires, charges sociales et
    remboursements de prêts n'y sont pas encore : le chiffre est au-dessus de la réalité. Ce n'est pas ce qu'Arnaud perçoit
    (dividendes, rémunération de gérant).</p>
  <p class="mb-0">Les montants d'une holding peuvent déjà contenir ce qu'elle reçoit de ses filiales : la part du résultat d'AD Holding
    peut faire en partie double emploi avec celle des sociétés qu'elle détient.</p>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
