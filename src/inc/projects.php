<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Brique générique "projet" — fonctions de base.
 * Toutes les requêtes utilisent des requêtes préparées PDO.
 */

function get_all_project_types(): array
{
    $pdo = get_db();
    return $pdo->query("SELECT * FROM project_types ORDER BY label")->fetchAll();
}

function get_all_projects(): array
{
    $pdo = get_db();
    $sql = "SELECT p.*, pt.label AS type_label, pt.code AS type_code
            FROM projects p
            JOIN project_types pt ON pt.id = p.project_type_id
            ORDER BY p.name";
    return $pdo->query($sql)->fetchAll();
}

function get_project(int $id): ?array
{
    $pdo = get_db();
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function create_project(string $name, int $projectTypeId, ?string $siret = null): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO projects (name, project_type_id, siret_siren) VALUES (:name, :type_id, :siret)"
    );
    $stmt->execute([
        'name'    => $name,
        'type_id' => $projectTypeId,
        'siret'   => $siret,
    ]);
    return (int) $pdo->lastInsertId();
}

function archive_project(int $id): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare("UPDATE projects SET status = 'archived' WHERE id = :id");
    $stmt->execute(['id' => $id]);
}

/**
 * Retourne les liens (détention ou murs/exploitation) impliquant un projet donné,
 * avec le nom des deux projets liés pour affichage direct.
 */
function get_project_links(int $projectId): array
{
    $pdo = get_db();
    
    $sql = "SELECT pl.*, pa.name AS project_a_name, pb.name AS project_b_name
            FROM project_links pl
            JOIN projects pa ON pa.id = pl.project_id_a
            JOIN projects pb ON pb.id = pl.project_id_b
            WHERE pl.project_id_a = :id_a OR pl.project_id_b = :id_b";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        'id_a' => $projectId,
        'id_b' => $projectId
    ]);
    
    return $stmt->fetchAll();
}

/**
 * $details peut contenir : adresse, surface_m2, valeur_bien, montant_emprunt,
 * documents_url, description. Toute clé absente reste inchangée (NULL si jamais
 * renseignée). Champs volontairement optionnels : tous les types de projet
 * n'ont pas de "bien" (ex: holding, distribution).
 */
function update_project(int $id, string $name, int $projectTypeId, ?string $siret, array $details = []): void
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "UPDATE projects SET
            name = :name,
            project_type_id = :type_id,
            siret_siren = :siret,
            adresse = :adresse,
            surface_m2 = :surface_m2,
            valeur_bien = :valeur_bien,
            montant_emprunt = :montant_emprunt,
            documents_url = :documents_url,
            description = :description,
            solde_bancaire = :solde,
            solde_maj_le = :solde_maj,
            solde_reference = :solde_ref,
            solde_reference_le = :solde_ref_le,
            lots_total = :lots_total,
            lots_loues = :lots_loues,
            affaires_en_cours = :affaires
         WHERE id = :id"
    );
    $stmt->execute([
        'name'            => $name,
        'type_id'         => $projectTypeId,
        'siret'           => $siret,
        'adresse'         => $details['adresse'] ?? null,
        'surface_m2'      => $details['surface_m2'] ?? null,
        'valeur_bien'     => $details['valeur_bien'] ?? null,
        'montant_emprunt' => $details['montant_emprunt'] ?? null,
        'documents_url'   => $details['documents_url'] ?? null,
        'description'     => $details['description'] ?? null,
        'solde'           => $details['solde_bancaire'] ?? null,
        'solde_maj'       => $details['solde_maj_le'] ?? null,
        // Le solde saisi sert de point de départ au calcul de trésorerie (pennylane_maj_tresorerie)
        'solde_ref'       => $details['solde_bancaire'] ?? null,
        'solde_ref_le'    => $details['solde_maj_le'] ?? null,
        'lots_total'      => $details['lots_total'] ?? null,
        'lots_loues'      => $details['lots_loues'] ?? null,
        'affaires'        => $details['affaires_en_cours'] ?? null,
        'id'              => $id,
    ]);
}

