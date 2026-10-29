<?php

/**
 * Gestion locative Qalimo : biens, baux et écritures des SCI.
 *
 * cron/qalimo/qalimo_sync.php lit l'API chaque nuit (GET uniquement) et remplace le
 * contenu des tables qalimo_*. Ce fichier fournit l'enregistrement, le rapprochement
 * profil bailleur → société ADHUB et les indicateurs du bloc « Immobilier ».
 *
 * Convention Qalimo : un appel (loyer, charges) est négatif, un paiement positif.
 * Le solde d'un locataire est donc la somme de ses écritures, hors dépôt de garantie
 * (types appel_dg, paiement_dg…) : négatif = il doit de l'argent.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/pennylane.php'; // pl_mots / pennylane_suggerer : même rapprochement par nom

const QALIMO_TYPES = [
    'habitation' => 'Logement',
    'commerce'   => 'Local',
    'commercial' => 'Local',
    'parking'    => 'Parking',
    'garage'     => 'Garage',
];

function qalimo_disponible(): bool
{
    static $ok = null;
    if ($ok === null) {
        $ok = (bool) get_db()->query("SHOW TABLES LIKE 'qalimo_biens'")->fetchColumn();
    }
    return $ok;
}

/**
 * Société ADHUB d'un profil bailleur : correspondance forcée dans la config, sinon le nom.
 * Les restaurants (exploitation) sont écartés : « SCI VALENTINO » est la SCI, pas le restaurant.
 */
function qalimo_societe_du_profil(array $profil, array $forcees): ?int
{
    if (isset($forcees[$profil['id']])) {
        return (int) $forcees[$profil['id']];
    }
    static $candidats = null;
    if ($candidats === null) {
        $candidats = get_db()->query(
            "SELECT p.id, p.name FROM projects p JOIN project_types pt ON pt.id = p.project_type_id
             WHERE p.status = 'active' AND pt.code <> 'restauration_exploitation'"
        )->fetchAll();
    }
    return pennylane_suggerer($profil['name'], $candidats);
}

/**
 * Remplace toutes les données Qalimo par celles qui viennent d'être lues, en une transaction :
 * une synchro interrompue laisse les données de la veille intactes.
 * $donnees : profils, biens (fiches détail), baux (fiches détail), ecritures [bail_id => liste].
 * Renvoie [project_id => [total, loues]] pour les sociétés reliées.
 */
