<?php
/**
 * Boîte à idées côté direction : tous les problèmes et améliorations remontés,
 * avec statut (nouveau / en cours / traité) et réponse visible par l'auteur.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/retours.php';
require_direction();

$migrationOk = retours_disponibles();
$errors = [];

// Sans filtre de statut, on montre ce qui reste à traiter (nouveaux + en cours) ; « Tous » = statut vide
$statutGet    = isset($_GET['statut']) ? (string) $_GET['statut'] : null;
$aTraiter     = $statutGet === null;
$filtreStatut = isset(RETOUR_STATUTS[$statutGet ?? '']) ? $statutGet : null;
$filtreType   = isset(RETOUR_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : null;

$retourUrl = '/pages/admin_retours.php?' . http_build_query(array_filter([
    'statut' => $statutGet,
    'type'   => $filtreType,
], fn($v) => $v !== null));

if ($migrationOk && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $errors[] = "Le formulaire a expiré, rechargez la page.";
    } else {
        $id = (int) ($_POST['id'] ?? 0);
        $action = $_POST['action'] ?? '';
        if ($action === 'traiter') {
            traiter_retour($id, (string) ($_POST['statut'] ?? ''), (string) ($_POST['reponse'] ?? ''), current_user()['id']);
            header('Location: ' . $retourUrl . '#retour-' . $id);
            exit;
        }
        if ($action === 'supprimer') {
            supprimer_retour($id);
            header('Location: ' . $retourUrl);
            exit;
        }
    }
}

$compteurs = retours_compteurs();
$retours = [];
if ($migrationOk) {
    $retours = retours_tous($filtreStatut, $filtreType);
    if ($aTraiter) {
        $retours = array_values(array_filter($retours, fn($r) => $r['statut'] !== 'traite'));
    }
}

/** Lien d'onglet de filtre en gardant le filtre de type */
function lien_filtre(?string $statut, ?string $type): string
{
    $q = [];
    if ($statut !== null) {
        $q['statut'] = $statut;
    }
    if ($type !== null) {
        $q['type'] = $type;
    }
    return '/pages/admin_retours.php' . ($q ? '?' . http_build_query($q) : '');
}

$onglets = [
    [null,       'À traiter', $compteurs['nouveau'] + $compteurs['en_cours']],
    ['nouveau',  'Nouveaux',  $compteurs['nouveau']],
    ['en_cours', 'En cours',  $compteurs['en_cours']],
    ['traite',   'Traités',   $compteurs['traite']],
    ['',         'Tous',      array_sum($compteurs)],
];

$pageTitle = 'Boîte à idées';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header(
    'Boîte à idées',
    'Problèmes et améliorations envoyés par les utilisateurs. Visible par la direction uniquement.',
    '<a href="/pages/remonter.php" class="btn btn-sm btn-outline-dark">' . icon('plus', 15) . ' Écrire un message</a>',
    'Administration'
) ?>

<?php if (!$migrationOk): ?>
  <div class="alert alert-warning">Migration <code>database/020_boite_a_idees.sql</code> à passer pour activer la boîte à idées.</div>
<?php else: ?>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <ul class="nav nav-pills retour-filtres">
    <?php foreach ($onglets as [$code, $libelle, $n]): ?>
      <?php $actif = ($code === null && $statutGet === null) || ($code !== null && $statutGet === $code); ?>
      <li class="nav-item">
        <a class="nav-link <?= $actif ? 'active' : '' ?>" href="<?= h(lien_filtre($code, $filtreType)) ?>">
          <?= h($libelle) ?> <span class="retour-compte"><?= $n ?></span>
        </a>
      </li>
    <?php endforeach; ?>
  </ul>
  <div class="ms-auto d-flex gap-1">
    <a class="btn btn-sm <?= $filtreType === null ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= h(lien_filtre($statutGet, null)) ?>">Tout</a>
    <?php foreach (RETOUR_TYPES as $code => $libelle): ?>
      <a class="btn btn-sm <?= $filtreType === $code ? 'btn-dark' : 'btn-outline-dark' ?>" href="<?= h(lien_filtre($statutGet, $code)) ?>"><?= h($libelle) ?>s</a>
    <?php endforeach; ?>
  </div>
</div>

<?php if (!$retours): ?>
  <div class="card"><div class="card-body text-center text-muted py-5">
    <?= $aTraiter ? 'Rien à traiter pour le moment.' : 'Aucun message.' ?>
  </div></div>
<?php endif; ?>

<?php foreach ($retours as $r): ?>
  <div class="card mb-3 retour-carte <?= $r['statut'] === 'nouveau' ? 'is-new' : '' ?>" id="retour-<?= (int) $r['id'] ?>">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <?= badge_type_retour($r['type']) ?>
        <?= badge_statut_retour($r['statut']) ?>
        <span class="fw-semibold"><?= h($r['auteur'] ?? 'Compte supprimé') ?></span>
        <?php if (!empty($r['auteur_email'])): ?>
          <a class="small" href="mailto:<?= h($r['auteur_email']) ?>"><?= h($r['auteur_email']) ?></a>
        <?php endif; ?>
        <span class="text-muted small ms-auto"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
      </div>

      <h2 class="h6 mb-1"><?= h($r['sujet']) ?></h2>
      <div class="retour-texte"><?= nl2br(h($r['message'])) ?></div>
      <?php if ($r['page']): ?>
        <div class="small text-muted mt-2">Envoyé depuis <a href="<?= h($r['page']) ?>"><code><?= h($r['page']) ?></code></a></div>
      <?php endif; ?>

      <form method="post" class="retour-traitement mt-3">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="traiter">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <label class="form-label small text-muted mb-1" for="rep-<?= (int) $r['id'] ?>">
          Réponse à l'auteur (facultative, il la verra dans « Mes messages »)
          <?php if ($r['repondu_le']): ?>· envoyée par <?= h($r['repondant'] ?? '—') ?> le <?= date('d/m/Y', strtotime($r['repondu_le'])) ?><?php endif; ?>
        </label>
        <textarea id="rep-<?= (int) $r['id'] ?>" name="reponse" class="form-control mb-2" rows="2" maxlength="<?= RETOUR_MESSAGE_MAX ?>"><?= h($r['reponse'] ?? '') ?></textarea>
        <div class="d-flex flex-wrap align-items-center gap-2">
          <select name="statut" class="form-select form-select-sm w-auto">
            <?php foreach (RETOUR_STATUTS as $code => $libelle): ?>
              <option value="<?= $code ?>" <?= $r['statut'] === $code ? 'selected' : '' ?>><?= h($libelle) ?></option>
            <?php endforeach; ?>
          </select>
          <button type="submit" class="btn btn-sm btn-dark">Enregistrer</button>
          <?php if ($r['statut'] !== 'traite'): ?>
            <button type="submit" name="statut" value="traite" class="btn btn-sm btn-outline-dark"><?= icon('check', 14) ?> Marquer traité</button>
          <?php endif; ?>
        </div>
      </form>
      <form method="post" class="text-end mt-2" onsubmit="return confirm('Supprimer définitivement ce message ?');">
        <?= csrf_champ() ?>
        <input type="hidden" name="action" value="supprimer">
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <button type="submit" class="btn btn-link btn-sm text-danger p-0">Supprimer</button>
      </form>
    </div>
  </div>
<?php endforeach; ?>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
