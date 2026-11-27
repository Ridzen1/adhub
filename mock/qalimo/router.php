<?php
/**
 * Fausse API Qalimo pour tester qalimo_sync.php sans toucher au vrai compte.
 * Formats repris du spec OpenAPI et du test réel du 27/10/2026 (noms de profils réels,
 * quelques biens par SCI, écritures sur 3 mois avec un impayé et un préavis).
 *
 * Lancement : php -S 127.0.0.1:8097 mock/qalimo/router.php
 * Config    : 'token' => 'qmcp_test', 'api' => 'http://127.0.0.1:8097/api/v1'
 */

header('Content-Type: application/json; charset=utf-8');
function out(int $code, $data) { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

// --- Banques (routes de l'application web, hors /api/v1) -------------------------------
// Connexion : e-mail test@adhub.local / mot de passe « test » ; avec MOCK_TOTP=1 dans
// l'environnement, un code à 6 chiffres est exigé (clé JBSWY3DPEHPK3PXP).
$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($chemin === '/token') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') out(405, ['error' => 'POST attendu']);
    $c = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($c['email'] ?? '') !== 'test@adhub.local' || ($c['password'] ?? '') !== 'test') out(401, ['error' => 'Adresse e-mail ou mot de passe incorrect']);
    if (getenv('MOCK_TOTP') && !preg_match('/^\d{6}$/', (string) ($c['totp_code'] ?? ''))) out(401, ['requires_totp' => true]);
    out(200, ['access_token' => 'jwt_test', 'id_compte' => 1, 'role' => []]);
}
if (in_array($chemin, ['/banks', '/transactions'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') out(405, ['error' => 'Lecture seule dans le mock']);
    if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer jwt_test') out(401, ['error' => 'Non connecté']);
    // [id, unique_id, profil, banque, nom, solde, statut]
    $comptes = [
        [501, 'lx-hd-1', 9699, 'Crédit Agricole', 'Compte courant SCI HALLES HOTEL DIEU', '12450.32', 'active'],
        [502, 'lx-hd-2', 9699, 'Crédit Agricole', 'Livret', 3000, 'active'],
        [503, 'lx-cous', 9753, 'CIC', 'SCI COUSIMMO', -820.10, 'inactive'],
        [504, 'lx-ad', 9759, 'Qonto', 'AD HOLDING', 45210.00, 'active'],
        [505, 'lx-arn', 9755, 'BNP', 'Compte perso', 999, 'active'],          // profil ignoré
        [506, 'lx-bell', null, 'Banque Populaire', 'SAS LE BELLEVUE', 18200.55, 'active'],
        [507, 'lx-x', null, 'Shine', 'Compte mystère', 50, 'unconsented'],
    ];
    if ($chemin === '/banks') {
        out(200, array_map(fn($c) => ['id' => $c[0], 'unique_id' => $c[1], 'id_profile_nom' => $c[2], 'nom' => $c[4], 'montant' => $c[5],
            'status' => $c[6], 'provider' => 'linxo', 'compte_gestion' => $c[0] === 501, 'date_maj' => '2026-11-27T05:12:00Z',
            'iban' => 'FR76 1234 5678 9012 3456 7890 ' . $c[0], 'bank_metadata' => ['name' => $c[3], 'logo' => '']], $comptes));
    }
    // 1 200 opérations réparties sur 14 mois, la plus récente en premier
    $ops = [];
    $t = strtotime('today');
    for ($i = 0; $i < 1200; $i++) {
        $c = $comptes[$i % 7];
        $entree = $i % 3 === 0;
        $ops[] = ['id' => 900000 + $i, 'unique_account_id' => $c[1], 'date_transac' => date('Y-m-d', $t - intdiv($i, 3) * 86400) . 'T00:00:00',
                  'description' => $entree ? 'VIR LOYER LOCATAIRE ' . ($i % 5) : ['PRLV EDF', 'CB LEROY MERLIN', 'ECHEANCE PRET', 'FRAIS BANCAIRES'][$i % 4],
                  'montant' => $entree ? 650 + $i % 7 * 10 : -(40 + $i % 11 * 15.5), 'balances' => [], 'id_transaction' => null];
    }
    if (!empty($_GET['date_min'])) $ops = array_values(array_filter($ops, fn($o) => substr($o['date_transac'], 0, 10) >= $_GET['date_min']));
    $pp = (int) ($_GET['per_page'] ?? 100);
    out(200, array_slice($ops, ((int) ($_GET['page'] ?? 1) - 1) * $pp, $pp));
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') out(405, ['message' => 'Lecture seule dans le mock']);
if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer qmcp_test') out(401, ['error' => 'Token invalide, expiré ou révoqué']);

$profils = [
    [9759, 'AD HOLDING', true], [9757, 'AGD INVEST', true], [9756, 'ALD IMMO', true],
    [9755, 'Arnaud DUVIVIER', false], [9754, 'CASA NOSTRA', true], [11082, 'DES PONTS', true],
    [9751, 'SCI 5 RUE PAUL DUBOIS', true], [9753, 'SCI COUSIMMO', true], [9752, 'SCI FACTORY', false],
    [9699, 'SCI HALLES HOTEL DIEU', true], [9758, 'SCI VALENTINO', true],
];
// [id, nom, type, logement, profil, loyer HC, charges]
$biens = [
    [12004, 'AGD INVEST studio meublé rdc', 'habitation', 'collectif', 9757, 480, 30],
    [12005, 'AGD INVEST T2 1er étage', 'habitation', 'collectif', 9757, 560, 40],
    [16602, 'Appartement 1', 'habitation', 'collectif', 11082, 520, 30],
    [16604, 'Appartement 3', 'habitation', 'collectif', 11082, 540, 30],
    [16609, 'Box 1', 'garage', null, 11082, 60, 0],
    [16607, 'Magasin 1 et bureaux', 'commercial', null, 11082, 1200, 100],
    [12020, 'Arnaud DUVIVIER Atelier 2', 'habitation', 'collectif', 9755, 400, 20],
    [12032, 'Factory maison', 'habitation', 'individuel', 9752, 900, 0],
    [12037, 'HD 6 Paul Dubois Meublé Duplex', 'habitation', 'collectif', 9699, 750, 50],
    [12035, 'HD 13 Hôtel Dieu local', 'commerce', 'collectif', 9699, 1100, 80],
    [12047, 'PD 1 er étage F2', 'habitation', 'collectif', 9751, 520, 35],
    [12027, 'VALENTINO', 'commercial', 'collectif', 9758, 2500, 0],
    [16313, 'STUDIO BIS 11 PLAT D ETAIN 2EME ETAGE', 'habitation', 'collectif', 9756, 630, 20],
];
// [id, bien, nom, début, fin, préavis, mois impayés]
$baux = [
    [26001, 12004, 'M. Martin', '2023-09-01', '2026-12-31', false, 0],
    [26002, 12005, 'Mme Bernard', '2024-02-01', '2027-01-31', true, 0],
    [26003, 16602, 'M. Petit', '2025-06-01', '2028-05-31', false, 1],
    [26004, 16609, 'M. Petit', '2025-06-01', '2028-05-31', false, 0],
    [26005, 12020, 'Mme Robert', '2022-01-01', '2027-12-31', false, 0],
    [26006, 12037, 'M. Durand', '2024-09-01', '2027-08-31', false, 2],
    [26007, 12035, 'SARL Fleurs', '2021-04-01', '2030-03-31', false, 0],
    [26008, 12047, 'Mme Leroy', '2023-03-01', '2026-11-30', false, 0],
    [26009, 12027, 'SAS LE VALENTINO', '2020-01-01', '2029-12-31', false, 0],
    [26256, 16313, 'Mme Moreau', '2026-09-15', '2029-09-14', false, 0],
];
$bienParId = array_column($biens, null, 0);
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$q = $_GET;

function bien_json(array $b, bool $detail): array {
    $j = ['id' => $b[0], 'name' => $b[1], 'type' => $b[2], 'city' => 'Troyes', 'postal_code' => '10000',
          'rent_excluding_charges' => $b[5], 'charges' => $b[6], 'status' => 'active'];
    return $detail ? $j + ['street' => '1 rue test', 'housing_type' => $b[3], 'surface_area' => 45, 'main_room_count' => 2,
                           'is_shared_housing' => false, 'owner_profile_id' => $b[4]] : $j;
}

function ecritures(array $bail, array $bien): array {
    $out = []; $id = $bail[0] * 100;
    foreach (['2026-08', '2026-09', '2026-10'] as $i => $m) {
        if ($m . '-01' < substr($bail[3], 0, 7) . '-01') continue;
        $out[] = ['id' => ++$id, 'date' => "$m-05", 'type' => 'appel_loyer', 'amount' => -$bien[5], 'domain' => 'tenant', 'parent_id' => null, 'comment' => null, 'correlation_id' => null];
        if ($bien[6]) $out[] = ['id' => ++$id, 'date' => "$m-05", 'type' => 'appel_charge', 'amount' => -$bien[6], 'domain' => 'tenant', 'parent_id' => null, 'comment' => null, 'correlation_id' => null];
        if (2 - $i >= $bail[6]) $out[] = ['id' => ++$id, 'date' => "$m-09", 'type' => 'paiement', 'amount' => $bien[5] + $bien[6], 'domain' => 'tenant', 'parent_id' => null, 'comment' => null, 'correlation_id' => null];
    }
    $out[] = ['id' => ++$id, 'date' => $bail[3], 'type' => 'appel_dg', 'amount' => -$bien[5], 'domain' => 'tenant', 'parent_id' => null, 'comment' => null, 'correlation_id' => null];
    $out[] = ['id' => ++$id, 'date' => $bail[3], 'type' => 'paiement_dg', 'amount' => $bien[5], 'domain' => 'tenant', 'parent_id' => null, 'comment' => null, 'correlation_id' => null];
    return array_reverse($out);
}

if ($path === '/api/v1/landlord-profiles') out(200, array_map(fn($p) => ['id' => $p[0], 'name' => $p[1], 'is_company' => $p[2]], $profils));
if ($path === '/api/v1/properties') out(200, ($q['status'] ?? 'active') === 'archived' ? [] : array_map(fn($b) => bien_json($b, false), $biens));
if (preg_match('#^/api/v1/properties/(\d+)$#', $path, $m)) isset($bienParId[$m[1]]) ? out(200, bien_json($bienParId[$m[1]], true)) : out(404, ['message' => 'Introuvable']);
$bailJson = fn($l, $detail) => ['id' => $l[0], 'name' => $l[2], 'occupants' => [], 'property_id' => $l[1], 'start_date' => $l[3], 'end_date' => $l[4],
    'rent_excluding_charges' => $bienParId[$l[1]][5], 'status' => 'active'] + ($detail ? ['charges' => $bienParId[$l[1]][6], 'notice_given' => $l[5]] : []);
if ($path === '/api/v1/tenants') out(200, array_map(fn($l) => $bailJson($l, false), $baux));
foreach ($baux as $l) {
    if ($path === "/api/v1/tenants/{$l[0]}") out(200, $bailJson($l, true));
    if ($path === "/api/v1/tenants/{$l[0]}/balances") out(200, ecritures($l, $bienParId[$l[1]]));
}
out(404, ['message' => 'Route inconnue']);