/**
 * Données de la vue globale : une ligne par société active, avec le CA du mois
 * calculé à la volée pour les restaurants (somme des saisies quotidiennes).
 */
function get_global_overview(): array
{
    $sql = "SELECT p.*,
                   pt.code AS type_code,
                   pt.label AS type_label,
                   (SELECT COALESCE(SUM(de.ca_ttc), 0)
                      FROM daily_entries de
                     WHERE de.project_id = p.id
                       AND YEAR(de.entry_date) = YEAR(CURDATE())
                       AND MONTH(de.entry_date) = MONTH(CURDATE())) AS ca_mois
            FROM projects p
            JOIN project_types pt ON pt.id = p.project_type_id
            WHERE p.status = 'active'
            ORDER BY p.name";
    return get_db()->query($sql)->fetchAll();
}

/**
 * Regroupe les sociétés en trois familles pour la vue globale (SCI / Restaurants / MDB).
 * Les types qui ne rentrent dans aucune des trois (holding, distribution…) sont
 * rassemblés sous "autres" plutôt que masqués : mieux vaut une société visible
 * dans une catégorie fourre-tout qu'une société absente du tableau de bord.
 */
function group_projects_for_overview(array $projects): array
{
    $groupes = ['sci' => [], 'restaurants' => [], 'mdb' => [], 'autres' => []];

    foreach ($projects as $p) {
        switch ($p['type_code']) {
            case 'immobilier':
            case 'restauration_murs':
                $groupes['sci'][] = $p;
                break;
            case 'restauration_exploitation':
                $groupes['restaurants'][] = $p;
                break;
            case 'mdb':
                $groupes['mdb'][] = $p;
                break;
            default:
                $groupes['autres'][] = $p;
        }
    }
    return $groupes;
}

/**
 * Associés d'un projet — personnes ou entités qui détiennent une part sans
 * avoir forcément leur propre fiche "projet" (contrairement à project_links).
 */
function get_associates(int $projectId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT * FROM project_associates WHERE project_id = :pid ORDER BY percentage DESC, name"
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function add_associate(int $projectId, string $name, ?string $role, ?float $percentage, ?string $contactEmail): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO project_associates (project_id, name, role, percentage, contact_email)
         VALUES (:pid, :name, :role, :pct, :email)"
    );
    $stmt->execute([
        'pid'   => $projectId,
        'name'  => $name,
        'role'  => $role,
        'pct'   => $percentage,
        'email' => $contactEmail,
    ]);
    return (int) $pdo->lastInsertId();
}

function delete_associate(int $associateId, int $projectId): void
{
    $pdo = get_db();
    // On filtre aussi par project_id : évite qu'un id manipulé dans le formulaire
    // permette de supprimer l'associé d'un autre projet.
    $stmt = $pdo->prepare("DELETE FROM project_associates WHERE id = :id AND project_id = :pid");
    $stmt->execute(['id' => $associateId, 'pid' => $projectId]);
}

/**
 * Utile pour peupler un <select> de projet cible lors de la création d'un lien,
 * en excluant le projet courant (on ne relie pas un projet à lui-même).
 */
function get_projects_except(int $excludeId): array
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "SELECT id, name FROM projects WHERE id != :id AND status = 'active' ORDER BY name"
    );
    $stmt->execute(['id' => $excludeId]);
    return $stmt->fetchAll();
}

function create_project_link(int $projectIdA, int $projectIdB, string $linkType, ?float $percentage = null): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO project_links (project_id_a, project_id_b, link_type, percentage)
         VALUES (:a, :b, :type, :pct)"
    );
    $stmt->execute([
        'a'    => $projectIdA,
        'b'    => $projectIdB,
        'type' => $linkType,
        'pct'  => $percentage,
    ]);
    return (int) $pdo->lastInsertId();
}
