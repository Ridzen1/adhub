<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Suivi financier mensuel par projet. Le résultat net (revenus - charges)
 * est LA donnée qui pilote le "coup d'œil rentable / pas rentable" partout
 * dans l'outil — calculé à la volée, jamais stocké, pour ne jamais désynchroniser.
 */

function upsert_financial_entry(int $projectId, string $periodDate, float $revenus, float $charges, ?string $notes = null): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT id FROM financial_entries WHERE project_id = :pid AND period_date = :period");
    $stmt->execute(['pid' => $projectId, 'period' => $periodDate]);
    $existing = $stmt->fetch();

    if ($existing) {
        $upd = $pdo->prepare(
            "UPDATE financial_entries SET revenus = :revenus, charges = :charges, notes = :notes WHERE id = :id"
        );
        $upd->execute(['revenus' => $revenus, 'charges' => $charges, 'notes' => $notes, 'id' => $existing['id']]);
    } else {
        $ins = $pdo->prepare(
            "INSERT INTO financial_entries (project_id, period_date, revenus, charges, notes)
             VALUES (:pid, :period, :revenus, :charges, :notes)"
        );
        $ins->execute([
            'pid' => $projectId, 'period' => $periodDate,
            'revenus' => $revenus, 'charges' => $charges, 'notes' => $notes,
        ]);
    }
}

function get_financial_entries(int $projectId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM financial_entries WHERE project_id = :pid ORDER BY period_date ASC");
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function get_latest_entry(int $projectId): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT * FROM financial_entries WHERE project_id = :pid ORDER BY period_date DESC LIMIT 1"
    );
    $stmt->execute(['pid' => $projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_ytd_summary(int $projectId, int $year): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(revenus),0) AS revenus, COALESCE(SUM(charges),0) AS charges
         FROM financial_entries WHERE project_id = :pid AND YEAR(period_date) = :year"
    );
    $stmt->execute(['pid' => $projectId, 'year' => $year]);
    return $stmt->fetch();
}

/**
 * Une ligne par projet actif, avec sa dernière saisie (ou aucune si pas encore renseignée).
 * Alimente directement la vue globale d'Arnaud.
 */
function get_global_dashboard_data(): array
{
    $pdo = get_db();
    $sql = "SELECT p.id, p.name, pt.label AS type_label,
                   fe.period_date, fe.revenus, fe.charges
            FROM projects p
            JOIN project_types pt ON pt.id = p.project_type_id
            LEFT JOIN financial_entries fe ON fe.project_id = p.id
                AND fe.period_date = (
                    SELECT MAX(period_date) FROM financial_entries WHERE project_id = p.id
                )
            WHERE p.status = 'active'
            ORDER BY p.name";
    return $pdo->query($sql)->fetchAll();
}
