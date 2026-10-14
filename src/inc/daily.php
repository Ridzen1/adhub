<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Saisie quotidienne des restaurants.
 *
 * Toutes les sources (saisie manuelle, import de fichier, API Popina) écrivent
 * dans la même table `daily_entries`, différenciées par la colonne `source`.
 * Le dashboard n'a donc jamais à savoir d'où vient la donnée.
 *
 * Le ticket moyen n'est jamais stocké : il se calcule à la volée (CA / couverts)
 * pour rester cohérent si le CA ou les couverts sont corrigés après coup.
 */

function ticket_moyen(float $ca, int $couverts): ?float
{
    return $couverts > 0 ? $ca / $couverts : null;
}

/**
 * Insère ou met à jour la ligne du jour. Une seule ligne par restaurant et par date
 * (contrainte UNIQUE en base), donc une nouvelle saisie corrige la précédente.
 *
 * $extra accepte les champs issus du rapport de caisse : ca_ht, remises, offerts,
 * ticket_moyen, corrections_cuisine, ca_solide, ca_liquide.
 */
function upsert_daily_entry(
    int $projectId,
    string $entryDate,
    float $caTtc,
    int $couverts,
    ?string $notes = null,
    string $source = 'manuel',
    ?int $userId = null,
    array $extra = []
): void {
    $champs = ['ca_ht', 'remises', 'offerts', 'ticket_moyen',
               'corrections_cuisine', 'ca_solide', 'ca_liquide'];

    $params = [
        'pid' => $projectId, 'date' => $entryDate,
        'ca' => $caTtc, 'cv' => $couverts, 'notes' => $notes,
        'source' => $source, 'uid' => $userId,
        'ca2' => $caTtc, 'cv2' => $couverts, 'notes2' => $notes, 'source2' => $source,
    ];
    foreach ($champs as $c) {
        $params[$c] = $extra[$c] ?? null;
        $params[$c . '_2'] = $extra[$c] ?? null;
    }

    $listeInsert = implode(', ', $champs);
    $listeValues = implode(', ', array_map(fn($c) => ':' . $c, $champs));
    $listeUpdate = implode(', ', array_map(fn($c) => "{$c} = :{$c}_2", $champs));

    $stmt = get_db()->prepare(
        "INSERT INTO daily_entries
            (project_id, entry_date, ca_ttc, couverts, notes, source, created_by, {$listeInsert})
         VALUES (:pid, :date, :ca, :cv, :notes, :source, :uid, {$listeValues})
         ON DUPLICATE KEY UPDATE
            ca_ttc = :ca2, couverts = :cv2, notes = :notes2, source = :source2, {$listeUpdate}"
    );
    $stmt->execute($params);
}

/**
 * Signale une journée dont le chiffre d'affaires s'écarte fortement de l'habitude
 * pour le même jour de la semaine.
 *
 * Motif principal : un service non clôturé en caisse produit une journée vide ou
 * partielle, et la journée suivante peut absorber le service manquant. L'outil
 * SIGNALE sans corriger — il ne peut pas distinguer un oubli d'une vraie mauvaise
 * journée, ni d'un jour de fermeture.
 *
 * Retourne 'nul', 'bas', 'haut', ou null si rien à signaler ou historique trop court.
 */
function detecter_anomalie(int $projectId, string $entryDate, float $caTtc): ?string
{
    $jourSemaine = (int) (new DateTimeImmutable($entryDate))->format('N');

    $stmt = get_db()->prepare(
        "SELECT ca_ttc FROM daily_entries
         WHERE project_id = :pid
           AND entry_date <> :date
           AND DAYOFWEEK(entry_date) = :jour
           AND ca_ttc > 0
         ORDER BY entry_date DESC
         LIMIT 8"
    );
    // DAYOFWEEK() : 1 = dimanche … 7 = samedi ; format('N') : 1 = lundi … 7 = dimanche
    $stmt->execute([
        'pid'  => $projectId,
        'date' => $entryDate,
        'jour' => ($jourSemaine % 7) + 1,
    ]);

    $valeurs = array_map('floatval', array_column($stmt->fetchAll(), 'ca_ttc'));

    // En dessous de trois références, une médiane n'a pas de sens.
    if (count($valeurs) < 3) {
        return null;
    }

    sort($valeurs);
    $n = count($valeurs);
    $mediane = $n % 2
        ? $valeurs[intdiv($n, 2)]
        : ($valeurs[$n / 2 - 1] + $valeurs[$n / 2]) / 2;

    if ($mediane <= 0) {
        return null;
    }
    if ($caTtc == 0.0) {
        return 'nul';
    }
    if ($caTtc <= $mediane * 0.5) {
        return 'bas';
    }
    if ($caTtc >= $mediane * 1.8) {
        return 'haut';
    }
    return null;
}

function libelle_anomalie(string $code): string
{
    return match ($code) {
        'nul'  => "Aucun chiffre d'affaires : service probablement non clôturé en caisse.",
        'bas'  => "Chiffre d'affaires très inférieur à l'habitude pour ce jour : journée peut-être partielle.",
        'haut' => "Chiffre d'affaires très supérieur à l'habitude : la journée absorbe peut-être un service de la veille.",
        default => '',
    };
}

