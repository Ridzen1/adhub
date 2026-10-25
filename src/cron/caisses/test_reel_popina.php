<?php
/**
 * Test sur le VRAI back-office Popina (Jalia), sans base de données.
 *   php test_reel_popina.php               -> la veille
 *   php test_reel_popina.php 2026-10-23    -> une date précise
 * N'affiche ni mot de passe ni cookie. Se déconnecte à la fin.
 */
declare(strict_types=1);
require __DIR__ . '/popina_sync.php'; // fonctions + config, sans lancer la synchro

$day = $argv[1] ?? date('Y-m-d', strtotime('-1 day'));
$dt = DateTime::createFromFormat('!Y-m-d', $day);
if (!$dt || $dt->format('Y-m-d') !== $day) { fwrite(STDERR, "Date invalide : $day (format AAAA-MM-JJ)\n"); exit(1); }
$acc = $config['popina_accounts'][0] ?? null;
if (!$acc) { fwrite(STDERR, "Aucun compte dans 'popina_accounts' (config/caisses.local.php)\n"); exit(1); }
$dFr = $dt->format('d.m.Y');

function step(string $label, callable $fn) {
    echo str_pad($label, 40, '.') . ' ';
    try { $r = $fn(); echo "OK\n"; return $r; }
    catch (Throwable $e) { echo "ÉCHEC\n   -> " . $e->getMessage() . "\n"; throw $e; }
}

echo "=== Test Popina – {$acc['label']} – $day ===\n";
echo 'Extension zip (lecture Excel) : ' . (class_exists('ZipArchive') ? 'OK' : 'MANQUANTE') . "\n";
$jar = tempnam(sys_get_temp_dir(), 'jal');
$logged = false;
try {
    step('1. Connexion', function () use ($acc, $jar) { jaliaLogin($acc['email'], $acc['password'], $jar); });
    $logged = true;
    $etabs = step('2. Établissements', fn() => jaliaEstablishments($jar));
    echo '   - ' . implode(', ', array_keys($etabs)) . "\n";
    $etab = (int) array_key_first($etabs);
    $cats = step('3. Catégories du jour', fn() => jaliaCategories($etab, $dFr, $jar));
    echo '   - ' . ($cats ? implode(', ', $cats) : 'aucune (pas de service ce jour-là ?)') . "\n";
    $accRows = step('4. Export Comptabilité', fn() => jaliaExport($etab, $dFr, 'export_accounting', $cats, $jar));
    $items   = $cats ? step('5. Export Produits', fn() => jaliaExport($etab, $dFr, 'export_items', $cats, $jar)) : [];

    $c = ITEM_COLS;
    $types = [];
    $net = 0.0; $brut = 0.0; $off = 0.0;
    foreach (array_slice($items, 1) as $r) {
        if (($r[0] ?? '') === 'Total' || ($r[2] ?? '') === '' || $r[2] === null) continue;
        $t = itemType((string) ($r[$c['cat']] ?? ''));
        $types[$t] = ($types[$t] ?? 0) + 1;
        if ($t === 'article') { $brut += f($r[$c['brut']]) ?? 0; $off += f($r[$c['offert']] ?? null) ?? 0; $net += f($r[$c['net']] ?? null) ?? 0; }
    }
    echo "\n--- Export Produits ---\n";
    echo 'Colonnes            : ' . (count($items) ? max(array_map('count', $items)) : 0) . " (23 attendues)\n";
    echo 'Lignes par type     : ' . json_encode($types) . "\n";
    printf("Articles            : brut %.2f €, offert %.2f €, net %.2f €\n", $brut, $off, $net);

    echo "\n--- Z de caisse (export Comptabilité) ---\n";
    $groups = []; $cur = '';
    foreach ($accRows[0] ?? [] as $i => $g) { if ($g !== null && $g !== '') $cur = trim((string) $g); $groups[$i] = $cur; }
    $subs = array_map(fn($s) => trim((string) $s), $accRows[1] ?? []);
    $zNet = 0.0;
    foreach (array_slice($accRows, 2) as $r) {
        if (($r[0] ?? '') === '' || $r[0] === null || $r[0] === 'Total') continue;
        $get = function ($g, $s) use ($r, $groups, $subs) { foreach ($groups as $i => $x) if ($x === $g && $subs[$i] === $s) return $r[$i] ?? null; return null; };
        printf("Caisse %s → %s : brut %.2f €, net %.2f €, %s couverts\n", $r[0], $r[2], $get('Total Brut', 'TTC'), $get('Total Net', 'TTC'), $get('Couverts', 'Nombre'));
        $zNet += (float) $get('Total Net', 'TTC');
    }
    $ecart = round($net - $zNet, 2);
    echo "\n=> Contrôle articles / Z : " . ($ecart == 0.0 ? 'IDENTIQUE ✔' : "ÉCART de $ecart €") . "\n";
} catch (Throwable $e) {
    exit(1);
} finally {
    if ($logged) { jaliaLogout($jar); echo "(déconnecté)\n"; }
    @unlink($jar);
}
