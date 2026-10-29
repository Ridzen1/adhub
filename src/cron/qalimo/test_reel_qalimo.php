<?php
/**
 * Test sur la VRAIE API Qalimo, sans base de données et en lecture seule (GET uniquement).
 *   php test_reel_qalimo.php
 * N'affiche ni le jeton ni le nom des locataires.
 */
declare(strict_types=1);

$config = require (getenv('QALIMO_CONFIG') ?: __DIR__ . '/../../config/qalimo.local.php');
$token = trim((string) ($config['token'] ?? ''));
if (!str_starts_with($token, 'qmcp_') || $token === 'qmcp_A_REMPLACER') {
    fwrite(STDERR, "Jeton absent ou invalide dans config/qalimo.local.php (il doit commencer par qmcp_)\n");
    exit(1);
}

function qalimo(string $path, array $query = []): array
{
    global $token;
    $url = (getenv('QALIMO_BASE') ?: 'https://api.qalimo.fr/api/v1') . $path;
    if ($query) $url .= '?' . preg_replace('/%5B\d+%5D/', '', http_build_query($query));
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    usleep(200000); // reste poli avec l'API (limite de débit non documentée)
    if ($body === false) throw new RuntimeException("Réseau : $err");
    $data = json_decode((string) $body, true);
    if ($code !== 200) throw new RuntimeException("HTTP $code sur $path : " . ($data['message'] ?? substr((string) $body, 0, 200)));
    if (!is_array($data)) throw new RuntimeException("Réponse illisible sur $path");
    return $data;
}

function step(string $label, callable $fn)
{
    echo str_pad($label, 40, '.') . ' ';
    try { $r = $fn(); echo "OK\n"; return $r; }
    catch (Throwable $e) { echo "ÉCHEC\n   -> " . $e->getMessage() . "\n"; throw $e; }
}

/** Champs renseignés sur un ensemble de fiches : "nom (n/total)". */
function coverage(array $rows): string
{
    $n = count($rows); $filled = [];
    foreach ($rows as $r) foreach ($r as $k => $v) if ($v !== null && $v !== '' && $v !== []) $filled[$k] = ($filled[$k] ?? 0) + 1;
    ksort($filled);
    return implode(', ', array_map(fn($k, $c) => "$k ($c/$n)", array_keys($filled), $filled));
}

echo "=== Test Qalimo – lecture seule ===\n";
try {
    $profiles = step('1. Profils bailleurs (SCI)', fn() => qalimo('/landlord-profiles'));
    $props    = step('2. Biens actifs', fn() => qalimo('/properties'));
    $archived = step('3. Biens archivés', fn() => qalimo('/properties', ['status' => ['archived']]));
    $tenants  = step('4. Baux actifs', fn() => qalimo('/tenants'));
    $details  = step('5. Fiche de chaque bien', function () use ($props) {
        $d = [];
        foreach ($props as $p) $d[$p['id']] = qalimo('/properties/' . $p['id']);
        return $d;
    });
} catch (Throwable $e) {
    exit(1);
}

$profName = [];
foreach ($profiles as $p) $profName[$p['id']] = $p['name'] . ($p['is_company'] ? '' : ' (particulier)');
echo "\n--- Profils bailleurs : " . count($profiles) . " ---\n";
foreach ($profName as $id => $n) echo "   - [$id] $n\n";

$byType = [];
foreach ($props as $p) $byType[$p['type'] ?? '?'] = ($byType[$p['type'] ?? '?'] ?? 0) + 1;
echo "\n--- Biens : " . count($props) . ' actifs, ' . count($archived) . " archivés ---\n";
echo 'Par type : ' . json_encode($byType, JSON_UNESCAPED_UNICODE) . "\n";

$leases = [];
foreach ($tenants as $t) $leases[$t['property_id']][] = $t;

// Logements loués / total, par SCI
$stats = [];
echo "\n--- Détail des biens (type, colocation, baux actifs) ---\n";
foreach ($props as $p) {
    $d = $details[$p['id']];
    $owner = $profName[$d['owner_profile_id'] ?? 0] ?? 'Sans profil';
    $nb = count($leases[$p['id']] ?? []);
    $coloc = !empty($d['is_shared_housing']) ? ' coloc ' . ($d['shared_housing_capacity'] ?? '?') . ' pl.' : '';
    $notice = count(array_filter($leases[$p['id']] ?? [], fn($t) => !empty($t['notice_given'])));
    printf("   - [%d] %s | %s %s%s | %s | baux actifs : %d%s\n", $p['id'], $p['name'], $p['type'], $d['housing_type'] ?? '', $coloc, $owner, $nb, $notice ? " (dont $notice avec préavis)" : '');
    if (($p['type'] ?? '') !== 'habitation') continue;
    $stats[$owner]['total'] = ($stats[$owner]['total'] ?? 0) + 1;
    $stats[$owner]['loues'] = ($stats[$owner]['loues'] ?? 0) + ($nb > 0 ? 1 : 0);
}

echo "\n--- Logements loués / total (habitation) ---\n";
$T = 0; $L = 0;
foreach ($stats as $owner => $s) { printf("   %-35s %d / %d\n", $owner, $s['loues'], $s['total']); $T += $s['total']; $L += $s['loues']; }
printf("   %-35s %d / %d\n", 'TOTAL', $L, $T);

echo "\n--- Champs renseignés ---\n";
echo 'Biens : ' . coverage(array_values($details)) . "\n";
echo 'Baux  : ' . coverage($tenants) . "\n";

// Écritures d'un bail, pour voir loyers appelés / payés
if ($tenants) {
    $t = $tenants[0];
    try {
        $bal = qalimo('/tenants/' . $t['id'] . '/balances');
        echo "\n--- Écritures du bail {$t['id']} (bien {$t['property_id']}) : " . count($bal) . " ---\n";
        $types = [];
        foreach ($bal as $b) $types[$b['type'] ?? '?'] = ($types[$b['type'] ?? '?'] ?? 0) + 1;
        echo 'Par type : ' . json_encode($types, JSON_UNESCAPED_UNICODE) . "\n";
        foreach (array_slice($bal, 0, 6) as $b) printf("   %s  %-20s %10.2f €  %s\n", $b['date'], $b['type'], $b['amount'], $b['domain'] ?? '');
    } catch (Throwable $e) {
        echo "\nÉcritures : ÉCHEC -> " . $e->getMessage() . "\n";
    }
}
echo "\nFin du test (aucune donnée modifiée).\n";
