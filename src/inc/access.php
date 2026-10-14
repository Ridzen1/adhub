<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Contrôle d'accès centralisé.
 *
 * RÈGLE : aucune page ne teste un rôle directement. Tout passe par les fonctions
 * de ce fichier. Si une vérification manque ici, elle manque partout — c'est
 * volontaire, ça évite qu'un oubli sur une page ouvre un accès non prévu.
 */

function start_session_once(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        // Le cookie de session ne doit pas être lisible en JavaScript, ni transiter
        // en clair sur le réseau. Le drapeau "secure" n'est activé qu'en HTTPS,
        // sinon la connexion deviendrait impossible en développement local.
        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        ]);
        session_start();
    }
}

function attempt_login(string $email, string $password): bool
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT u.*, r.code AS role_code
         FROM users u
         JOIN roles r ON r.id = u.role_id
         WHERE u.email = :email AND u.is_active = 1"
    );
    $stmt->execute(['email' => $email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    start_session_once();
    session_regenerate_id(true); // évite la fixation de session
    $_SESSION['user_id']   = (int) $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['role_code'] = $user['role_code'];

    return true;
}

function logout(): void
{
    start_session_once();
    $_SESSION = [];
    session_destroy();
}

function current_user(): ?array
{
    start_session_once();
    if (empty($_SESSION['user_id'])) {
        return null;
    }
    return [
        'id'        => (int) $_SESSION['user_id'],
        'name'      => $_SESSION['user_name'] ?? '',
        'role_code' => $_SESSION['role_code'] ?? '',
    ];
}

function is_logged_in(): bool
{
    return current_user() !== null;
}

function is_direction(): bool
{
    $u = current_user();
    return $u !== null && $u['role_code'] === 'direction';
}

/**
 * À appeler en toute première ligne de chaque page protégée.
 */
function require_login(): void
{
    if (!is_logged_in()) {
        header('Location: /pages/login.php');
        exit;
    }
}

function require_direction(): void
{
    require_login();
    if (!is_direction()) {
        http_response_code(403);
        echo "Accès refusé.";
        exit;
    }
}

/**
 * Projets visibles par l'utilisateur courant.
 * Direction : tous les projets actifs.
 * Autres : uniquement ceux listés dans project_user_access.
 */
function get_visible_projects(): array
{
    $user = current_user();
    if ($user === null) {
        return [];
    }

    $pdo = get_db();

    if (is_direction()) {
        $sql = "SELECT p.*, pt.label AS type_label, pt.code AS type_code
                FROM projects p
                JOIN project_types pt ON pt.id = p.project_type_id
                WHERE p.status = 'active'
                ORDER BY pt.label, p.name";
        return $pdo->query($sql)->fetchAll();
    }

    $sql = "SELECT p.*, pt.label AS type_label, pt.code AS type_code,
                   pua.access_level, pua.scope
            FROM projects p
            JOIN project_types pt ON pt.id = p.project_type_id
            JOIN project_user_access pua ON pua.project_id = p.id
            WHERE pua.user_id = :uid AND p.status = 'active'
            ORDER BY pt.label, p.name";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['uid' => $user['id']]);
    return $stmt->fetchAll();
}

function get_access_row(int $projectId): ?array
{
    $user = current_user();
    if ($user === null) {
        return null;
    }

    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT * FROM project_user_access WHERE user_id = :uid AND project_id = :pid"
    );
    $stmt->execute(['uid' => $user['id'], 'pid' => $projectId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * $section : 'dashboard' | 'documents' | 'fiche'
 * Un accès de scope 'all' ouvre toutes les sections.
 */
function can_view_project(int $projectId, string $section = 'fiche'): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_direction()) {
        return true;
    }

    $access = get_access_row($projectId);
    if ($access === null) {
        return false;
    }
    if ($access['scope'] === 'all') {
        return true;
    }
    return $access['scope'] === $section;
}

function can_edit_project(int $projectId): bool
{
    if (!is_logged_in()) {
        return false;
    }
    if (is_direction()) {
        return true;
    }

    $access = get_access_row($projectId);
    return $access !== null && $access['access_level'] === 'write';
}

/**
 * À appeler après avoir chargé un projet, avant d'afficher quoi que ce soit.
 */
function require_project_access(int $projectId, string $section = 'fiche'): void
{
    require_login();
    if (!can_view_project($projectId, $section)) {
        http_response_code(403);
        echo "Vous n'avez pas accès à cette société.";
        exit;
    }
}

function require_project_edit(int $projectId): void
{
    require_login();
    if (!can_edit_project($projectId)) {
        http_response_code(403);
        echo "Vous n'avez pas les droits de modification sur cette société.";
        exit;
    }
}

/* ------------------------------------------------------------------ */
/* Administration des utilisateurs (réservée à la direction)           */
/* ------------------------------------------------------------------ */

function get_all_roles(): array
{
    return get_db()->query("SELECT * FROM roles ORDER BY label")->fetchAll();
}

function get_all_users(): array
{
    $sql = "SELECT u.*, r.label AS role_label, r.code AS role_code
            FROM users u
            JOIN roles r ON r.id = u.role_id
            ORDER BY u.name";
    return get_db()->query($sql)->fetchAll();
}

function get_user(int $id): ?array
{
    $stmt = get_db()->prepare(
        "SELECT u.*, r.label AS role_label, r.code AS role_code
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = :id"
    );
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_user(string $name, string $email, string $password, int $roleId): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO users (name, email, password_hash, role_id)
         VALUES (:name, :email, :hash, :role_id)"
    );
    $stmt->execute([
        'name'    => $name,
        'email'   => $email,
        'hash'    => password_hash($password, PASSWORD_DEFAULT),
        'role_id' => $roleId,
    ]);
    return (int) $pdo->lastInsertId();
}

function set_user_active(int $userId, bool $active): void
{
    $stmt = get_db()->prepare("UPDATE users SET is_active = :a WHERE id = :id");
    $stmt->execute(['a' => $active ? 1 : 0, 'id' => $userId]);
}

function update_password(int $userId, string $newPassword): void
{
    $stmt = get_db()->prepare("UPDATE users SET password_hash = :h WHERE id = :id");
    $stmt->execute(['h' => password_hash($newPassword, PASSWORD_DEFAULT), 'id' => $userId]);
}

function get_user_access(int $userId): array
{
    $sql = "SELECT pua.*, p.name AS project_name, pt.label AS type_label
            FROM project_user_access pua
            JOIN projects p ON p.id = pua.project_id
            JOIN project_types pt ON pt.id = p.project_type_id
            WHERE pua.user_id = :uid
            ORDER BY pt.label, p.name";
    $stmt = get_db()->prepare($sql);
    $stmt->execute(['uid' => $userId]);
    return $stmt->fetchAll();
}

function grant_access(int $userId, int $projectId, string $accessLevel = 'read', string $scope = 'all'): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO project_user_access (user_id, project_id, access_level, scope)
         VALUES (:uid, :pid, :lvl, :scope)
         ON DUPLICATE KEY UPDATE access_level = :lvl2, scope = :scope2"
    );
    $stmt->execute([
        'uid' => $userId, 'pid' => $projectId,
        'lvl' => $accessLevel, 'scope' => $scope,
        'lvl2' => $accessLevel, 'scope2' => $scope,
    ]);
}

function revoke_access(int $userId, int $projectId): void
{
    $stmt = get_db()->prepare(
        "DELETE FROM project_user_access WHERE user_id = :uid AND project_id = :pid"
    );
    $stmt->execute(['uid' => $userId, 'pid' => $projectId]);
}