function qalimo_enregistrer(array $donnees, array $liens): array
{
    $pdo = get_db();
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        foreach (['qalimo_ecritures', 'qalimo_baux', 'qalimo_biens', 'qalimo_profils'] as $t) {
            $pdo->exec("DELETE FROM $t");
        }
        $insP = $pdo->prepare("INSERT INTO qalimo_profils (id, nom, societe, project_id, synced_at) VALUES (?, ?, ?, ?, ?)");
        foreach ($donnees['profils'] as $p) {
            $insP->execute([$p['id'], $p['name'], empty($p['is_company']) ? 0 : 1, $liens[$p['id']] ?? null, $now]);
        }
        $insB = $pdo->prepare(
            "INSERT INTO qalimo_biens (id, profil_id, nom, type, type_logement, ville, surface, pieces, loyer_hc, charges, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($donnees['biens'] as $b) {
            $insB->execute([$b['id'], $b['owner_profile_id'] ?? null, trim(preg_replace('/\s+/', ' ', $b['name'])), $b['type'] ?? null,
                            $b['housing_type'] ?? null, $b['city'] ?? null, $b['surface_area'] ?? null, $b['main_room_count'] ?? null,
                            $b['rent_excluding_charges'] ?? null, $b['charges'] ?? null, $now]);
        }
        $insL = $pdo->prepare(
            "INSERT INTO qalimo_baux (id, bien_id, locataire, debut, fin, loyer_hc, charges, preavis, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        foreach ($donnees['baux'] as $l) {
            $insL->execute([$l['id'], $l['property_id'], $l['name'] ?? null, $l['start_date'] ?? null, $l['end_date'] ?? null,
                            $l['rent_excluding_charges'] ?? null, $l['charges'] ?? null, empty($l['notice_given']) ? 0 : 1, $now]);
        }
        $insE = $pdo->prepare(
            "INSERT IGNORE INTO qalimo_ecritures (id, bail_id, date_ecriture, type, montant, domaine) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($donnees['ecritures'] as $bailId => $liste) {
            foreach ($liste as $e) {
                if (empty($e['date'])) {
                    continue;
                }
                $insE->execute([$e['id'], $bailId, substr($e['date'], 0, 10), (string) $e['type'], (float) $e['amount'], $e['domain'] ?? null]);
            }
        }

        // Occupation des sociétés reliées : un bien est loué s'il a au moins un bail actif
        $lots = [];
        foreach ($pdo->query(
            "SELECT p.project_id, COUNT(*) AS total, SUM(EXISTS(SELECT 1 FROM qalimo_baux l WHERE l.bien_id = b.id)) AS loues
             FROM qalimo_biens b JOIN qalimo_profils p ON p.id = b.profil_id
             WHERE p.project_id IS NOT NULL GROUP BY p.project_id"
        ) as $r) {
            $lots[(int) $r['project_id']] = [(int) $r['total'], (int) $r['loues']];
        }
        $maj = $pdo->prepare("UPDATE projects SET lots_total = ?, lots_loues = ? WHERE id = ?");
        foreach ($lots as $pid => [$total, $loues]) {
            $maj->execute([$total, $loues, $pid]);
        }
        $pdo->commit();
        return $lots;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/* Indicateurs                                                         */
/* ------------------------------------------------------------------ */

/**
 * Biens d'une société avec leur bail actif, le solde du locataire et l'échéance.
 * Un bien avec plusieurs baux actifs (rare) apparaît une fois par bail.
 */
function qalimo_biens_projet(int $projectId): array
{
    $stmt = get_db()->prepare(
        "SELECT b.id, b.nom, b.type, b.ville, b.surface, b.loyer_hc AS loyer_bien, b.charges AS charges_bien,
                l.id AS bail_id, l.locataire, l.debut, l.fin, l.loyer_hc, l.charges, l.preavis,
                (SELECT SUM(e.montant) FROM qalimo_ecritures e
                  WHERE e.bail_id = l.id AND (e.domaine IS NULL OR e.domaine = 'tenant')
                    AND e.type NOT REGEXP '(^|_)dg($|_)') AS solde
         FROM qalimo_biens b
         JOIN qalimo_profils p ON p.id = b.profil_id
         LEFT JOIN qalimo_baux l ON l.bien_id = b.id
         WHERE p.project_id = :pid
         ORDER BY (l.id IS NULL) DESC, b.nom"
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

/** Loyers et charges appelés / encaissés par mois (AAAA-MM) pour un ensemble de sociétés, sur N mois */
function qalimo_mensuel(array $projectIds, int $nbMois = 12): array
{
    $mois = [];
    $d = new DateTimeImmutable('first day of this month');
    for ($i = $nbMois - 1; $i >= 0; $i--) {
        $mois[$d->modify("-$i months")->format('Y-m')] = ['appele' => 0.0, 'encaisse' => 0.0];
    }
    if (!$projectIds) {
        return $mois;
    }
    $in = implode(',', array_map('intval', $projectIds));
    $rows = get_db()->query(
        "SELECT DATE_FORMAT(e.date_ecriture, '%Y-%m') AS mois,
                -SUM(CASE WHEN e.montant < 0 THEN e.montant ELSE 0 END) AS appele,
                 SUM(CASE WHEN e.montant > 0 THEN e.montant ELSE 0 END) AS encaisse
         FROM qalimo_ecritures e
         JOIN qalimo_baux l ON l.id = e.bail_id
         JOIN qalimo_biens b ON b.id = l.bien_id
         JOIN qalimo_profils p ON p.id = b.profil_id
         WHERE p.project_id IN ($in)
           AND (e.domaine IS NULL OR e.domaine = 'tenant')
           AND e.type NOT REGEXP '(^|_)dg($|_)'
           AND e.date_ecriture >= '" . array_key_first($mois) . "-01'
         GROUP BY mois"
    )->fetchAll();
    foreach ($rows as $r) {
        if (isset($mois[$r['mois']])) {
            $mois[$r['mois']] = ['appele' => (float) $r['appele'], 'encaisse' => (float) $r['encaisse']];
        }
    }
    return $mois;
}

/**
 * Résumé pour le bloc « Immobilier » d'une société, ou null si aucun bien Qalimo n'y est relié.
 */
function qalimo_resume_projet(int $projectId): ?array
{
    if (!qalimo_disponible()) {
        return null;
    }
    $lignes = qalimo_biens_projet($projectId);
    if (!$lignes) {
        return null;
    }
    $biens = [];
    $impayes = 0.0;
    $nbImpayes = 0;
    $echeance = null;
    foreach ($lignes as $l) {
        $biens[$l['id']] = ($biens[$l['id']] ?? false) || $l['bail_id'] !== null;
        if ($l['solde'] !== null && (float) $l['solde'] < -0.009) {
            $impayes += -(float) $l['solde'];
            $nbImpayes++;
        }
        if ($l['fin'] && $l['fin'] >= date('Y-m-d') && (!$echeance || $l['fin'] < $echeance['fin'])) {
            $echeance = $l;
        }
    }
    $mensuel = qalimo_mensuel([$projectId]);
    $sync = get_db()->query("SELECT MAX(synced_at) FROM qalimo_profils")->fetchColumn();
    return [
        'lignes'     => $lignes,
        'total'      => count($biens),
        'loues'      => count(array_filter($biens)),
        'impayes'    => $impayes,
        'nb_impayes' => $nbImpayes,
        'preavis'    => count(array_filter($lignes, fn($l) => (int) $l['preavis'] === 1)),
        'echeance'   => $echeance,
        'mensuel'    => $mensuel,
        'mois'       => end($mensuel),
        'sync'       => $sync ?: null,
    ];
}

/** Total des impayés locataires de toutes les sociétés reliées (vue d'ensemble) */
function qalimo_impayes_total(): ?array
{
    if (!qalimo_disponible()) {
        return null;
    }
    $r = get_db()->query(
        "SELECT COUNT(*) AS nb, COALESCE(-SUM(s.solde), 0) AS total FROM (
            SELECT SUM(e.montant) AS solde
            FROM qalimo_ecritures e
            JOIN qalimo_baux l ON l.id = e.bail_id
            JOIN qalimo_biens b ON b.id = l.bien_id
            JOIN qalimo_profils p ON p.id = b.profil_id
            WHERE p.project_id IS NOT NULL
              AND (e.domaine IS NULL OR e.domaine = 'tenant')
              AND e.type NOT REGEXP '(^|_)dg($|_)'
            GROUP BY e.bail_id
            HAVING solde < -0.009
         ) s"
    )->fetch();
    $sync = get_db()->query("SELECT MAX(synced_at) FROM qalimo_profils")->fetchColumn();
    return $sync ? ['nb' => (int) $r['nb'], 'total' => (float) $r['total']] : null;
}
