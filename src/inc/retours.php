<?php

/**
 * Boîte à idées : problèmes et améliorations remontés par les utilisateurs.
 *
 * Cloisonnement : un utilisateur ne lit que ses propres messages
 * (retours_de_l_utilisateur). La liste complète (retours_tous) et les
 * actions de traitement sont réservées aux pages appelant require_direction().
 */

require_once __DIR__ . '/access.php';
require_once __DIR__ . '/ui.php';

const RETOUR_TYPES = [
    'probleme' => 'Problème',
    'idee'     => 'Amélioration',
];

const RETOUR_STATUTS = [
    'nouveau'  => 'Nouveau',
    'en_cours' => 'En cours',
    'traite'   => 'Traité',
];

const RETOUR_SUJET_MAX   = 150;
const RETOUR_MESSAGE_MAX = 5000;

/** La migration 020 est-elle passée ? */
function retours_disponibles(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            get_db()->query("SELECT 1 FROM retours LIMIT 1");
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

/**
 * Valide et enregistre un message. Retourne la liste des erreurs (vide si enregistré).
 */
function envoyer_retour(int $userId, string $type, string $sujet, string $message, ?string $page): array
{
    $sujet   = trim($sujet);
    $message = trim($message);
    $errors  = [];

    if (!isset(RETOUR_TYPES[$type])) {
        $errors[] = "Choisissez s'il s'agit d'un problème ou d'une amélioration.";
    }
    if ($sujet === '') {
        $errors[] = "Le sujet est obligatoire.";
    } elseif (mb_strlen($sujet) > RETOUR_SUJET_MAX) {
        $errors[] = "Le sujet ne doit pas dépasser " . RETOUR_SUJET_MAX . " caractères.";
    }
    if ($message === '') {
        $errors[] = "Décrivez votre problème ou votre idée.";
    } elseif (mb_strlen($message) > RETOUR_MESSAGE_MAX) {
        $errors[] = "Le message ne doit pas dépasser " . RETOUR_MESSAGE_MAX . " caractères.";
    }
    if ($errors) {
        return $errors;
    }

    $page = page_d_origine($page);

    $stmt = get_db()->prepare(
        "INSERT INTO retours (user_id, type, sujet, message, page) VALUES (:u, :t, :s, :m, :p)"
    );
    $stmt->execute(['u' => $userId, 't' => $type, 's' => $sujet, 'm' => $message, 'p' => $page]);
    return [];
}

/**
 * Ne garde qu'un chemin interne (/pages/...) : on n'enregistre jamais une adresse
 * externe fournie par le navigateur, elle serait ensuite affichée comme lien.
 */
function page_d_origine(?string $page): ?string
{
    if ($page === null || $page === '') {
        return null;
    }
    $chemin = parse_url($page, PHP_URL_PATH);
    $query  = parse_url($page, PHP_URL_QUERY);
    if (!is_string($chemin) || !preg_match('#^/pages/[a-z0-9_]+\.php$#', $chemin)) {
        return null;
    }
    if ($chemin === '/pages/remonter.php') {
        return null;
    }
    $complet = $chemin . (is_string($query) && $query !== '' ? '?' . $query : '');
    return mb_substr($complet, 0, 255);
}

/** Messages envoyés par un utilisateur, du plus récent au plus ancien */
function retours_de_l_utilisateur(int $userId): array
{
    $stmt = get_db()->prepare(
        "SELECT r.*, rep.name AS repondant
         FROM retours r
         LEFT JOIN users rep ON rep.id = r.repondu_par
         WHERE r.user_id = :u
         ORDER BY r.created_at DESC, r.id DESC"
    );
    $stmt->execute(['u' => $userId]);
    return $stmt->fetchAll();
}

/**
 * Tous les messages (direction uniquement). Filtres : statut et type.
 * Ordre : nouveaux d'abord, puis en cours, puis traités ; plus récents en tête.
 */
function retours_tous(?string $statut = null, ?string $type = null): array
{
    $where  = [];
    $params = [];
    if ($statut !== null && isset(RETOUR_STATUTS[$statut])) {
        $where[] = 'r.statut = :statut';
        $params['statut'] = $statut;
    }
    if ($type !== null && isset(RETOUR_TYPES[$type])) {
        $where[] = 'r.type = :type';
        $params['type'] = $type;
    }
    $stmt = get_db()->prepare(
        "SELECT r.*, u.name AS auteur, u.email AS auteur_email, rep.name AS repondant
         FROM retours r
         LEFT JOIN users u   ON u.id = r.user_id
         LEFT JOIN users rep ON rep.id = r.repondu_par
         " . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . "
         ORDER BY FIELD(r.statut, 'nouveau', 'en_cours', 'traite'), r.created_at DESC, r.id DESC"
    );
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Nombre de messages par statut : ['nouveau' => 3, 'en_cours' => 1, 'traite' => 8] */
function retours_compteurs(): array
{
    $compte = array_fill_keys(array_keys(RETOUR_STATUTS), 0);
    if (!retours_disponibles()) {
        return $compte;
    }
    foreach (get_db()->query("SELECT statut, COUNT(*) AS n FROM retours GROUP BY statut") as $row) {
        $compte[$row['statut']] = (int) $row['n'];
    }
    return $compte;
}

/** Messages non traités (nouveaux), pour la pastille de la barre de navigation */
function retours_nouveaux(): int
{
    return retours_compteurs()['nouveau'];
}

/**
 * Traitement par la direction : change le statut et, si une réponse est saisie,
 * l'enregistre (visible par l'auteur). Une réponse vide efface la réponse existante.
 */
function traiter_retour(int $id, string $statut, string $reponse, int $parUserId): bool
{
    if (!isset(RETOUR_STATUTS[$statut])) {
        return false;
    }
    $reponse = trim($reponse);
    if (mb_strlen($reponse) > RETOUR_MESSAGE_MAX) {
        $reponse = mb_substr($reponse, 0, RETOUR_MESSAGE_MAX);
    }
    $pdo = get_db();

    $actuel = $pdo->prepare("SELECT reponse FROM retours WHERE id = :id");
    $actuel->execute(['id' => $id]);
    $ligne = $actuel->fetch();
    if (!$ligne) {
        return false;
    }

    if ($reponse === '') {
        $stmt = $pdo->prepare(
            "UPDATE retours SET statut = :s, reponse = NULL, repondu_par = NULL, repondu_le = NULL WHERE id = :id"
        );
        $stmt->execute(['s' => $statut, 'id' => $id]);
    } elseif ($reponse !== (string) $ligne['reponse']) {
        $stmt = $pdo->prepare(
            "UPDATE retours SET statut = :s, reponse = :r, repondu_par = :p, repondu_le = NOW() WHERE id = :id"
        );
        $stmt->execute(['s' => $statut, 'r' => $reponse, 'p' => $parUserId, 'id' => $id]);
    } else {
        $stmt = $pdo->prepare("UPDATE retours SET statut = :s WHERE id = :id");
        $stmt->execute(['s' => $statut, 'id' => $id]);
    }
    return true;
}

/** Supprime un message (direction uniquement : spam, doublon) */
function supprimer_retour(int $id): void
{
    get_db()->prepare("DELETE FROM retours WHERE id = :id")->execute(['id' => $id]);
}

/** Pastille de type */
function badge_type_retour(string $type): string
{
    $classe = $type === 'probleme' ? 'bg-danger' : 'bg-info';
    return '<span class="badge ' . $classe . '">' . h(RETOUR_TYPES[$type] ?? $type) . '</span>';
}

/** Pastille de statut */
function badge_statut_retour(string $statut): string
{
    $classe = ['nouveau' => 'bg-warning', 'en_cours' => 'bg-info', 'traite' => 'bg-success'][$statut] ?? 'bg-secondary';
    return '<span class="badge ' . $classe . '">' . h(RETOUR_STATUTS[$statut] ?? $statut) . '</span>';
}
