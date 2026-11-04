<?php
/**
 * Boîte à idées côté utilisateur : envoyer un problème ou une amélioration,
 * et suivre ses propres messages. Les messages des autres ne sont jamais affichés ici.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_once __DIR__ . '/../inc/retours.php';
require_login();

$user   = current_user();
$errors = [];
$migrationOk = retours_disponibles();

// Valeurs du formulaire (reprises en cas d'erreur)
$form = [
    'type'    => in_array($_GET['type'] ?? '', array_keys(RETOUR_TYPES), true) ? $_GET['type'] : 'probleme',
    'sujet'   => '',
    'message' => '',
    'page'    => '',
];

// Page d'où vient l'utilisateur : jointe au message pour aider à reproduire un problème
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if ($referer !== '' && parse_url($referer, PHP_URL_HOST) === explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]) {
    $form['page'] = page_d_origine($referer) ?? '';
}

if ($migrationOk && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'envoyer') {
    $form = [
        'type'    => (string) ($_POST['type'] ?? ''),
        'sujet'   => (string) ($_POST['sujet'] ?? ''),
        'message' => (string) ($_POST['message'] ?? ''),
        'page'    => (string) ($_POST['page'] ?? ''),
    ];
    if (!csrf_valide()) {
        $errors[] = "Le formulaire a expiré, rechargez la page.";
    } else {
        $errors = envoyer_retour($user['id'], $form['type'], $form['sujet'], $form['message'], $form['page']);
        if (!$errors) {
            header('Location: /pages/remonter.php?envoye=1');
            exit;
        }
    }
}

$mesRetours = $migrationOk ? retours_de_l_utilisateur($user['id']) : [];

$pageTitle = 'Boîte à idées';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header(
    'Une idée, un problème ?',
    'Signalez ce qui ne fonctionne pas ou proposez une amélioration. Votre message est lu par la direction uniquement.',
    is_direction() ? '<a href="/pages/admin_retours.php" class="btn btn-sm btn-outline-dark">Voir tous les messages</a>' : ''
) ?>

<?php if (!$migrationOk): ?>
  <?php if (is_direction()): ?>
    <div class="alert alert-warning">La boîte à idées n'est pas encore activée (migration <code>020_boite_a_idees.sql</code> à passer).</div>
  <?php else: ?>
    <div class="card"><div class="card-body empty-state">La boîte à idées n'est pas encore disponible.</div></div>
  <?php endif; ?>
<?php else: ?>

<?php if (isset($_GET['envoye'])): ?>
  <div class="alert alert-success">Merci, votre message a bien été envoyé à la direction. Vous suivrez sa prise en charge ci-dessous.</div>
<?php endif; ?>
<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>

<div class="row g-4">
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Nouveau message</div>
      <div class="card-body">
        <form method="post" class="d-grid gap-3">
          <?= csrf_champ() ?>
          <input type="hidden" name="action" value="envoyer">
          <input type="hidden" name="page" value="<?= h($form['page']) ?>">

          <div>
            <label class="form-label">C'est…</label>
            <div class="retour-types">
              <?php foreach (RETOUR_TYPES as $code => $libelle): ?>
                <input type="radio" class="btn-check" name="type" id="type-<?= $code ?>" value="<?= $code ?>" <?= $form['type'] === $code ? 'checked' : '' ?> required>
                <label class="btn btn-outline-dark" for="type-<?= $code ?>">
                  <?= icon($code === 'probleme' ? 'alert' : 'bulb', 16) ?>
                  <?= $code === 'probleme' ? 'Un problème' : 'Une amélioration' ?>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div>
            <label class="form-label" for="sujet">Sujet</label>
            <input type="text" id="sujet" name="sujet" class="form-control" maxlength="<?= RETOUR_SUJET_MAX ?>" required
                   value="<?= h($form['sujet']) ?>" placeholder="Ex. : le CA d'hier ne s'affiche pas">
          </div>

          <div>
            <label class="form-label" for="message">Détails</label>
            <textarea id="message" name="message" class="form-control" rows="6" maxlength="<?= RETOUR_MESSAGE_MAX ?>" required
                      placeholder="Que s'est-il passé ? Sur quelle société, quelle page ? Ou : qu'est-ce qui vous ferait gagner du temps ?"><?= h($form['message']) ?></textarea>
          </div>

          <?php if ($form['page'] !== ''): ?>
            <div class="text-muted small">Page jointe au message : <code><?= h($form['page']) ?></code></div>
          <?php endif; ?>

          <div>
            <button type="submit" class="btn btn-dark">Envoyer à la direction</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Mes messages</div>
      <?php if (!$mesRetours): ?>
        <div class="card-body text-muted">Vous n'avez encore rien envoyé.</div>
      <?php else: ?>
        <ul class="list-unstyled mb-0 retour-liste">
          <?php foreach ($mesRetours as $r): ?>
            <li class="retour-item">
              <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                <?= badge_type_retour($r['type']) ?>
                <?= badge_statut_retour($r['statut']) ?>
                <span class="text-muted small ms-auto"><?= date('d/m/Y H:i', strtotime($r['created_at'])) ?></span>
              </div>
              <div class="fw-semibold"><?= h($r['sujet']) ?></div>
              <div class="retour-texte"><?= nl2br(h($r['message'])) ?></div>
              <?php if ($r['reponse'] !== null && $r['reponse'] !== ''): ?>
                <div class="retour-reponse">
                  <div class="small fw-semibold mb-1">
                    Réponse de <?= h($r['repondant'] ?? 'la direction') ?>
                    <?php if ($r['repondu_le']): ?><span class="text-muted fw-normal">· <?= date('d/m/Y', strtotime($r['repondu_le'])) ?></span><?php endif; ?>
                  </div>
                  <?= nl2br(h($r['reponse'])) ?>
                </div>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
