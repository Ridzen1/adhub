<?php

/**
 * Indicateurs pour la vue d'ensemble et les tableaux de bord.
 * Les restaurants ne sont jamais additionnés entre eux : chaque fonction qui reçoit
 * plusieurs ids renvoie un résultat par société, sauf ca_societes(), à n'appeler
 * qu'avec une seule société quand il s'agit de restaurants.
 * Lecture seule : rien ici n'écrit en base.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/periodes.php';

/** CA total de plusieurs sociétés sur une période (bornes incluses) */
function ca_societes(array $ids, string $du, string $au): array
{
    if (!$ids) {
        return ['ca' => 0.0, 'couverts' => 0, 'jours' => 0];
    }
    $in = implode(',', array_map('intval', $ids));
    $stmt = get_db()->prepare(
        "SELECT COALESCE(SUM(ca_ttc), 0) AS ca, COALESCE(SUM(couverts), 0) AS couverts, COUNT(*) AS jours
         FROM daily_entries WHERE project_id IN ($in) AND entry_date BETWEEN :du AND :au"
    );
    $stmt->execute(['du' => $du, 'au' => $au]);
    $r = $stmt->fetch();
    return ['ca' => (float) $r['ca'], 'couverts' => (int) $r['couverts'], 'jours' => (int) $r['jours']];
}

/** CA par société sur une période : [project_id => ca] */
function ca_par_societe(array $ids, string $du, string $au): array
{
    if (!$ids) {
        return [];
    }
    $in = implode(',', array_map('intval', $ids));
    $stmt = get_db()->prepare(
        "SELECT project_id, SUM(ca_ttc) AS ca FROM daily_entries
         WHERE project_id IN ($in) AND entry_date BETWEEN :du AND :au GROUP BY project_id"
    );
    $stmt->execute(['du' => $du, 'au' => $au]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[(int) $r['project_id']] = (float) $r['ca'];
    }
    return $out;
}

/**
 * CA jour par jour et par société, jours sans saisie à 0 : un graphique par société.
 * Retourne ['labels' => [...], 'series' => [project_id => [valeurs]]]
 */
function ca_quotidien_societes(array $ids, string $du, string $au): array
{
    $labels = [];
    $index  = [];
    for ($d = new DateTimeImmutable($du), $f = new DateTimeImmutable($au); $d <= $f; $d = $d->modify('+1 day')) {
        $index[$d->format('Y-m-d')] = count($labels);
        $labels[] = $d->format('d/m');
    }
    $series = [];
    foreach ($ids as $id) {
        $series[(int) $id] = array_fill(0, count($labels), 0.0);
    }
    if ($ids) {
        $in = implode(',', array_map('intval', $ids));
        $stmt = get_db()->prepare(
            "SELECT project_id, entry_date, ca_ttc FROM daily_entries
             WHERE project_id IN ($in) AND entry_date BETWEEN :du AND :au"
        );
        $stmt->execute(['du' => $du, 'au' => $au]);
        foreach ($stmt->fetchAll() as $r) {
            $series[(int) $r['project_id']][$index[$r['entry_date']]] = (float) $r['ca_ttc'];
        }
    }
    return ['labels' => $labels, 'series' => $series];
}

/** Résultat cumulé de l'année (saisies mensuelles) pour plusieurs sociétés */
function resultat_annee(array $ids, int $annee, ?int $jusquAuMois = null): array
{
    if (!$ids) {
        return ['revenus' => 0.0, 'charges' => 0.0, 'mois' => 0];
    }
    $in  = implode(',', array_map('intval', $ids));
    $fin = $jusquAuMois ? sprintf('%04d-%02d-01', $annee, $jusquAuMois) : sprintf('%04d-12-01', $annee);
    $stmt = get_db()->prepare(
        "SELECT COALESCE(SUM(revenus), 0) AS revenus, COALESCE(SUM(charges), 0) AS charges,
                COUNT(DISTINCT period_date) AS mois
         FROM financial_entries
         WHERE project_id IN ($in) AND period_date BETWEEN :d AND :f"
    );
    $stmt->execute(['d' => sprintf('%04d-01-01', $annee), 'f' => $fin]);
    $r = $stmt->fetch();
    return ['revenus' => (float) $r['revenus'], 'charges' => (float) $r['charges'], 'mois' => (int) $r['mois']];
}
