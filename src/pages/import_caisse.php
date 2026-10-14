<?php
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/projects.php';
require_once __DIR__ . '/../inc/daily.php';
require_once __DIR__ . '/../inc/rapport_caisse.php';
require_login();

$id = (int) ($_GET['id'] ?? 0);
$project = get_project($id);

if (!$project) {
    http_response_code(404);
    echo "Projet introuvable.";
    exit;
}

require_project_access($id, 'dashboard');
require_project_edit($id); // l'import écrit des données

$analyse = null;
$texte   = '';
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $texte  = $_POST['rapport'] ?? '';

    if ($action === 'analyser') {
        if (trim($texte) === '') {
            $errors[] = "Collez d'abord le contenu du rapport.";
        } else {
            $analyse = parser_rapport_caisse($texte);
        }
    }

    if ($action === 'confirmer') {
        $analyse = parser_rapport_caisse($texte);

        if ($analyse['erreurs']) {
            $errors = $analyse['erreurs'];
        } else {
            $user = current_user();
            upsert_daily_entry(
                $id,
                $analyse['entry_date'],
                (float) $analyse['ca_ttc'],
                (int) $analyse['couverts'],
                null,
                'email',
                $user['id'],
                [
                    'ca_ht'               => $analyse['ca_ht'],
                    'remises'             => $analyse['remises'],
                    'offerts'             => $analyse['offerts'],
                    'ticket_moyen'        => $analyse['ticket_moyen'],
                    'corrections_cuisine' => $analyse['corrections_cuisine'],
                    'ca_solide'           => $analyse['ca_solide'],
                    'ca_liquide'          => $analyse['ca_liquide'],
                ]
            );
            header("Location: /pages/saisie_quotidienne.php?id={$id}&saved=1");
            exit;
        }
    }
}

$pageTitle = $project['name'] . ' — Import du rapport de caisse';
require __DIR__ . '/../inc/layout_header.php';
?>

<div class="d-flex justify-content-between align-items-start mb-4">
  <h1 class="mb-0"><?= htmlspecialchars($project['name']) ?></h1>
  <div>
    <a href="/pages/saisie_quotidienne.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Saisie manuelle</a>
    <a href="/pages/project_dashboard.php?id=<?= $id ?>" class="btn btn-sm btn-outline-dark">Dashboard</a>
  </div>
</div>

<h5 class="text-muted mb-3">Import d'un rapport de caisse</h5>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= htmlspecialchars($e) ?></div>
<?php endforeach; ?>

<div class="card mb-4">
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="action" value="analyser">
      <label class="form-label">Contenu du courriel reçu</label>
      <textarea name="rapport" class="form-control" rows="10"
                placeholder="Collez ici le texte complet du rapport quotidien…"><?= htmlspecialchars($texte) ?></textarea>
      <div class="form-text mb-3">
        Copiez le courriel entier, en-tête et tableau des ventes compris. L'outil extrait ce dont il a besoin.
      </div>
      <button type="submit" class="btn btn-dark">Analyser</button>
    </form>
  </div>
</div>

<?php if ($analyse !== null && !$analyse['erreurs']):
  $existante = get_daily_entry($id, $analyse['entry_date']);
  $panier = $analyse['couverts'] > 0 ? $analyse['ca_ttc'] / $analyse['couverts'] : null;
?>
  <div class="card border-dark mb-4">
    <div class="card-header bg-dark text-white">
      Vérification avant enregistrement — journée du
      <?= date('d/m/Y', strtotime($analyse['entry_date'])) ?>
    </div>
    <div class="card-body">

      <?php if ($existante): ?>
        <div class="alert alert-warning">
          Une saisie existe déjà pour cette journée
          (<?= number_format((float) $existante['ca_ttc'], 2, ',', ' ') ?> €,
          source « <?= htmlspecialchars($existante['source']) ?> »).
          Confirmer la remplacera.
        </div>
      <?php endif; ?>

      <?php foreach ($analyse['avertissements'] as $a): ?>
        <div class="alert alert-warning py-2"><?= htmlspecialchars($a) ?></div>
      <?php endforeach; ?>

      <table class="table table-sm">
        <tr><td class="text-muted" style="width:40%">Chiffre d'affaires TTC</td>
            <td class="fw-bold"><?= number_format((float) $analyse['ca_ttc'], 2, ',', ' ') ?> €</td></tr>
        <tr><td class="text-muted">Chiffre d'affaires HT</td>
            <td><?= $analyse['ca_ht'] !== null ? number_format((float) $analyse['ca_ht'], 2, ',', ' ') . ' €' : '—' ?></td></tr>
        <tr><td class="text-muted">Couverts</td>
            <td class="fw-bold"><?= (int) $analyse['couverts'] ?></td></tr>
        <tr><td class="text-muted">Panier moyen (par couvert)</td>
            <td><?= $panier !== null ? number_format($panier, 2, ',', ' ') . ' €' : '—' ?></td></tr>
        <tr><td class="text-muted">Ticket moyen (par addition)</td>
            <td><?= $analyse['ticket_moyen'] !== null ? number_format((float) $analyse['ticket_moyen'], 2, ',', ' ') . ' €' : '—' ?></td></tr>
        <tr><td class="text-muted">Remises / Offerts</td>
            <td><?= number_format((float) ($analyse['remises'] ?? 0), 2, ',', ' ') ?> €
                / <?= number_format((float) ($analyse['offerts'] ?? 0), 2, ',', ' ') ?> €</td></tr>
        <tr><td class="text-muted">Corrections après envoi en cuisine</td>
            <td><?= $analyse['corrections_cuisine'] !== null ? number_format((float) $analyse['corrections_cuisine'], 2, ',', ' ') . ' €' : '—' ?></td></tr>
        <tr><td class="text-muted">Ventes solide / liquide</td>
            <td>
              <?= $analyse['ca_solide'] !== null ? number_format((float) $analyse['ca_solide'], 2, ',', ' ') . ' €' : '—' ?>
              /
              <?= $analyse['ca_liquide'] !== null ? number_format((float) $analyse['ca_liquide'], 2, ',', ' ') . ' €' : '—' ?>
            </td></tr>
      </table>

      <form method="post">
        <input type="hidden" name="action" value="confirmer">
        <input type="hidden" name="rapport" value="<?= htmlspecialchars($texte) ?>">
        <button type="submit" class="btn btn-dark">Enregistrer cette journée</button>
      </form>
    </div>
  </div>
<?php elseif ($analyse !== null && $analyse['erreurs']): ?>
  <div class="alert alert-danger">
    <strong>Rapport non reconnu :</strong>
    <ul class="mb-0">
      <?php foreach ($analyse['erreurs'] as $e): ?>
        <li><?= htmlspecialchars($e) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<div class="alert alert-light border small">
  Le rapport ne couvre que les <strong>services clôturés en caisse</strong>. Si un service n'a pas
  été fermé, la journée sera partielle et la suivante pourra l'absorber : les journées atypiques
  sont signalées dans l'historique, sans être corrigées automatiquement.
</div>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
