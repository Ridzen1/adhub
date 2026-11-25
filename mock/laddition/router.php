<?php
/**
 * Faux serveur L'Addition pour tester laddition_sync.php sans toucher à la vraie plateforme.
 * Routes et formats de réponse reproduits d'après le HAR capturé le 23/10/2026.
 *
 * Lancement : php -S 127.0.0.1:8099 mock/router.php
 * Config    : 'laddition_auth_api'      => 'http://127.0.0.1:8099/auth/v2/api/',
 *             'laddition_reporting_api' => 'http://127.0.0.1:8099/reporting/api/',
 *             'laddition_data_url'      => 'http://127.0.0.1:8099/data/',
 *
 * Depuis le 20/11/2026, la route dataExport/getOrderLines/ n'existe plus (404, comme en vrai) :
 * les lignes viennent de l'export Excel data/scripts/reporting/v1/SalesDocumentLines.php.
 *
 * Comptes de test :
 *   bellevue@test.fr / test123  -> 1 établissement (26573 "Le bellevue ")
 *   resto2@test.fr   / test456  -> 1 établissement (30001 "Resto test 2")
 */

const ACCOUNTS = [
    'bellevue@test.fr' => ['password' => 'test123', 'id' => 29819, 'companies' => [['id_company' => 26573, 'company_db_name' => 'add-le_bellevue_2930', 'company_name' => 'Le bellevue ']]],
    'resto2@test.fr'   => ['password' => 'test456', 'id' => 31000, 'companies' => [['id_company' => 30001, 'company_db_name' => 'add-resto_test_3000', 'company_name' => 'Resto test 2']]],
];
const STATE = __DIR__ . '/state.json'; // compteurs pour vérifier login/logout

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$body   = json_decode(file_get_contents('php://input') ?: 'null', true);
header('Content-Type: application/json; charset=utf-8');

