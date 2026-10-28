<?php

/**
 * Objectifs de chiffre d'affaires des restaurants (migration 016).
 *
 * La direction fixe un CA TTC mensuel par restaurant ; la progression se calcule
 * à partir des ventes remontées par les caisses, sans aucune saisie des équipes.
 * Un restaurant n'est jamais comparé qu'à son propre objectif : pas de classement.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/periodes.php';
require_once __DIR__ . '/ui.php';

/**
 * Paliers de progression, du plus haut au plus bas : pourcentage => libellé.
 * Le navigateur fête chaque nouveau palier franchi (animation à 100 % et plus).
 */
const PALIERS_OBJECTIF = [
    120 => 'Objectif pulvérisé',
    100 => 'Objectif atteint',
    75  => 'Dernière ligne droite',
    50  => 'Mi-parcours',
];

/** Faux tant que la migration 016 n'est pas passée : les pages s'affichent sans objectifs. */
function objectifs_disponibles(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            get_db()->query("SELECT 1 FROM objectifs_ca LIMIT 1");
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Objectifs d'un restaurant entre deux mois inclus : ['2026-10' => montant] */
function objectifs_du_projet(int $projectId, string $du, string $au): array
{
    if (!objectifs_disponibles()) {
        return [];
    }
    $st = get_db()->prepare(
        "SELECT DATE_FORMAT(mois, '%Y-%m') AS m, montant FROM objectifs_ca
         WHERE project_id = :pid AND mois BETWEEN :du AND :au"
    );
    $st->execute(['pid' => $projectId, 'du' => substr($du, 0, 7) . '-01', 'au' => substr($au, 0, 7) . '-01']);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['m']] = (float) $r['montant'];
    }
    return $out;
}

/** CA TTC par mois : ['2026-10' => ca] (mois sans vente absents) */
function ca_par_mois(int $projectId, string $du, string $au): array
{
    $st = get_db()->prepare(
        "SELECT DATE_FORMAT(entry_date, '%Y-%m') AS m, SUM(ca_ttc) AS ca FROM daily_entries
         WHERE project_id = :pid AND entry_date BETWEEN :du AND :au GROUP BY m"
    );
    $st->execute(['pid' => $projectId, 'du' => $du, 'au' => $au]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[$r['m']] = (float) $r['ca'];
    }
    return $out;
}

/**
 * Enregistre les objectifs d'un restaurant : ['2026-10' => montant|null].
 * Un montant vide ou nul supprime l'objectif du mois.
 */
function enregistrer_objectifs(int $projectId, array $parMois, ?int $userId): void
{
    $db  = get_db();
    $set = $db->prepare(
        "INSERT INTO objectifs_ca (project_id, mois, montant, fixe_par) VALUES (:pid, :mois, :montant, :uid)
         ON DUPLICATE KEY UPDATE montant = VALUES(montant), fixe_par = VALUES(fixe_par)"
    );
    $del = $db->prepare("DELETE FROM objectifs_ca WHERE project_id = :pid AND mois = :mois");
    $db->beginTransaction();
    foreach ($parMois as $mois => $montant) {
        if (!preg_match('/^\d{4}-\d{2}$/', (string) $mois)) {
            continue;
        }
        if ($montant === null || $montant <= 0) {
            $del->execute(['pid' => $projectId, 'mois' => $mois . '-01']);
        } else {
            $set->execute(['pid' => $projectId, 'mois' => $mois . '-01', 'montant' => round($montant, 2), 'uid' => $userId]);
        }
    }
    $db->commit();
}

/** Plus haut palier franchi (0 si aucun) */
function palier_objectif(float $pct): int
{
    foreach (PALIERS_OBJECTIF as $seuil => $_) {
        if ($pct >= $seuil) {
            return $seuil;
        }
    }
    return 0;
}

/**
 * Nombre de mois d'affilée où l'objectif a été atteint, en remontant depuis le
 * mois précédent (le mois en cours compte dès qu'il est atteint).
 * $objectifs et $ca : tableaux ['Y-m' => montant].
 */
function serie_objectifs(array $objectifs, array $ca, DateTimeImmutable $moisCourant, bool $courantAtteint): int
{
    $serie = $courantAtteint ? 1 : 0;
    for ($m = $moisCourant->modify('-1 month'); ; $m = $m->modify('-1 month')) {
        $cle = $m->format('Y-m');
        if (!isset($objectifs[$cle]) || ($ca[$cle] ?? 0) < $objectifs[$cle]) {
            return $serie;
        }
        $serie++;
    }
}

/**
 * Où en est le restaurant de son objectif du mois en cours.
 * null si aucun objectif n'est fixé pour ce mois.
 */
function etat_objectif(int $projectId, ?DateTimeImmutable $aujourdhui = null): ?array
{
    $aujourdhui ??= new DateTimeImmutable('today');
    $mois  = $aujourdhui->modify('first day of this month');
    $debut = $mois->modify('-24 months');

    $objectifs = objectifs_du_projet($projectId, $debut->format('Y-m-d'), $mois->format('Y-m-d'));
    $cle = $mois->format('Y-m');
    if (!isset($objectifs[$cle])) {
        return null;
    }
    $ca = ca_par_mois($projectId, $debut->format('Y-m-d'), $mois->modify('last day of this month')->format('Y-m-d'));

    $objectif = $objectifs[$cle];
    $realise  = $ca[$cle] ?? 0.0;
    $pct      = $objectif > 0 ? $realise / $objectif * 100 : 0.0;
    $reste    = max(0.0, $objectif - $realise);

    // Jours restants : aujourd'hui compte tant que sa journée n'est pas remontée
    $joursMois = (int) $aujourdhui->format('t');
    $jourCourant = (int) $aujourdhui->format('j');
    $journeeSaisie = (bool) get_daily_entry($projectId, $aujourdhui->format('Y-m-d'));
    $joursRestants = $joursMois - $jourCourant + ($journeeSaisie ? 0 : 1);

    return [
        'project_id'     => $projectId,
        'cle'            => $cle,
        'mois_label'     => format_mois_fr((int) $mois->format('Y'), (int) $mois->format('n')),
        'objectif'       => $objectif,
        'realise'        => $realise,
        'pct'            => $pct,
        'palier'         => palier_objectif($pct),
        'reste'          => $reste,
        'jours_restants' => $joursRestants,
        'par_jour'       => $reste > 0 && $joursRestants > 0 ? $reste / $joursRestants : null,
        'serie'          => serie_objectifs($objectifs, $ca, $mois, $pct >= 100),
    ];
}

