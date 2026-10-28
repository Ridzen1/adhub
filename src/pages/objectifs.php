<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/objectifs.php';
require_once __DIR__ . '/../inc/ui.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);
if (!$project || !is_restaurant($project)) {
    http_response_code(404);
    echo "Restaurant introuvable.";
    exit;
}
require_project_access($id, 'dashboard');

// Les objectifs sont fixés par la direction seule : aucune saisie pour les équipes.
$peutFixer = is_direction();
$anneeCourante = (int) date('Y');
$annee = (int) ($_GET['annee'] ?? $anneeCourante);
$annee = max($anneeCourante - 2, min($anneeCourante + 1, $annee));

$errors = [];
$migrationOk = objectifs_disponibles();

if ($migrationOk && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_objectifs') {
    require_direction();
    if (!csrf_valide()) {
        $errors[] = "Le formulaire a expiré, rechargez la page.";
    } else {
        $parMois = [];
        foreach ((array) ($_POST['objectif'] ?? []) as $mois => $valeur) {
            $valeur = trim(str_replace([' ', "\u{202F}", "\u{00A0}", '€'], '', (string) $valeur));
            if ($valeur !== '' && !is_numeric(str_replace(',', '.', $valeur))) {
                $errors[] = "Montant invalide pour " . h((string) $mois) . ".";
                continue;
            }
            $parMois[(string) $mois] = $valeur === '' ? null : (float) str_replace(',', '.', $valeur);
        }
        // Seuls les mois de l'année affichée sont acceptés
        $parMois = array_filter($parMois, fn($m) => str_starts_with((string) $m, $annee . '-'), ARRAY_FILTER_USE_KEY);
        if (!$errors) {
            enregistrer_objectifs($id, $parMois, (int) (current_user()['id'] ?? 0) ?: null);
            header("Location: /pages/objectifs.php?id={$id}&annee={$annee}&saved=1");
            exit;
        }
    }
}

$etat  = $migrationOk ? etat_objectif($id) : null;
$bilan = $migrationOk ? bilan_objectifs_annee($id, $annee) : [];

$fixes    = array_filter($bilan, fn($b) => $b['objectif'] !== null && in_array($b['etat'], ['atteint', 'manque'], true));
$atteints = array_filter($fixes, fn($b) => $b['etat'] === 'atteint');
$meilleur = null;
foreach ($bilan as $cle => $b) {
    if ($b['pct'] !== null && $b['etat'] !== 'a_venir' && ($meilleur === null || $b['pct'] > $bilan[$meilleur]['pct'])) {
        $meilleur = $cle;
    }
}

$etats = [
    'atteint'  => ['Atteint', 'trophy'],
    'manque'   => ['Non atteint', 'target'],
    'en_cours' => ['En cours', 'clock'],
    'a_venir'  => ['À venir', 'calendar'],
    'aucun'    => ['Pas d\'objectif', ''],
];

$pageTitle = $project['name'] . ' — Objectifs';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= company_header($project, 'objectifs') ?>

<?php if (!$migrationOk): ?>
  <div class="alert alert-warning">Les objectifs ne sont pas encore activés : passez la migration <code>016_objectifs.sql</code>.</div>
  <?php require __DIR__ . '/../inc/layout_footer.php'; exit; ?>
<?php endif; ?>

<?php if (isset($_GET['saved'])): ?>
  <div class="alert alert-success">Objectifs enregistrés.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= $e ?></div>
<?php endforeach; ?>

<div class="row g-4 mb-4">
  <div class="col-lg-7">
    <div class="card h-100">
      <div class="card-header">Ce mois-ci <span class="card-hint">CA TTC remonté par la caisse</span></div>
      <div class="card-body">
        <?php if ($etat): ?>
          <?= bloc_objectif($etat, $project['name']) ?>
        <?php else: ?>
          <div class="empty-state">
            Aucun objectif fixé pour <?= h(format_mois_fr($anneeCourante, (int) date('n'))) ?>.
            <?php if ($peutFixer): ?><br><a href="#fixer">Fixer les objectifs</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card h-100">
      <div class="card-header">Bilan <?= $annee ?></div>
      <div class="card-body">
        <div class="row g-3">
          <div class="col-6">
            <div class="kpi-label">Mois atteints</div>
            <div class="resto-value num"><?= count($atteints) ?> <small class="text-muted fw-normal fs-6">/ <?= count($fixes) ?></small></div>
          </div>
          <div class="col-6">
            <div class="kpi-label">Série en cours</div>
            <div class="resto-value num"><?= $etat ? $etat['serie'] : 0 ?> <small class="text-muted fw-normal fs-6">mois</small></div>
          </div>
          <div class="col-12">
            <div class="kpi-label">Meilleur mois</div>
            <div class="fw-semibold"><?= $meilleur ? h(ucfirst(format_mois_fr($annee, $bilan[$meilleur]['mois']))) . ' · ' . pourcent($bilan[$meilleur]['pct']) . ' de l\'objectif' : '<span class="text-muted">—</span>' ?></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="d-flex align-items-center justify-content-between mb-3">
  <h6 class="text-muted mb-0">Mois par mois</h6>
  <div class="btn-group btn-group-sm">
    <?php for ($a = $anneeCourante - 2; $a <= $anneeCourante + 1; $a++): ?>
      <a class="btn <?= $a === $annee ? 'btn-dark' : 'btn-outline-secondary' ?>" href="?id=<?= $id ?>&annee=<?= $a ?>"><?= $a ?></a>
    <?php endfor; ?>
  </div>
