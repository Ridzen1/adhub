<?php
/**
 * Faux back-office Jalia (Popina) pour tester popina_sync.php sans toucher au vrai site.
 * Reproduit ce qui a été relevé le 25/10/2026 : formulaire de connexion Rails + CSRF,
 * cookie de session, page statistiques, catégories en HTML, exports Excel (vrais fichiers du 21 et 22/10).
 *
 * Lancement : php -S 127.0.0.1:8098 mock/router.php
 * Compte de test : come@test.fr / popina123  (établissement 10900)
 */

const EMAIL = 'come@test.fr';
const PASSWORD = 'popina123';
const ETAB = 10900;
const FIX = __DIR__ . '/fixtures';
const STATE = __DIR__ . '/state_popina.json';
const MACROS = ['CARNÉ', 'VÉGÉ', 'DESSERTS', 'CAVE', 'MARIN', 'SOFTS', 'COCKTAILS', 'ALCOOLS FORTS', 'BIERES/CIDRES', 'Suggestion'];

function st(): array { return is_file(STATE) ? json_decode(file_get_contents(STATE), true) : ['sessions' => [], 'count' => []]; }
function save(array $s): void { file_put_contents(STATE, json_encode($s)); }
function bump(string $k): void { $s = st(); $s['count'][$k] = ($s['count'][$k] ?? 0) + 1; save($s); }

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Session (cookie)
$sid = $_COOKIE['_jalia_session'] ?? null;
$s = st();
if (!$sid || !isset($s['sessions'][$sid])) {
    $sid = bin2hex(random_bytes(8));
    $s['sessions'][$sid] = ['csrf' => base64_encode(random_bytes(24)), 'user' => null];
    save($s);
    setcookie('_jalia_session', $sid, ['path' => '/', 'httponly' => true]);
}
$sess = $s['sessions'][$sid];
function logged(): bool { global $sess; return $sess['user'] !== null; }
function csrfOk(): bool { global $sess; return ($_POST['authenticity_token'] ?? '') === $sess['csrf']; }
function redirect(string $to) { header("Location: $to", true, 302); exit; }
function needLogin() { if (!logged()) redirect('/fr/session/index?redirect=%2Ffr'); }

function loginPage(string $msg = '') {
    global $sess;
    $t = htmlspecialchars($sess['csrf']);
    echo "<html><body>$msg<form action=\"/fr/session/login\" accept-charset=\"UTF-8\" method=\"post\">"
       . "<input name=\"utf8\" type=\"hidden\" value=\"&#x2713;\" /><input type=\"hidden\" name=\"authenticity_token\" value=\"$t\" />"
       . "<input type=\"email\" name=\"email\" /><input type=\"password\" name=\"password\" /><input type=\"submit\" name=\"commit\" value=\"Connexion\" /></form></body></html>";
    exit;
}

/** "22.10.2026" -> "2026-10-22" */
function isoDay(string $fr): string { [$d, $m, $y] = explode('.', $fr); return "$y-$m-$d"; }

// ------------------------------------------------------------------ routes

if ($path === '/fr/session/login' && $method === 'GET') loginPage();
if ($path === '/fr/session/index') loginPage();

if ($path === '/fr/session/login' && $method === 'POST') {
    if (!csrfOk()) { http_response_code(422); echo 'InvalidAuthenticityToken'; exit; }
    if (($_POST['email'] ?? '') === EMAIL && ($_POST['password'] ?? '') === PASSWORD) {
        $s = st(); $s['sessions'][$sid]['user'] = EMAIL; save($s); bump('login');
        redirect('/fr');
    }
    loginPage('<div class="alert">Email ou mot de passe incorrect</div>');
}

if ($path === '/fr/session/logout') {
    $s = st(); unset($s['sessions'][$sid]); save($s); bump('logout');
    redirect('/fr/session/index');
}

// Comme le vrai site : /fr renvoie vers l'établissement, /fr/dashboard n'existe pas
if ($path === '/fr' || $path === '/') {
    needLogin();
    redirect('/fr/dashboard/establishment/' . ETAB);
}
if ($path === '/fr/dashboard/account') {
    needLogin();
    echo '<html><body><a href="/fr/dashboard/establishment/' . ETAB . '/settings">Réglages</a></body></html>';
    exit;
}

if (preg_match('#^/fr/dashboard/establishment/(\d+)$#', $path)) {
    needLogin();
    echo '<html><body><a href="/fr/dashboard/establishment/' . ETAB . '/statistics">Stats</a></body></html>';
    exit;
}

if (preg_match('#^/fr/dashboard/establishment/(\d+)/statistics$#', $path, $m)) {
    needLogin();
    if ((int) $m[1] !== ETAB) { http_response_code(404); exit; }
    $t = htmlspecialchars($sess['csrf']);
    echo "<html><body><form action=\"/fr/dashboard/export_tills.xlsx\" method=\"post\"><input type=\"hidden\" name=\"authenticity_token\" value=\"$t\" /></form></body></html>";
    exit;
}

if (preg_match('#^/fr/dashboard/establishment/(\d+)/statistics_all_categories_data$#', $path)) {
    needLogin();
    $day = isoDay($_GET['date_start'] ?? '01.01.2000');
    $html = '<table class="macro_select">';
    if (is_file(FIX . "/items_$day.xlsx")) {
        foreach (MACROS as $mac) $html .= '<tr><td><input type="checkbox" name="macro_names[]" value="' . htmlspecialchars($mac) . '" checked="checked" /></td></tr>';
    }
    echo $html . '</table>';
    exit;
}

if ($path === '/fr/dashboard/export_tills.xlsx' && $method === 'POST') {
    needLogin();
    if (!csrfOk()) { http_response_code(422); echo 'InvalidAuthenticityToken'; exit; }
    if ((int) ($_POST['establishment_id'] ?? 0) !== ETAB) { http_response_code(404); exit; }
    $day = isoDay($_POST['date_start'] ?? '01.01.2000');
    if (isset($_POST['export_accounting'])) $kind = 'accounting';
    elseif (isset($_POST['export_items'])) $kind = 'items';
    else { http_response_code(400); exit; }
    bump("export_$kind");
    $file = FIX . "/{$kind}_$day.xlsx";
    // Comme le vrai site : sans catégories cochées, l'export Produits est vide
    if (!is_file($file) || ($kind === 'items' && empty($_POST['macro_names']))) $file = FIX . "/{$kind}_vide.xlsx";
    header('Content-Type: application/xlsx; charset=iso-8859-1');
    header("Content-Disposition: attachment; filename=jalia_export_{$kind}.xlsx");
    readfile($file);
    exit;
}

http_response_code(404);
echo "Route inconnue : $method $path";
