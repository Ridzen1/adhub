<?php
/**
 * Activation et gestion de la double authentification du compte connecté.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/double_auth.php';
require_login();

$moi        = current_user();
$compte     = get_user($moi['id']);
$obligatoire = double_auth_est_obligatoire($moi['role_code']);
$errors     = [];
$message    = null;

// Codes de secours à montrer une seule fois, juste après leur création
$codesAffiches = $_SESSION['codes_secours_affichage'] ?? null;
unset($_SESSION['codes_secours_affichage']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_valide()) {
        $errors[] = "Le formulaire a expiré, recommencez.";
    } else {
        $action = $_POST['action'] ?? '';
        $code   = (string) ($_POST['code'] ?? '');

        if ($action === 'activer' && !$compte['totp_enabled']) {
            $secret = $_SESSION['totp_secret_en_cours'] ?? null;
            $pas = $secret ? totp_verifier($secret, $code) : null;
            if ($pas === null) {
                $errors[] = "Code incorrect. Vérifiez que l'heure de votre téléphone est à l'heure automatique et saisissez le code affiché en ce moment.";
            } else {
                double_auth_activer($moi['id'], $secret, $pas);
                unset($_SESSION['totp_secret_en_cours']);
                $_SESSION['totp_enabled'] = true;
                $_SESSION['codes_secours_affichage'] = codes_secours_generer($moi['id']);
                header('Location: /pages/double_authentification.php?active=1');
                exit;
            }
        }

        if ($action === 'nouveaux_codes' && $compte['totp_enabled']) {
            if (totp_verifier($compte['totp_secret'], $code, (int) $compte['totp_last_step']) === null) {
                $errors[] = "Code de l'application incorrect.";
            } else {
                $_SESSION['codes_secours_affichage'] = codes_secours_generer($moi['id']);
                header('Location: /pages/double_authentification.php?codes=1');
                exit;
            }
        }

        if ($action === 'desactiver' && $compte['totp_enabled']) {
            if (!password_verify((string) ($_POST['password'] ?? ''), $compte['password_hash'])) {
                $errors[] = "Mot de passe incorrect.";
            } elseif (totp_verifier($compte['totp_secret'], $code, (int) $compte['totp_last_step']) === null
                      && !code_secours_utiliser($moi['id'], $code)) {
                $errors[] = "Code incorrect.";
            } else {
                double_auth_desactiver($moi['id']);
                $_SESSION['totp_enabled'] = false;
                header('Location: /pages/double_authentification.php?desactive=1');
                exit;
            }
        }
    }
    $compte = get_user($moi['id']);
}

// Activation en cours : un secret est préparé et gardé en session jusqu'à la confirmation
if (!$compte['totp_enabled']) {
    if (empty($_SESSION['totp_secret_en_cours'])) {
        $_SESSION['totp_secret_en_cours'] = totp_nouveau_secret();
    }
    $secretEnCours = $_SESSION['totp_secret_en_cours'];
    $uri = totp_uri($secretEnCours, $compte['email']);
}

$pageTitle = 'Double authentification';
require __DIR__ . '/../inc/layout_header.php';
?>

<?= page_header('Double authentification', 'Un code à 6 chiffres sur votre téléphone, demandé à chaque connexion en plus du mot de passe.', '', 'Mon compte') ?>

<?php foreach ($errors as $e): ?>
  <div class="alert alert-danger"><?= h($e) ?></div>
<?php endforeach; ?>
<?php if (isset($_GET['desactive'])): ?>
  <div class="alert alert-secondary">Double authentification désactivée.</div>
<?php endif; ?>

<?php if ($codesAffiches): ?>
  <div class="card mb-4" style="border-color:#BFE3D2">
    <div class="card-header" style="background:var(--ad-green-soft);color:#0A5A3E">
      <span class="d-flex align-items-center gap-2"><?= icon('check', 16) ?>
        <?= isset($_GET['active']) ? 'Double authentification activée' : 'Nouveaux codes de secours créés' ?></span>
    </div>
    <div class="card-body">
      <p class="mb-3">
        <strong>Notez ces codes de secours maintenant</strong> et rangez-les en lieu sûr (gestionnaire de mots de passe,
        papier dans un coffre). Ils servent si vous perdez votre téléphone. Chaque code ne marche qu'une fois,
        et ils ne seront plus affichés.
        <?php if (isset($_GET['codes'])): ?>Les anciens codes ne fonctionnent plus.<?php endif; ?>
      </p>
      <div id="codesSecours" class="d-grid gap-2 mb-3" style="grid-template-columns:repeat(auto-fill,minmax(150px,1fr));font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:1rem">
        <?php foreach ($codesAffiches as $c): ?>
          <div class="border rounded-2 px-3 py-2 text-center bg-white fw-semibold"><?= h($c) ?></div>
        <?php endforeach; ?>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button type="button" class="btn btn-sm btn-outline-dark" id="copierCodes">Copier</button>
        <button type="button" class="btn btn-sm btn-outline-dark" id="telechargerCodes">Télécharger (.txt)</button>
        <button type="button" class="btn btn-sm btn-outline-dark" onclick="window.print()">Imprimer</button>
      </div>
    </div>
  </div>
  <script>
  (function () {
    var texte = <?= json_encode("Codes de secours AD'HUB — " . $compte['email'] . "\n", JSON_UNESCAPED_UNICODE) ?>
      + <?= json_encode(implode("\n", $codesAffiches)) ?> + "\n\nChaque code ne fonctionne qu'une fois.\n";
    document.getElementById('copierCodes').onclick = function () {
      navigator.clipboard.writeText(texte).then(() => { this.textContent = 'Copié ✓'; });
    };
    document.getElementById('telechargerCodes').onclick = function () {
      var a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([texte], { type: 'text/plain' }));
      a.download = 'adhub-codes-de-secours.txt'; a.click();
    };
  })();
  </script>
<?php endif; ?>

<?php if (!$compte['totp_enabled']): ?>

  <?php if ($obligatoire): ?>
    <div class="alert alert-warning d-flex gap-2 align-items-start">
      <?= icon('alert', 18) ?>
      <div>La double authentification est <strong>obligatoire pour les comptes de la direction</strong>, qui voient toutes les sociétés.
        Activez-la pour accéder à ADHUB : cela prend une minute.</div>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-lg-7">
      <div class="card h-100">
        <div class="card-header">Activer en 3 étapes</div>
        <div class="card-body">
          <ol class="ps-3 mb-0" style="display:grid;gap:18px">
            <li>
              <strong>Installez une application d'authentification</strong> sur votre téléphone si vous n'en avez pas :
              Google Authenticator, Microsoft Authenticator, ou celle de votre gestionnaire de mots de passe (1Password, Bitwarden…).
            </li>
            <li>
              <strong>Scannez ce QR code</strong> avec l'application (bouton « + » ou « Ajouter un compte »).
              <div class="d-flex flex-wrap gap-4 align-items-center mt-3">
                <div id="qrTotp" class="p-2 bg-white border rounded-3" style="width:196px;height:196px" aria-label="QR code à scanner"></div>
                <div class="small text-muted" style="max-width:280px">
                  Impossible de scanner ? Choisissez « Saisir une clé » dans l'application et entrez :
                  <div class="mt-2 p-2 rounded-2 bg-light fw-semibold text-body" style="font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.04em;word-break:break-all">
                    <?= h(totp_secret_lisible($secretEnCours)) ?>
                  </div>
                  <div class="mt-1">Type : basé sur le temps · compte : <?= h($compte['email']) ?></div>
                </div>
              </div>
            </li>
            <li>
              <strong>Saisissez le code à 6 chiffres</strong> affiché par l'application pour confirmer.
              <form method="post" class="d-flex gap-2 mt-3" style="max-width:340px" autocomplete="off">
                <?= csrf_champ() ?>
                <input type="hidden" name="action" value="activer">
                <input type="text" name="code" class="form-control text-center fw-semibold" style="letter-spacing:.3em;font-size:1.1rem"
                       required inputmode="numeric" pattern="[0-9 ]{6,7}" maxlength="7" autocomplete="one-time-code" placeholder="000000">
                <button type="submit" class="btn btn-dark text-nowrap">Activer</button>
              </form>
            </li>
          </ol>
        </div>
      </div>
    </div>
    <div class="col-lg-5">
      <div class="card h-100">
        <div class="card-header">Pourquoi ?</div>
        <div class="card-body text-muted">
          <p>Avec la double authentification, un mot de passe volé ou deviné ne suffit plus : il faut aussi votre téléphone.</p>
          <p class="mb-0">Après l'activation, vous recevrez 10 <strong class="text-body">codes de secours</strong> à conserver,
            pour vous connecter si vous perdez votre téléphone.</p>
        </div>
      </div>
    </div>
  </div>

  <script src="/assets/vendor/qrcode.js"></script>
  <script>
  (function () {
    var qr = qrcode(0, 'M');
    qr.addData(<?= json_encode($uri) ?>);
    qr.make();
    document.getElementById('qrTotp').innerHTML = qr.createSvgTag({ cellSize: 4, margin: 2, scalable: true });
  })();
  </script>

<?php else: ?>

  <div class="kpi-grid">
    <?= kpi('Statut', '<span class="text-success">Activée</span>', [
          'icon' => 'check', 'foot' => $compte['totp_enabled_at'] ? '<span>Depuis le ' . date('d/m/Y', strtotime($compte['totp_enabled_at'])) . '</span>' : '',
        ]) ?>
    <?php $restants = codes_secours_restants($moi['id']); ?>
    <?= kpi('Codes de secours restants', (string) $restants . ' <small>/ ' . TOTP_NB_SECOURS . '</small>', [
          'icon' => 'key', 'foot' => $restants <= 3 ? '<span class="text-danger fw-semibold">Pensez à en créer de nouveaux</span>' : '',
        ]) ?>
  </div>

  <div class="row g-4">
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header">Nouveaux codes de secours</div>
        <div class="card-body">
          <p class="text-muted">Crée 10 nouveaux codes et invalide les anciens. Confirmez avec le code de l'application.</p>
          <form method="post" class="d-flex gap-2" style="max-width:360px" autocomplete="off">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="nouveaux_codes">
            <input type="text" name="code" class="form-control text-center" required inputmode="numeric" maxlength="7" autocomplete="one-time-code" placeholder="Code à 6 chiffres">
            <button type="submit" class="btn btn-outline-dark text-nowrap">Générer</button>
          </form>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="card h-100">
        <div class="card-header"><?= $obligatoire ? "Changer de téléphone" : 'Désactiver' ?></div>
        <div class="card-body">
          <p class="text-muted">
            <?= $obligatoire
                ? "Supprime l'appareil actuel ; vous configurerez aussitôt le nouveau (la double authentification reste obligatoire pour votre rôle)."
                : "Votre compte sera de nouveau protégé par le mot de passe seul." ?>
          </p>
          <form method="post" class="row g-2" autocomplete="off">
            <?= csrf_champ() ?>
            <input type="hidden" name="action" value="desactiver">
            <div class="col-sm-6"><input type="password" name="password" class="form-control" required placeholder="Mot de passe" autocomplete="current-password"></div>
            <div class="col-sm-6"><input type="text" name="code" class="form-control" required placeholder="Code ou code de secours"></div>
            <div class="col-12"><button type="submit" class="btn btn-outline-danger"><?= $obligatoire ? "Changer de téléphone" : 'Désactiver la double authentification' ?></button></div>
          </form>
        </div>
      </div>
    </div>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../inc/layout_footer.php'; ?>
