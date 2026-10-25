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
    $id = (int) $companies[0]['id_company'];

    // Aperçu (comme le site) vs complet
    $preview = step('4. Lignes de vente (limit=true)', function () use ($token, $id, $day) {
        $j = reportingCall($token, 'POST', 'dataExport/getOrderLines/', [
            'opening_date' => "$day 00:00:00", 'closing_date' => "$day 23:59:59",
            'idCompanies' => json_encode([$id]), 'orderTypeAway' => json_encode([1, 2, 3]), 'limit' => true,
        ]);
        return $j;
    });
    $full = step('5. Lignes de vente (limit=false)', function () use ($token, $id, $day) {
        return reportingCall($token, 'POST', 'dataExport/getOrderLines/', [
            'opening_date' => "$day 00:00:00", 'closing_date' => "$day 23:59:59",
            'idCompanies' => json_encode([$id]), 'orderTypeAway' => json_encode([1, 2, 3]), 'limit' => false,
        ]);
    });
    file_put_contents(__DIR__ . '/test_reel_reponse.json', json_encode($full, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    echo "\n--- Format de la réponse ---\n";
    echo "Clés racine        : " . implode(', ', array_keys($full)) . "\n";
    $lines = $full['data'] ?? [];
    echo "Type de 'data'     : " . gettype($lines) . "\n";
    echo "Lignes limit=true  : " . (is_array($preview['data'] ?? null) ? count($preview['data']) : 'n/a') . "\n";
    echo "Lignes limit=false : " . (is_array($lines) ? count($lines) : 'n/a') . "\n";

    if (is_array($lines) && $lines) {
        $first = reset($lines);
        echo "Colonnes reçues    : " . implode(' | ', array_keys($first)) . "\n";
        $attendues = ['ID_LIGNE_VENTE', 'ETABLISSEMENT', 'ID_PRODUIT', 'NOM PRODUIT', 'TYPE PRODUIT', 'CATEGORIE PRODUIT', 'TAUX DE TVA', 'NOM TVA', 'TVA', 'HT', 'TTC', 'ID_PARENT', 'ID_SALLES', 'NOM SALLES', 'HEURE VENTE'];
        $manquantes = array_diff($attendues, array_keys($first));
        echo "Colonnes manquantes: " . ($manquantes ? implode(', ', $manquantes) : 'aucune') . "\n";

        echo "\n--- Contrôles ---\n";
        $heures = array_column($lines, 'HEURE VENTE');
        $nonLues = array_filter($heures, fn($h) => toDatetime(is_scalar($h) ? (string) $h : null) === null);
        echo "Exemple heure brute: " . json_encode($heures[0] ?? null) . " -> " . (toDatetime((string) ($heures[0] ?? '')) ?? 'NON RECONNUE') . "\n";
        echo "Heures non lues    : " . count($nonLues) . "\n";
        echo "Exemple montant TTC: " . json_encode($first['TTC'] ?? null) . "\n";
        $ttc = array_sum(array_map(fn($l) => num($l['TTC'] ?? null) ?? 0, $lines));
        $ttcSansOptions = array_sum(array_map(fn($l) => in_array((string) ($l['ID_PARENT'] ?? ''), ['', '0'], true) ? (num($l['TTC'] ?? null) ?? 0) : 0, $lines));
        echo "Total TTC          : " . number_format($ttc, 2, ',', ' ') . " €\n";
        echo "Total TTC hors lignes enfants : " . number_format($ttcSansOptions, 2, ',', ' ') . " €\n";
        echo "Lignes avec parent : " . count(array_filter($lines, fn($l) => !in_array((string) ($l['ID_PARENT'] ?? ''), ['', '0'], true))) . "\n";
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