function out(int $code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function jwt(array $claims) { return b64('{"alg":"HS256","typ":"JWT"}') . '.' . b64(json_encode($claims)) . '.' . b64('fake-signature'); }
function claims(string $jwt): ?array {
    $p = explode('.', $jwt);
    if (count($p) !== 3) return null;
    return json_decode(base64_decode(strtr($p[1], '-_', '+/')), true);
}
function bump(string $k) {
    $s = is_file(STATE) ? json_decode(file_get_contents(STATE), true) : [];
    $s[$k] = ($s[$k] ?? 0) + 1;
    file_put_contents(STATE, json_encode($s));
}
function authUser(): array {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^accessToken (\S+)$/', $h, $m)) out(401, ['success' => false, 'message' => 'Unauthorized']);
    $c = claims($m[1]);
    if (!$c || ($c['kind'] ?? '') !== 'access' || $c['exp'] < time()) out(401, ['success' => false, 'message' => 'Token expired']);
    return $c;
}

/** Génère des lignes de vente déterministes, au format réel relevé le 24/10/2026. */
function fakeLines(int $idCompany, string $name, string $day): array {
    mt_srand(crc32("$idCompany-$day"));
    $products = [ // [type, catégorie, nom, ttc, taux]
        ['Cocktail', 'Boissons', 'Mojito', 9.0, 0.20], ['Bière', 'Boissons', 'Pression 50cl', 7.5, 0.20],
        ['Café', 'Boisson Chaude', 'Expresso', 2.5, 0.10], ['Soft', 'Boissons', 'Coca-Cola 33cl', 4.0, 0.10],
        ['Viande', 'Plat', 'Burger maison', 16.5, 0.10], ['Dessert', 'Dessert', 'Tiramisu', 7.0, 0.10],
    ];
    $salles = [['def0-1', 'RDC'], ['def0-2', '1er Etage']];
    $date = DateTime::createFromFormat('Y-m-d', $day)->format('d-m-Y');
    $seq = 0;
    $mk = function (string $type, string $cat, string $nom, float $ttc, float $taux, string $parent, array $salle, string $heure) use ($name, $idCompany, &$seq, $day) {
        $seq++;
        $ht = round($ttc / (1 + $taux), 2);
        return [
            'ID_LIGNE_VENTE' => sprintf('AB5AM-%d%s%04d', $idCompany % 1000, str_replace('-', '', $day), $seq),
            'ETABLISSEMENT' => $name, 'ID_PRODUIT' => 'web-' . substr(md5($nom), 0, 9), 'NOM PRODUIT' => $nom,
            'TYPE PRODUIT' => $type, 'CATEGORIE PRODUIT' => $cat, 'TAUX DE TVA' => $taux,
            'NOM TVA' => mt_rand(1, 8) === 1 ? null : str_replace('.', ',', (string) ($taux * 100)) . '%',
            'TVA' => round($ttc - $ht, 2), 'HT' => $ht, 'TTC' => $ttc, 'ID_PARENT' => $parent,
            'ID_SALLES' => $salle[0], 'NOM SALLES' => $salle[1], 'HEURE VENTE' => $heure,
        ];
    };
    $lines = [];
    $n = mt_rand(150, 400);
    for ($i = 1; $i <= $n; $i++) {
        $salle = $salles[mt_rand(0, 1)];
        $heure = sprintf('%s %02d:%02d', $date, mt_rand(11, 22), mt_rand(0, 59));
        if (mt_rand(1, 6) === 1) {
            // Menu : ligne parente à 0 €, le prix est réparti sur les plats enfants
            $menu = $mk('Menu', 'Menu', 'Entrée + Plat', 0.0, 0.10, '0', $salle, $heure);
            $lines[] = $menu;
            $lines[] = $mk('Poisson', 'Entrée', 'Velouté', 6.52, 0.10, $menu['ID_LIGNE_VENTE'], $salle, $heure);
            $lines[] = $mk('Viande', 'Plat', 'Plat du jour', 15.48, 0.10, $menu['ID_LIGNE_VENTE'], $salle, $heure);
        } else {
            [$type, $cat, $nom, $ttc, $taux] = $products[mt_rand(0, count($products) - 1)];
            $lines[] = $mk($type, $cat, $nom, $ttc, $taux, '0', $salle, $heure);
        }
    }
    return $lines;
}

// ------------------------------------------------------------------ routes

// Connexion
if ($method === 'POST' && $path === '/auth/v2/api/auth') {
    $acc = ACCOUNTS[$body['identifier'] ?? ''] ?? null;
    if (!$acc || $acc['password'] !== ($body['password'] ?? null)) {
        out(401, ['success' => false, 'message' => 'Identifiant ou mot de passe incorrect']);
    }
    bump('login');
    $rt = jwt(['id' => $acc['id'], 'username' => $body['identifier'], 'type' => 'user_laddition', 'kind' => 'refresh', 'iat' => time()]);
    setcookie('refreshToken', $rt, ['expires' => time() + 2592000, 'path' => '/']);
    out(200, ['success' => true, 'refreshToken' => $rt, 'back' => ['cookies' => 1, 'url' => 'https://espace-client.laddition.com/', 'scope' => 'espace_client'], 'dbStatus' => 'online']);
}

// Déconnexion
if ($method === 'POST' && $path === '/auth/v2/api/auth/logout') {
    if (!preg_match('/^refreshToken \S+$/', $_SERVER['HTTP_AUTHORIZATION'] ?? '')) out(401, ['success' => false]);
    bump('logout');
    out(200, ['success' => true]);
}

// refreshToken -> accessToken
if ($method === 'GET' && $path === '/reporting/api/auth/accessToken/') {
    $c = claims($_GET['refreshToken'] ?? '');
    if (!$c || ($c['kind'] ?? '') !== 'refresh') out(401, ['success' => false, 'message' => 'Invalid refresh token']);
    out(200, ['success' => true, 'accessToken' => jwt(['id' => $c['id'], 'username' => $c['username'], 'kind' => 'access', 'iat' => time(), 'exp' => time() + 900])]);
}

// Informations (liste des établissements)
if ($method === 'GET' && $path === '/reporting/api/informations') {
    $u = authUser();
    out(200, ['categories' => [], 'messages' => [], 'notifications' => [], 'faq' => [], 'companies' => ACCOUNTS[$u['username']]['companies']]);
}

// Export Excel « Lignes de ventes » (nouveau reporting, relevé le 25/11/2026)
if ($method === 'GET' && $path === '/data/scripts/reporting/v1/SalesDocumentLines.php') {
    $db = $_GET['db'] ?? '';
    $company = null;
    foreach (ACCOUNTS as $acc) foreach ($acc['companies'] as $c) if ($c['company_db_name'] === $db) $company = $c;
    $sigOk = false;
    foreach ([0, -1, -2] as $d) $sigOk = $sigOk || ($_GET['signature'] ?? '') === md5($db . '#' . substr((string) (time() + $d), -5));
    if (!$company || !$sigOk) { header('Content-Type: text/html'); http_response_code(403); echo 'Forbidden'; exit; }
    $from = $_GET['date_start'] ?? ''; $to = $_GET['date_end'] ?? '';
    $entete = ['Etablissement', 'Jour', 'TAG_Jour/Sem', 'TAG_Split', 'TAG_LiquideUniquement (commande avec liquide seulement)', 'TAG_Heure', 'TAG_Minutes', 'Timestamp', 'ID Ticket', 'ID Receipt', 'ID user', 'Nom user', 'ID', 'ID produit', 'SKU', 'Menu parent', 'Nom', 'Modificateurs', 'Qte', 'Prix TTC', 'Prix achat HT', 'Prix achat origine', 'Prix achat MàJ', 'TAG_TypePrix', 'TAG_Discount', 'Remises TTC', 'TAG_TypeDiscount', 'TAG_Offered', 'Offerts TTC', 'Taux', 'TAG_Catégorie', 'TAG_TypeProduit', 'TAG_Typologie', 'TAG_Famille', 'TAG_Salle', 'TAG_Table', 'TAG_Type', 'Clients', 'Paiements', 'URL Ticket'];
    $rows = [$entete];
    for ($d = $from; $d && $d <= $to; $d = date('Y-m-d', strtotime("$d +1 day"))) {
        foreach (fakeLines((int) $company['id_company'], $company['company_name'], $d) as $l) {
            if ((float) $l['TTC'] == 0 && $l['TYPE PRODUIT'] === 'Menu') continue; // pas de ligne menu à 0 € dans l'export
            $dt = DateTime::createFromFormat('d-m-Y H:i', $l['HEURE VENTE']);
            $serial = ($dt->getTimestamp() + $dt->getOffset()) / 86400 + 25569;
            $taux = str_replace('.', ',', (string) ($l['TAUX DE TVA'] * 100)) . '%';
            $rows[] = [$l['ETABLISSEMENT'], $d, 'Vendredi', 'Midi (11h30-15h30)', 'NON', (int) $dt->format('G'), (int) $dt->format('i'), $serial,
                'T-1', 'R-1', 'U-1', 'Serveur T.', $l['ID_LIGNE_VENTE'], $l['ID_PRODUIT'], '', '-', $l['NOM PRODUIT'], '', 1, $l['TTC'], 0, '-', '-',
                'Normal', 'NON', '0.00', '-', 'NON', '0.00', $taux, $l['CATEGORIE PRODUIT'], $l['TYPE PRODUIT'], 'Solide', 'Sans Famille', $l['NOM SALLES'], '12', 'Sur place', '', 'CB', ''];
        }
    }
    $rows[] = array_merge(['Total'], array_fill(0, count($entete) - 1, '-'));
    $feuilles = ['Accueil' => [['Titre', 'Description'], ['SalesDocumentLines', 'Toutes les ventes produits (ligne par ligne)']], 'SalesDocumentLines' => $rows];
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $company['id_company'] . "-laddition-SalesDocumentLines-$from-$to.xlsx\"");
    echo mockXlsx($feuilles);
    exit;
}