/**
 * Bilan d'une année pour la page Objectifs : un élément par mois avec
 * objectif, CA réalisé, CA du même mois N-1 (pour aider à fixer l'objectif) et état.
 */
function bilan_objectifs_annee(int $projectId, int $annee, ?DateTimeImmutable $aujourdhui = null): array
{
    $aujourdhui ??= new DateTimeImmutable('today');
    $objectifs = objectifs_du_projet($projectId, $annee . '-01-01', $annee . '-12-01');
    $ca   = ca_par_mois($projectId, $annee . '-01-01', $annee . '-12-31');
    $caN1 = ca_par_mois($projectId, ($annee - 1) . '-01-01', ($annee - 1) . '-12-31');
    $moisCourant = $aujourdhui->format('Y-m');

    $out = [];
    for ($m = 1; $m <= 12; $m++) {
        $cle   = sprintf('%04d-%02d', $annee, $m);
        $obj   = $objectifs[$cle] ?? null;
        $real  = $ca[$cle] ?? 0.0;
        $etat  = match (true) {
            $obj === null          => 'aucun',
            $cle > $moisCourant    => 'a_venir',
            $real >= $obj          => 'atteint',
            $cle === $moisCourant  => 'en_cours',
            default                => 'manque',
        };
        $out[$cle] = [
            'mois'     => $m,
            'objectif' => $obj,
            'realise'  => $real,
            'ca_n1'    => $caN1[sprintf('%04d-%02d', $annee - 1, $m)] ?? null,
            'pct'      => $obj ? $real / $obj * 100 : null,
            'etat'     => $etat,
        ];
    }
    return $out;
}

/**
 * Jauge d'objectif. $compact : version courte pour les cartes de la vue d'ensemble.
 * Les attributs data-goal-* servent à l'animation (assets/adhub.js).
 */
function bloc_objectif(array $e, string $nomRestaurant, bool $compact = false): string
{
    $pctAff  = min(100, $e['pct']);
    $palier  = $e['palier'];
    $libelle = $palier ? PALIERS_OBJECTIF[$palier] : null;
    $atteint = $palier >= 100;

    $badge = '';
    if ($atteint) {
        $badge = '<button type="button" class="goal-badge goal-win" data-goal-replay title="Revoir l\'animation">'
               . icon('trophy', 14) . ' ' . h($libelle) . '</button>';
    } elseif ($libelle) {
        $badge = '<span class="goal-badge">' . icon('flag', 13) . ' ' . h($libelle) . '</span>';
    }

    $marques = '';
    foreach ([50, 75] as $seuil) {
        $marques .= '<i class="goal-mark' . ($e['pct'] >= $seuil ? ' passed' : '') . '" style="left:' . $seuil . '%"></i>';
    }

    if ($atteint) {
        $suite = 'Dépassé de <strong>' . eur($e['realise'] - $e['objectif']) . '</strong>';
    } elseif ($e['par_jour'] !== null) {
        $suite = 'Reste <strong>' . eur($e['reste']) . '</strong>, soit ' . eur($e['par_jour']) . ' par jour sur '
               . $e['jours_restants'] . ' jour' . ($e['jours_restants'] > 1 ? 's' : '');
    } else {
        $suite = 'Reste <strong>' . eur($e['reste']) . '</strong>';
    }

    $serie = $e['serie'] >= 2
        ? '<span class="goal-streak" title="Mois consécutifs où l\'objectif a été atteint">' . icon('flame', 13) . ' '
          . $e['serie'] . ' mois d\'affilée</span>'
        : '';

    return '<div class="goal' . ($atteint ? ' is-won' : '') . ($compact ? ' goal-compact' : '') . '" data-goal'
         . ' data-goal-key="' . h($e['project_id'] . ':' . $e['cle']) . '" data-goal-palier="' . $palier . '"'
         . ' data-goal-name="' . h($nomRestaurant) . '">'
         . '<div class="goal-top">'
         . '<span class="kpi-label">' . icon('target', 14) . ' Objectif · ' . h($e['mois_label']) . '</span>'
         . $badge
         . '</div>'
         . '<div class="goal-row">'
         . '<div class="goal-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . round($e['pct']) . '"'
         . ' aria-label="Objectif du mois atteint à ' . round($e['pct']) . ' %">'
         . '<span class="goal-fill" style="--goal-pct:' . round($pctAff, 1) . '%"></span>' . $marques
         . '</div>'
         . '<span class="goal-pct num">' . pourcent($e['pct']) . '</span>'
         . '</div>'
         . '<div class="goal-foot">'
         . '<span>' . eur($e['realise']) . ' sur ' . eur($e['objectif']) . '</span>'
         . ($compact ? '' : '<span>' . $suite . '</span>')
         . $serie
         . '</div>'
         . '</div>';
}
