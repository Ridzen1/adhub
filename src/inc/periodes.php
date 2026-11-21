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
 * jour de la semaine, au prix d'un décalage d'un ou deux jours dans le calendrier.
 *
 * La règle vaut pour TOUTES les périodes (jour, semaine, mois, année) : chaque
 * jour de la période est comparé au jour situé 364 jours plus tôt. Le mois de
 * novembre 2026 (1er → 30) est ainsi comparé au 2 novembre → 1er décembre 2025 :
 * même nombre de lundis, de vendredis, de dimanches des deux côtés.
 *
 * Les jours qui ont un CA cette année mais aucune donnée l'an dernier (fermeture,
 * jour férié, historique non chargé) sont comptés et signalés à l'écran.
 */

const DECALAGE_N1_JOURS = 364;

const JOURS_COURTS_FR = [1 => 'lun.', 2 => 'mar.', 3 => 'mer.', 4 => 'jeu.', 5 => 'ven.', 6 => 'sam.', 7 => 'dim.'];
const MOIS_FR = [1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];

/**
 * Date de référence N-1 : même jour de la semaine, 52 semaines plus tôt.
 */
function date_n1(string $date): string
{
    return (new DateTimeImmutable($date))->modify('-' . DECALAGE_N1_JOURS . ' days')->format('Y-m-d');
}

/**
 * « ven. 20/11 » : le jour de la semaine rend l'alignement visible.
 */
function libelle_jour(string $date, bool $avecAnnee = false): string
{
    $d = new DateTimeImmutable($date);
    return JOURS_COURTS_FR[(int) $d->format('N')] . ' ' . $d->format($avecAnnee ? 'd/m/Y' : 'd/m');
}

/**
 * CA par jour sur une période : ['2026-11-20' => 1234.5, ...].
 * Un jour présent à 0 € est traité comme absent (caisse fermée ou non remontée).
 */
function ca_par_jour(int $projectId, string $debut, string $fin): array
{
    $stmt = get_db()->prepare(
        "SELECT entry_date, ca_ttc FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :d AND :f AND ca_ttc > 0"
    );
    $stmt->execute(['pid' => $projectId, 'd' => $debut, 'f' => $fin]);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $out[$r['entry_date']] = (float) $r['ca_ttc'];
    }
    return $out;
}

/**
 * Jours de la période qui ont un CA mais dont le jour N-1 (364 jours plus tôt)
 * n'a aucune donnée. Liste de dates de l'année en cours.
 */
function jours_sans_n1(int $projectId, string $debut, string $fin): array
{
    $actuels = ca_par_jour($projectId, $debut, $fin);
    $n1s     = ca_par_jour($projectId, date_n1($debut), date_n1($fin));
    $manque  = [];
    foreach (array_keys($actuels) as $jour) {
        if (!isset($n1s[date_n1($jour)])) {
            $manque[] = $jour;
        }
    }
    sort($manque);
    return $manque;
}

/**
 * Phrase courte qui signale les jours sans comparaison N-1, ou '' s'il n'y en a pas.
 */
function note_jours_sans_n1(array $jours, int $max = 4): string
{
    $n = count($jours);
    if ($n === 0) {
        return '';
    }
    $exemples = array_map(fn($j) => libelle_jour($j), array_slice($jours, 0, $max));
    return $n . ($n > 1 ? ' jours' : ' jour') . ' sans données l\'an dernier (fermeture, jour férié ou historique absent) : '
         . implode(', ', $exemples) . ($n > $max ? '…' : '') . '.';
}

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
            // Semaine en cours, du lundi à hier (dernier jour complet ; la caisse
            // du jour n'est remontée que la nuit, l'inclure fausserait l'écart N-1)
            $debut = $ref->modify('monday this week');
            $fin   = max($debut, $ref->modify('-1 day'));
            $libelle = 'Semaine du ' . $debut->format('d/m') . ' au ' . $fin->format('d/m/Y');
            break;

        case 'mois':
        default:
            $debut = $ref->modify('first day of this month');
            $fin   = max($debut, $ref->modify('-1 day'));
            $libelle = 'Mois en cours au ' . $fin->format('d/m/Y');
            break;
    }

    // Période de comparaison : même longueur, mêmes jours de la semaine (52 semaines plus tôt)
    $n1Debut = $debut->modify('-' . DECALAGE_N1_JOURS . ' days');
    $n1Fin   = $fin->modify('-' . DECALAGE_N1_JOURS . ' days');

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
        // Le lundi ou le 1er du mois, aucun jour n'est encore complet : pas d'écart affiché.
        'n1_disponible' => $n1['jours_saisis'] > 0 && $b['fin'] < date('Y-m-d'),
        'jours_sans_n1' => jours_sans_n1($projectId, $b['debut'], $b['fin']),
    ];
}

/**
 * Série jour par jour sur une période, avec la valeur N-1 en regard.
 * Chaque jour est mis en face du même jour de la semaine, 364 jours plus tôt.
 * Les jours sans saisie sont conservés à 0 pour ne pas déformer le graphique ;
 * 'n1_manquant' dit si le jour N-1 n'a aucune donnée.
 */