/** Petit .xlsx (cellules texte en ligne, nombres) pour le faux export. */
function mockXlsx(array $feuilles): string {
    $tmp = tempnam(sys_get_temp_dir(), 'mockx');
    $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
    $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/></Types>');
    $sheets = ''; $rels = ''; $i = 0;
    foreach ($feuilles as $nom => $rows) {
        $i++;
        $sheets .= '<sheet name="' . htmlspecialchars($nom) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $xml = '<?xml version="1.0" encoding="UTF-8"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($rows as $n => $row) {
            $xml .= '<row r="' . ($n + 1) . '">';
            foreach (array_values($row) as $c => $val) {
                $ref = ($c >= 26 ? chr(64 + intdiv($c, 26)) : '') . chr(65 + $c % 26) . ($n + 1);
                $xml .= (is_int($val) || is_float($val))
                    ? '<c r="' . $ref . '" t="n"><v>' . $val . '</v></c>'
                    : '<c r="' . $ref . '" t="inlineStr"><is><t>' . htmlspecialchars((string) $val) . '</t></is></c>';
            }
            $xml .= '</row>';
        }
        $z->addFromString("xl/worksheets/sheet$i.xml", $xml . '</sheetData></worksheet>');
    }
    $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $sheets . '</sheets></workbook>');
    $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
    $z->close();
    $out = file_get_contents($tmp); @unlink($tmp);
    return $out;
}

out(404, ['success' => false, 'message' => "Route inconnue : $method $path"]);
