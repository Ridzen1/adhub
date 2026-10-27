<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/pennylane.php';
require_once __DIR__ . '/../inc/ui.php';
require_direction();

/**
 * Soldes de départ de la trésorerie : un solde bancaire réel par société, saisi une seule
 * fois par la direction. Chaque import Pennylane y ajoute ensuite les opérations bancaires.
 */

$erreur = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $erreur = "La page a expiré, recommencez.";
    } else {
        $dateCommune = ($_POST['date_commune'] ?? '') !== '' ? $_POST['date_commune'] : null;
        $lignes = [];
        foreach ((array) ($_POST['solde'] ?? []) as $pid => $brut) {
            $brut = trim((string) $brut);
            $date = ($_POST['le'][$pid] ?? '') !== '' ? $_POST['le'][$pid] : $dateCommune;
            if ($brut === '') {
                continue;
            }
            $montant = str_replace([' ', "\u{a0}", "\u{202f}", '€', ','], ['', '', '', '', '.'], $brut);
            if (!is_numeric($montant)) {
                $erreur = "Montant illisible : « " . $brut . " ».";
                break;
            }
            if (!$date || !DateTime::createFromFormat('Y-m-d', $date)) {
                $erreur = "Indiquez la date du solde « " . $brut . " ».";
                break;
            }
            $lignes[(int) $pid] = [round((float) $montant, 2), $date];
        }
        if (!$erreur) {
            foreach ($lignes as $pid => [$solde, $le]) {
                pennylane_definir_solde_depart($pid, $solde, $le);
            }
            $n = pennylane_maj_tresorerie();
            header('Location: /pages/admin_tresorerie.php?enregistre=' . count($lignes) . '&calcules=' . $n);
            exit;
        }
    }
}

$societes = pennylane_soldes_depart();
$sansDepart = count(array_filter($societes, fn($s) => $s['solde_reference'] === null));
$sansBanque = count(array_filter($societes, fn($s) => $s['solde_reference'] !== null && (int) $s['nb_liens'] === 0));

$pageTitle = 'Trésorerie';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Trésorerie des sociétés', "Saisissez une seule fois le solde bancaire réel de chaque société à une date donnée : chaque import Pennylane y ajoute ensuite les opérations bancaires.", '', 'Administration') ?>

<?php if ($erreur): ?><div class="alert alert-danger"><?= h($erreur) ?></div><?php endif; ?>
<?php if (isset($_GET['enregistre'])): ?>
  <div class="alert alert-success">
    <?= (int) $_GET['enregistre'] ?> solde(s) de départ enregistré(s),
    <?= (int) ($_GET['calcules'] ?? 0) ?> trésorerie(s) recalculée(s) avec les opérations Pennylane.
  </div>
<?php endif; ?>

<div class="card mb-4">
  <div class="card-body small">
    <strong>Où trouver le solde ?</strong> Le plus simple : l'écran Trésorerie de Pennylane (solde de chaque compte
    à aujourd'hui), ou le relevé bancaire d'une fin de mois. Pour une société qui a plusieurs comptes, additionnez-les.
    Le solde doit être celui du <em>soir</em> de la date indiquée : seules les opérations postérieures sont ajoutées.
  </div>
</div>

<form method="post" class="card mb-4">
  <?= csrf_champ() ?>
  <div class="card-header">
    <span>Soldes de départ</span>
    <?php if ($sansDepart): ?><span class="badge bg-warning text-dark"><?= $sansDepart ?> sans solde</span><?php endif; ?>
  </div>
  <div class="card-body border-bottom d-flex flex-wrap align-items-center gap-2">
    <label for="date_commune" class="small text-muted">Date des soldes saisis (si la ligne n'en précise pas)</label>
    <input type="date" id="date_commune" name="date_commune" class="form-control form-control-sm" style="max-width:170px"
           value="<?= h(date('Y-m-d')) ?>">
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table mb-0 align-middle">
        <thead>
          <tr>
            <th>Société</th>
            <th>Point de départ</th>
            <th class="text-end">Trésorerie affichée</th>
            <th>Nouveau solde (€)</th>
            <th>au</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($societes as $s): $pid = (int) $s['id']; ?>
            <tr>
              <td>
                <div class="fw-semibold"><?= h($s['name']) ?></div>
                <?php if ((int) $s['nb_liens'] === 0): ?>
                  <div class="small text-warning">Pas reliée à Pennylane : le solde ne bougera pas tout seul</div>
                <?php elseif ($s['solde_reference'] !== null): ?>
                  <div class="small text-muted"><?= number_format($s['nb_operations'], 0, ',', ' ') ?> opération(s) Pennylane depuis</div>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ($s['solde_reference'] !== null): ?>
                  <?= eur((float) $s['solde_reference']) ?> au <?= date('d/m/Y', strtotime($s['solde_reference_le'])) ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td class="text-end">
                <?php if ($s['solde_bancaire'] !== null): ?>
                  <span class="fw-semibold <?= (float) $s['solde_bancaire'] < 0 ? 'text-danger' : '' ?>"><?= eur((float) $s['solde_bancaire']) ?></span>
                  <?php if ($s['solde_maj_le']): ?><div class="small text-muted">au <?= date('d/m/Y', strtotime($s['solde_maj_le'])) ?></div><?php endif; ?>
                <?php else: ?>
                  <span class="text-muted">—</span>
                <?php endif; ?>
              </td>
              <td style="min-width:140px">
                <input type="text" inputmode="decimal" name="solde[<?= $pid ?>]" class="form-control form-control-sm" placeholder="ex. 12 450,30">
              </td>
              <td style="min-width:150px">
                <input type="date" name="le[<?= $pid ?>]" class="form-control form-control-sm">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <div class="p-3 border-top d-flex justify-content-between align-items-center gap-3 flex-wrap">
    <span class="text-muted small">Seules les lignes remplies sont modifiées.
      <?php if ($sansBanque): ?><?= $sansBanque ?> société(s) avec un solde mais sans lien Pennylane.<?php endif; ?></span>
    <button class="btn btn-dark btn-sm">Enregistrer et recalculer</button>
  </div>
</form>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