function serie_comparee(int $projectId, string $debut, string $fin, ?string $n1Debut = null): array
{
    $n1Debut ??= date_n1($debut);
    $nbJours = (new DateTimeImmutable($debut))->diff(new DateTimeImmutable($fin))->days;
    $n1Fin   = (new DateTimeImmutable($n1Debut))->modify("+{$nbJours} days")->format('Y-m-d');

    $actuels = ca_par_jour($projectId, $debut, $fin);
    $n1s     = ca_par_jour($projectId, $n1Debut, $n1Fin);

    $serie = [];
    $curseur   = new DateTimeImmutable($debut);
    $curseurN1 = new DateTimeImmutable($n1Debut);
    $finDate   = new DateTimeImmutable($fin);

    while ($curseur <= $finDate) {
        $jour   = $curseur->format('Y-m-d');
        $jourN1 = $curseurN1->format('Y-m-d');
        $serie[] = [
            'date'        => $jour,
            'libelle'     => $curseur->format('d/m'),
            'jour'        => libelle_jour($jour),
            'date_n1'     => $jourN1,
            'jour_n1'     => libelle_jour($jourN1, true),
            'ca'          => $actuels[$jour] ?? 0.0,
            'ca_n1'       => $n1s[$jourN1] ?? 0.0,
            'n1_manquant' => !isset($n1s[$jourN1]),
        ];
        $curseur   = $curseur->modify('+1 day');
        $curseurN1 = $curseurN1->modify('+1 day');
    }

    return $serie;
}

/**
 * Comparaison d'un ensemble de périodes (mois, semaines) à leur équivalent N-1.
 * $periodes : liste de ['cle', 'libelle', 'debut', 'fin'] ; une période qui
 * dépasse $jusquau est tronquée à cette date (des deux côtés), une période
 * qui commence après est ignorée.
 */
function comparer_periodes(int $projectId, array $periodes, string $jusquau): array
{
    if (!$periodes) {
        return [];
    }
    $min = min(array_column($periodes, 'debut'));
    $max = min($jusquau, max(array_column($periodes, 'fin')));
    $actuels = ca_par_jour($projectId, $min, $max);
    $n1s     = ca_par_jour($projectId, date_n1($min), date_n1($max));

    $out = [];
    foreach ($periodes as $p) {
        if ($p['debut'] > $jusquau) {
            continue;
        }
        $fin = min($p['fin'], $jusquau);
        $ca = $caN1 = 0.0;
        $sansN1 = [];
        for ($j = new DateTimeImmutable($p['debut']); $j->format('Y-m-d') <= $fin; $j = $j->modify('+1 day')) {
            $jour = $j->format('Y-m-d');
            $jn1  = $j->modify('-' . DECALAGE_N1_JOURS . ' days')->format('Y-m-d');
            $ca   += $actuels[$jour] ?? 0.0;
            $caN1 += $n1s[$jn1] ?? 0.0;
            if (isset($actuels[$jour]) && !isset($n1s[$jn1])) {
                $sansN1[] = $jour;
            }
        }
        $out[] = $p + [
            'fin_calcul'    => $fin,
            'en_cours'      => $fin < $p['fin'],
            'n1_debut'      => date_n1($p['debut']),
            'n1_fin'        => date_n1($fin),
            'ca'            => $ca,
            'ca_n1'         => $caN1,
            'var'           => variation($ca, $caN1),
            'jours_sans_n1' => $sansN1,
        ];
    }
    return $out;
}

/**
 * Les mois d'une année, chacun comparé aux mêmes jours de la semaine N-1.
 */
function comparaison_mois_annee(int $projectId, int $annee, ?string $jusquau = null): array
{
    $periodes = [];
    for ($m = 1; $m <= 12; $m++) {
        $d = new DateTimeImmutable(sprintf('%04d-%02d-01', $annee, $m));
        $periodes[] = [
            'cle'     => $d->format('Y-m'),
            'libelle' => ucfirst(MOIS_FR[$m]),
            'debut'   => $d->format('Y-m-d'),
            'fin'     => $d->format('Y-m-t'),
        ];
    }
    return comparer_periodes($projectId, $periodes, $jusquau ?? (new DateTimeImmutable('yesterday'))->format('Y-m-d'));
}

/**
 * Les $nb dernières semaines (lundi → dimanche), la semaine en cours comprise,
 * chacune comparée à la semaine de même rang 52 semaines plus tôt.
 */
function comparaison_semaines(int $projectId, int $nb = 12, ?DateTimeImmutable $aujourdhui = null): array
{
    $aujourdhui ??= new DateTimeImmutable('today');
    $lundi = $aujourdhui->modify('monday this week');
    $periodes = [];
    for ($i = $nb - 1; $i >= 0; $i--) {
        $d = $lundi->modify("-{$i} weeks");
        $periodes[] = [
            'cle'     => $d->format('o-\WW'),
            'libelle' => 'S' . $d->format('W') . ' · ' . $d->format('d/m') . ' → ' . $d->modify('+6 days')->format('d/m'),
            'debut'   => $d->format('Y-m-d'),
            'fin'     => $d->modify('+6 days')->format('Y-m-d'),
        ];
    }
    return comparer_periodes($projectId, $periodes, $aujourdhui->modify('-1 day')->format('Y-m-d'));
}
