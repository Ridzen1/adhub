<?php
/**
 * Test sur le VRAI site L'Addition, sans base de données.
 *
 *   php test_reel_laddition.php                -> premier compte de config/caisses.local.php, la veille
 *   php test_reel_laddition.php 2026-10-22     -> une date précise
 *   php test_reel_laddition.php 2026-10-22 1   -> 2e compte de la liste (index 1)
 *
 * Affiche un résumé à copier-coller (aucun jeton ni mot de passe affiché).
 * La réponse brute est enregistrée dans test_reel_reponse.json : à garder pour toi.
 */
declare(strict_types=1);
require __DIR__ . '/laddition_sync.php'; // fonctions + config, sans lancer la synchro

$day   = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));
$index = (int) ($argv[2] ?? 0);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day)) { fwrite(STDERR, "Date invalide : $day (format AAAA-MM-JJ)\n"); exit(1); }
$acc = $config['laddition_accounts'][$index] ?? null;
if (!$acc) { fwrite(STDERR, "Pas de compte à l'index $index dans config/caisses.local.php\n"); exit(1); }

function step(string $label, callable $fn) {
    echo str_pad($label, 38, '.') . ' ';
    try { $r = $fn(); echo "OK\n"; return $r; }
    catch (Throwable $e) { echo "ÉCHEC\n   -> " . $e->getMessage() . "\n"; throw $e; }
}

echo "=== Test L'Addition – {$acc['label']} – $day ===\n";
$jar = tempnam(sys_get_temp_dir(), 'ladd');
$refresh = null;
try {
    $refresh   = step('1. Connexion', fn() => ladditionLogin($acc['login'], $acc['password'], $jar));
    $token     = step('2. Jeton d\'accès', fn() => ladditionAccessToken($refresh));
    $companies = step('3. Liste des établissements', fn() => getCompanies($token));
    foreach ($companies as $c) echo "   - {$c['id_company']} : " . trim($c['company_name'] ?? '?') . "\n";
    if (!$companies) throw new RuntimeException('Aucun établissement');
    $company = $companies[0];

    $lines = step('4. Export « Lignes de ventes »', fn() => getOrderLines($company, $day, $day));
    file_put_contents(__DIR__ . '/test_reel_reponse.json', json_encode($lines, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "\n--- Contrôles ---\n";
    echo "Lignes             : " . count($lines) . "\n";
    if ($lines) {
        $heures = array_column($lines, 'HEURE VENTE');
        echo "Première / dernière: " . min($heures) . " / " . max($heures) . "\n";
        $ttc = array_sum(array_column($lines, 'TTC'));
        echo "Total TTC          : " . number_format($ttc, 2, ',', ' ') . " €\n";
        $cats = array_count_values(array_map(fn($l) => $l['CATEGORIE PRODUIT'] ?? '(sans)', $lines));
        arsort($cats);
        echo "Catégories         : " . implode(', ', array_map(fn($k, $n) => "$k ($n)", array_keys($cats), $cats)) . "\n";
        $ids = array_column($lines, 'ID_LIGNE_VENTE');
        echo "ID de ligne uniques: " . (count(array_unique($ids)) === count($ids) ? 'oui' : 'NON (' . (count($ids) - count(array_unique($ids))) . ' doublons)') . "\n";
        echo "\n--- 3 premières lignes ---\n";
        foreach (array_slice($lines, 0, 3) as $l) echo json_encode($l, JSON_UNESCAPED_UNICODE) . "\n";
    }
    echo "\n=> Compare le total TTC avec le CA du $day dans le back-office L'Addition.\n";
} catch (Throwable $e) {
    exit(1);
} finally {
    if ($refresh) { ladditionLogout($refresh, $jar); echo "(déconnecté)\n"; }
    @unlink($jar);
}
