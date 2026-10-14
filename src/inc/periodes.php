<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/daily.php';

/**
 * Comparaison de périodes (jour / semaine / mois) avec l'année précédente.
 *
 * CHOIX DE MÉTHODE — comparaison N-1 à 364 jours
 * ----------------------------------------------
 * Pour un restaurant, le jour de la semaine pèse plus que la date : un vendredi
 * ne se compare pas à un jeudi. Or "même date l'an dernier" décale le jour de la
 * semaine (7 août 2026 = vendredi, 7 août 2025 = jeudi).
 *
 * On recule donc de 364 jours (52 semaines pile), ce qui conserve toujours le
 * jour de la semaine, au prix d'un décalage d'un seul jour dans le calendrier.
 *
 * Exception : la vue mensuelle utilise le vrai mois calendaire N-1, parce qu'un
 * mois est une unité comptable — on veut bien comparer août à août.
 */

const DECALAGE_N1_JOURS = 364;

/**
 * Totaux d'une période (bornes incluses).
 */
function totaux_periode(int $projectId, string $debut, string $fin): array
{
    $stmt = get_db()->prepare(
        "SELECT COALESCE(SUM(ca_ttc), 0) AS ca,
                COALESCE(SUM(couverts), 0) AS couverts,
                COUNT(*) AS jours_saisis
         FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :debut AND :fin"
    );
    $stmt->execute(['pid' => $projectId, 'debut' => $debut, 'fin' => $fin]);
    $row = $stmt->fetch();

    return [
        'ca'           => (float) $row['ca'],
        'couverts'     => (int) $row['couverts'],
        'jours_saisis' => (int) $row['jours_saisis'],
        'ticket_moyen' => ticket_moyen((float) $row['ca'], (int) $row['couverts']),
        'debut'        => $debut,
        'fin'          => $fin,
    ];
}

/**
 * Variation en % entre deux valeurs.
 * Retourne null si la référence est nulle : "+100 %" à partir de zéro n'aurait
 * aucun sens, mieux vaut n'afficher aucun pourcentage.
 */
function variation(?float $actuel, ?float $reference): ?float
{
    if ($reference === null || abs($reference) < 0.01 || $actuel === null) {
        return null;
    }
    return (($actuel - $reference) / $reference) * 100;
}

/**
 * Bornes de la période demandée et de sa référence N-1.
 *
 * $vue : 'jour' | 'semaine' | 'mois'
 */
function bornes_periode(string $vue, ?DateTimeImmutable $reference = null): array
{
    $ref = $reference ?? new DateTimeImmutable('today');

    switch ($vue) {
        case 'jour':
            // J-1 : la veille, dernier jour complet
            $debut = $ref->modify('-1 day');
            $fin   = $debut;
            $libelle = 'Journée du ' . $debut->format('d/m/Y');
            break;

        case 'semaine':
            // Semaine en cours, du lundi à aujourd'hui
            $debut = $ref->modify('monday this week');
            $fin   = $ref;
            $libelle = 'Semaine du ' . $debut->format('d/m') . ' au ' . $fin->format('d/m/Y');
            break;

        case 'mois':
        default:
            $debut = $ref->modify('first day of this month');
            $fin   = $ref;
            $libelle = 'Mois en cours au ' . $fin->format('d/m/Y');
            break;
    }

    // Période de comparaison, de même longueur
    if ($vue === 'mois') {
        // Mois calendaire de l'an dernier, tronqué au même nombre de jours écoulés
        $nbJours   = (int) $debut->diff($fin)->days + 1;
        $n1Debut   = $debut->modify('-1 year');
        $n1Fin     = $n1Debut->modify('+' . ($nbJours - 1) . ' days');
    } else {
        $n1Debut = $debut->modify('-' . DECALAGE_N1_JOURS . ' days');
        $n1Fin   = $fin->modify('-' . DECALAGE_N1_JOURS . ' days');
    }

    return [
        'libelle'   => $libelle,
        'debut'     => $debut->format('Y-m-d'),
        'fin'       => $fin->format('Y-m-d'),
        'n1_debut'  => $n1Debut->format('Y-m-d'),
        'n1_fin'    => $n1Fin->format('Y-m-d'),
    ];
}

/**
 * Comparatif complet prêt à afficher.
 */
function comparatif_periode(int $projectId, string $vue): array
{
    $b = bornes_periode($vue);

    $actuel = totaux_periode($projectId, $b['debut'], $b['fin']);
    $n1     = totaux_periode($projectId, $b['n1_debut'], $b['n1_fin']);

    return [
        'vue'      => $vue,
        'libelle'  => $b['libelle'],
        'bornes'   => $b,
        'actuel'   => $actuel,
        'n1'       => $n1,
        'var_ca'       => variation($actuel['ca'], $n1['ca']),
        'var_couverts' => variation((float) $actuel['couverts'], (float) $n1['couverts']),
        'var_ticket'   => variation($actuel['ticket_moyen'], $n1['ticket_moyen']),
        // Sans données N-1, toute comparaison est trompeuse : on le signale.
        'n1_disponible' => $n1['jours_saisis'] > 0,
    ];
}

/**
 * Série jour par jour sur une période, avec la valeur N-1 en regard.
 * Les jours sans saisie sont conservés à 0 pour ne pas déformer le graphique.
 */
function serie_comparee(int $projectId, string $debut, string $fin, string $n1Debut): array
{
    $stmt = get_db()->prepare(
        "SELECT entry_date, ca_ttc FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :d AND :f"
    );
    $stmt->execute(['pid' => $projectId, 'd' => $debut, 'f' => $fin]);
    $actuels = [];
    foreach ($stmt->fetchAll() as $r) {
        $actuels[$r['entry_date']] = (float) $r['ca_ttc'];
    }

    $nbJours = (new DateTimeImmutable($debut))->diff(new DateTimeImmutable($fin))->days;
    $n1Fin   = (new DateTimeImmutable($n1Debut))->modify("+{$nbJours} days")->format('Y-m-d');

    $stmt2 = get_db()->prepare(
        "SELECT entry_date, ca_ttc FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :d AND :f"
    );
    $stmt2->execute(['pid' => $projectId, 'd' => $n1Debut, 'f' => $n1Fin]);
    $n1s = [];
    foreach ($stmt2->fetchAll() as $r) {
        $n1s[$r['entry_date']] = (float) $r['ca_ttc'];
    }

    $serie = [];
    $curseur   = new DateTimeImmutable($debut);
    $curseurN1 = new DateTimeImmutable($n1Debut);
    $finDate   = new DateTimeImmutable($fin);

    while ($curseur <= $finDate) {
        $jour   = $curseur->format('Y-m-d');
        $jourN1 = $curseurN1->format('Y-m-d');
        $serie[] = [
            'date'     => $jour,
            'libelle'  => $curseur->format('d/m'),
            'ca'       => $actuels[$jour] ?? 0.0,
            'ca_n1'    => $n1s[$jourN1] ?? 0.0,
        ];
        $curseur   = $curseur->modify('+1 day');
        $curseurN1 = $curseurN1->modify('+1 day');
    }

    return $serie;
}
