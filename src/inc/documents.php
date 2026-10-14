<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Documents = catégories (dossiers OneDrive) rattachées à un projet.
 * L'application ne stocke aucun fichier : uniquement des liens.
 */

function get_categories(int $projectId): array
{
    $stmt = get_db()->prepare(
        "SELECT * FROM document_categories WHERE project_id = :pid ORDER BY sort_order, label"
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function get_category(int $id): ?array
{
    $stmt = get_db()->prepare("SELECT * FROM document_categories WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_category(int $projectId, string $label, ?string $url, bool $isSensitive = false): int
{
    $pdo = get_db();

    $next = $pdo->prepare(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 AS n FROM document_categories WHERE project_id = :pid"
    );
    $next->execute(['pid' => $projectId]);
    $order = (int) $next->fetch()['n'];

    $stmt = $pdo->prepare(
        "INSERT INTO document_categories (project_id, label, onedrive_url, is_sensitive, sort_order)
         VALUES (:pid, :label, :url, :sensitive, :ord)"
    );
    $stmt->execute([
        'pid'       => $projectId,
        'label'     => $label,
        'url'       => $url,
        'sensitive' => $isSensitive ? 1 : 0,
        'ord'       => $order,
    ]);
    return (int) $pdo->lastInsertId();
}

function update_category(int $id, int $projectId, string $label, ?string $url, bool $isSensitive): void
{
    // project_id dans le WHERE : empêche de modifier la catégorie d'un autre projet
    // en manipulant l'id dans le formulaire.
    $stmt = get_db()->prepare(
        "UPDATE document_categories
         SET label = :label, onedrive_url = :url, is_sensitive = :sensitive
         WHERE id = :id AND project_id = :pid"
    );
    $stmt->execute([
        'label'     => $label,
        'url'       => $url,
        'sensitive' => $isSensitive ? 1 : 0,
        'id'        => $id,
        'pid'       => $projectId,
    ]);
}

function delete_category(int $id, int $projectId): void
{
    $pdo = get_db();
    // On purge d'abord les logs liés, sinon la contrainte de clé étrangère bloque.
    $pdo->prepare("DELETE FROM document_access_log WHERE category_id = :id")->execute(['id' => $id]);

    $stmt = $pdo->prepare("DELETE FROM document_categories WHERE id = :id AND project_id = :pid");
    $stmt->execute(['id' => $id, 'pid' => $projectId]);
}

function log_category_access(int $categoryId, int $userId): void
{
    $stmt = get_db()->prepare(
        "INSERT INTO document_access_log (category_id, user_id, ip_address)
         VALUES (:cid, :uid, :ip)"
    );
    $stmt->execute([
        'cid' => $categoryId,
        'uid' => $userId,
        'ip'  => $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

function get_access_log(int $limit = 100): array
{
    $limit = max(1, min(500, $limit)); // borne dure, la valeur est injectée dans le SQL
    $sql = "SELECT dal.opened_at, dal.ip_address,
                   u.name AS user_name,
                   dc.label AS category_label,
                   p.name AS project_name
            FROM document_access_log dal
            JOIN users u ON u.id = dal.user_id
            JOIN document_categories dc ON dc.id = dal.category_id
            JOIN projects p ON p.id = dc.project_id
            ORDER BY dal.opened_at DESC
            LIMIT {$limit}";
    return get_db()->query($sql)->fetchAll();
}