</div>

<div class="goal-months mb-4">
  <?php foreach ($bilan as $cle => $b): [$lib, $ico] = $etats[$b['etat']]; ?>
    <div class="goal-month st-<?= $b['etat'] ?>">
      <div class="d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= h(ucfirst(explode(' ', mois_court($cle . '-01'))[0])) ?></span>
        <span class="goal-month-state"><?= $ico ? icon($ico, 13) : '' ?> <?= h($lib) ?></span>
      </div>
      <?php if ($b['objectif'] !== null): ?>
        <div class="goal-month-pct num"><?= $b['etat'] === 'a_venir' ? '—' : pourcent($b['pct']) ?></div>
        <div class="goal-track sm"><span class="goal-fill" style="--goal-pct:<?= round(min(100, (float) $b['pct']), 1) ?>%"></span></div>
        <div class="goal-month-foot"><?= eur($b['realise']) ?> / <?= eur($b['objectif']) ?></div>
      <?php else: ?>
        <div class="goal-month-foot mt-2"><?= $b['realise'] > 0 ? eur($b['realise']) . ' réalisés' : '&nbsp;' ?></div>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>

<?php if ($peutFixer): ?>
<div class="card" id="fixer">
  <div class="card-header">
    Fixer les objectifs <?= $annee ?>
    <span class="card-hint">Visible par la direction seulement</span>
  </div>
  <div class="card-body">
    <p class="text-muted small mb-3">
      CA TTC visé pour chaque mois. Le CA du même mois l'an dernier est donné comme repère.
      Laissez une case vide pour ne pas fixer d'objectif.
    </p>
    <div class="d-flex flex-wrap align-items-center gap-2 mb-3">
      <span class="small">Remplir les cases vides avec le CA N-1</span>
      <div class="input-group input-group-sm" style="width:120px">
        <span class="input-group-text">+</span>
        <input type="number" class="form-control" id="hausseN1" value="5" step="1" min="-50" max="100">
        <span class="input-group-text">%</span>
      </div>
      <button type="button" class="btn btn-sm btn-outline-dark" id="remplirN1">Remplir</button>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="save_objectifs">
      <?= csrf_champ() ?>
      <div class="table-responsive">
        <table class="table align-middle mb-3">
          <thead><tr><th>Mois</th><th class="text-end">CA N-1</th><th class="text-end">Réalisé</th><th style="width:200px">Objectif (€ TTC)</th></tr></thead>
          <tbody>
            <?php foreach ($bilan as $cle => $b): ?>
              <tr>
                <td class="fw-semibold"><?= h(ucfirst(format_mois_fr($annee, $b['mois']))) ?></td>
                <td class="text-end text-muted"><?= $b['ca_n1'] !== null ? eur($b['ca_n1']) : '—' ?></td>
                <td class="text-end"><?= $b['realise'] > 0 ? eur($b['realise']) : '<span class="text-muted">—</span>' ?></td>
                <td>
                  <input type="text" inputmode="decimal" class="form-control form-control-sm text-end" name="objectif[<?= $cle ?>]"
                         value="<?= $b['objectif'] !== null ? h(rtrim(rtrim(number_format($b['objectif'], 2, ',', ''), '0'), ',')) : '' ?>"
                         data-n1="<?= $b['ca_n1'] !== null ? round($b['ca_n1']) : '' ?>" placeholder="—">
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <button type="submit" class="btn btn-dark">Enregistrer les objectifs</button>
    </form>
  </div>
</div>
<script>
document.getElementById('remplirN1').addEventListener('click', function () {
  var hausse = parseFloat(document.getElementById('hausseN1').value) || 0;
  document.querySelectorAll('input[data-n1]').forEach(function (i) {
    if (i.value.trim() !== '' || i.dataset.n1 === '') return;
    // Arrondi à la centaine : un objectif se retient mieux en chiffres ronds
    i.value = Math.round(parseFloat(i.dataset.n1) * (1 + hausse / 100) / 100) * 100;
  });
});
</script>
<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
