<?php
/**
 * Redirection journalisée vers OneDrive.
 *
 * Intérêt : l'URL OneDrive n'apparaît jamais dans le HTML de la page. Elle n'est
 * révélée qu'après vérification des droits, ce qui évite qu'un utilisateur
 * inspecte le code source pour récupérer des liens auxquels il n'a pas accès.
 */
require_once __DIR__ . '/../inc/access.php';
require_once __DIR__ . '/../inc/documents.php';
require_login();

// Dossier OneDrive de la société entière (champ « Lien vers les documents » de la fiche)
if (isset($_GET['project_id'])) {
    require_once __DIR__ . '/../inc/projects.php';
    $projectId = (int) $_GET['project_id'];
    require_project_access($projectId, 'documents');
    $project = get_project($projectId);
    if (!$project || empty($project['documents_url'])) {
        http_response_code(404);
        echo "Aucun dossier OneDrive n'est associé à cette société.";
        exit;
    }
    header('Location: ' . $project['documents_url']);
    exit;
}

$categoryId = (int) ($_GET['category_id'] ?? 0);
$category   = get_category($categoryId);

if (!$category) {
    http_response_code(404);
    echo "Document introuvable.";
    exit;
}

// Le droit se vérifie sur le projet auquel appartient la catégorie.
require_project_access((int) $category['project_id'], 'documents');

if (empty($category['onedrive_url'])) {
    http_response_code(404);
    echo "Aucun lien n'est associé à cette catégorie.";
    exit;
}

$user = current_user();
log_category_access($categoryId, $user['id']);

header('Location: ' . $category['onedrive_url']);
exit;