function get_daily_entry(int $projectId, string $entryDate): ?array
{
    $stmt = get_db()->prepare(
        "SELECT * FROM daily_entries WHERE project_id = :pid AND entry_date = :date"
    );
    $stmt->execute(['pid' => $projectId, 'date' => $entryDate]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function delete_daily_entry(int $projectId, string $entryDate): void
{
    $stmt = get_db()->prepare(
        "DELETE FROM daily_entries WHERE project_id = :pid AND entry_date = :date"
    );
    $stmt->execute(['pid' => $projectId, 'date' => $entryDate]);
}

/**
 * Les N derniers jours saisis, du plus ancien au plus récent (pratique pour un graphique).
 */
function get_recent_days(int $projectId, int $days = 7): array
{
    $days = max(1, min(365, $days)); // borne dure : la valeur part dans le SQL
    $sql = "SELECT * FROM (
                SELECT * FROM daily_entries
                WHERE project_id = :pid
                ORDER BY entry_date DESC
                LIMIT {$days}
            ) AS derniers
            ORDER BY entry_date ASC";
    $stmt = get_db()->prepare($sql);
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function get_month_entries(int $projectId, int $year, int $month): array
{
    $stmt = get_db()->prepare(
        "SELECT * FROM daily_entries
         WHERE project_id = :pid AND YEAR(entry_date) = :y AND MONTH(entry_date) = :m
         ORDER BY entry_date ASC"
    );
    $stmt->execute(['pid' => $projectId, 'y' => $year, 'm' => $month]);
    return $stmt->fetchAll();
}

/**
 * Cumul du mois : CA, couverts, nombre de jours saisis.
 * Le nombre de jours saisis sert à repérer les trous (ex. 12 jours saisis sur 20 écoulés).
 */
function get_month_summary(int $projectId, int $year, int $month): array
{
    $stmt = get_db()->prepare(
        "SELECT COALESCE(SUM(ca_ttc),0) AS ca_total,
                COALESCE(SUM(couverts),0) AS couverts_total,
                COUNT(*) AS jours_saisis
         FROM daily_entries
         WHERE project_id = :pid AND YEAR(entry_date) = :y AND MONTH(entry_date) = :m"
    );
    $stmt->execute(['pid' => $projectId, 'y' => $year, 'm' => $month]);
    return $stmt->fetch();
}

/**
 * Dates manquantes entre la première saisie du mois et aujourd'hui (ou la fin du mois).
 * Un restaurant fermé un jour donné apparaîtra ici : c'est voulu, l'outil ne peut pas
 * deviner la différence entre "fermé" et "oublié".
 */
function get_missing_days(int $projectId, int $year, int $month): array
{
    $entries = get_month_entries($projectId, $year, $month);
    if (!$entries) {
        return [];
    }

    $saisies = array_column($entries, 'entry_date');
    $premier = new DateTime($saisies[0]);

    $finMois = new DateTime(sprintf('%04d-%02d-01', $year, $month));
    $finMois->modify('last day of this month');
    $aujourdhui = new DateTime('today');
    $fin = $aujourdhui < $finMois ? $aujourdhui : $finMois;

    $manquants = [];
    $curseur = clone $premier;
    while ($curseur <= $fin) {
        $jour = $curseur->format('Y-m-d');
        if (!in_array($jour, $saisies, true)) {
            $manquants[] = $jour;
        }
        $curseur->modify('+1 day');
    }
    return $manquants;
}

/**
 * Indicateurs prêts à afficher pour le dashboard restaurant.
 */
function get_restaurant_kpis(int $projectId): array
{
    $today     = date('Y-m-d');
    $yesterday = date('Y-m-d', strtotime('-1 day'));

    $entryToday     = get_daily_entry($projectId, $today);
    $entryYesterday = get_daily_entry($projectId, $yesterday);

    // On affiche le jour le plus récent réellement saisi : à 9h du matin, la veille
    // est souvent plus pertinente que le jour en cours encore vide.
    $jour = $entryToday ?? $entryYesterday;

    $month = get_month_summary($projectId, (int) date('Y'), (int) date('n'));

    return [
        'jour'              => $jour,
        'jour_ticket'       => $jour ? ticket_moyen((float) $jour['ca_ttc'], (int) $jour['couverts']) : null,
        'mois_ca'           => (float) $month['ca_total'],
        'mois_couverts'     => (int) $month['couverts_total'],
        'mois_jours_saisis' => (int) $month['jours_saisis'],
        'mois_ticket'       => ticket_moyen((float) $month['ca_total'], (int) $month['couverts_total']),
    ];
}

/**
 * Un projet est-il un restaurant (donc concerné par la saisie quotidienne) ?
 */
function is_restaurant(array $project): bool
{
    $stmt = get_db()->prepare("SELECT code FROM project_types WHERE id = :id");
    $stmt->execute(['id' => $project['project_type_id']]);
    $row = $stmt->fetch();
    return $row && $row['code'] === 'restauration_exploitation';
}

/**
 * "août 2026" — setlocale n'est pas fiable selon les serveurs, on fait simple.
 */
function format_mois_fr(int $annee, int $mois): string
{
    $noms = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin',
             'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
    return ($noms[$mois] ?? '?') . ' ' . $annee;
}
