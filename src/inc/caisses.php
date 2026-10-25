<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/daily.php';

/**
 * Données détaillées des caisses (L'Addition, Popina).
 *
 * Les scripts de cron/caisses/ stockent le détail brut (lignes de ticket, Z de caisse,
 * paiements, TVA). Ce fichier fait deux choses :
 *   1. reporter chaque jour le résumé dans daily_entries, pour que le dashboard, la vue
 *      globale et l'évolution fonctionnent sans changement ;
 *   2. fournir les analyses détaillées de la page « Analyse » (catégories, produits,
 *      heures, salles, serveurs, paiements, TVA, offerts, annulations).
 *
 * Les deux caisses n'exposent pas les mêmes informations :
 *   - Popina : tickets, couverts, serveurs, paiements, TVA, offerts, annulations ;
 *   - L'Addition : lignes de vente seulement (pas de couverts, de serveur ni de paiement).
 * Une analyse sans donnée pour la source du restaurant est simplement masquée.
 */

/**
 * Heure de bascule de la journée de service : une vente à 1h du matin compte pour la
 * veille. L'Addition ne donne que l'heure de vente, d'où ce découpage ; Popina fournit
 * déjà le jour de caisse.
 */
const CAISSE_HEURE_BASCULE = 5;

const CAISSE_SOURCES = ['laddition' => "L'Addition", 'popina' => 'Popina'];

/**
 * Enregistre le lien établissement de caisse → société, depuis config/caisses.local.php.
 */
function caisse_relier(string $source, int $idExterne, int $projectId, ?string $nom = null): void
{
    get_db()->prepare(
        "INSERT INTO caisse_etablissements (source, id_externe, project_id, nom)
         VALUES (:s, :id, :pid, :nom)
         ON DUPLICATE KEY UPDATE project_id = :pid2, nom = COALESCE(:nom2, nom)"
    )->execute(['s' => $source, 'id' => $idExterne, 'pid' => $projectId, 'nom' => $nom,
                'pid2' => $projectId, 'nom2' => $nom]);
}

function caisse_projet(string $source, int $idExterne): ?int
{
    $stmt = get_db()->prepare(
        "SELECT project_id FROM caisse_etablissements WHERE source = :s AND id_externe = :id"
    );
    $stmt->execute(['s' => $source, 'id' => $idExterne]);
    $pid = $stmt->fetchColumn();
    return $pid === false ? null : (int) $pid;
}

/** Établissements de caisse reliés à une société. */
function caisse_etablissements_projet(int $projectId): array
{
    $stmt = get_db()->prepare(
        "SELECT source, id_externe, nom FROM caisse_etablissements WHERE project_id = :pid"
    );
    $stmt->execute(['pid' => $projectId]);
    return $stmt->fetchAll();
}

function caisse_sources_projet(int $projectId): array
{
    return array_values(array_unique(array_column(caisse_etablissements_projet($projectId), 'source')));
}

// ---------------------------------------------------------------------------
// Vue unifiée des articles vendus
// ---------------------------------------------------------------------------

/**
 * Sous-requête qui ramène les articles des deux caisses au même format :
 * jour, heure, categorie, sous_categorie, produit, quantite, ttc, ht, offert, salle,
 * serveur, ticket, source.
 *
 * Les paramètres nommés ne pouvant pas être réutilisés (requêtes préparées natives),
 * chaque branche a les siens : renvoyer aussi le tableau de paramètres.
 */
function caisse_articles_sql(int $projectId, string $du, string $au): array
{
    $b = CAISSE_HEURE_BASCULE;
    $sql = "(
        SELECT pl.jour_caisse AS jour, HOUR(pl.date_ligne) AS heure,
               COALESCE(pl.categorie_parent, '(sans catégorie)') AS categorie,
               pl.categorie AS sous_categorie, pl.nom AS produit,
               COALESCE(pl.quantite, 1) AS quantite, COALESCE(pl.net_ttc, 0) AS ttc,
               COALESCE(pl.ht, 0) AS ht, COALESCE(pl.offert_ttc, 0) AS offert,
               pl.salle, pl.serveur, pl.ticket_id AS ticket, 'popina' AS source
        FROM popina_lignes pl
        JOIN caisse_etablissements ce
          ON ce.source = 'popina' AND ce.id_externe = pl.id_etablissement
        WHERE ce.project_id = :pid_p AND pl.jour_caisse BETWEEN :du_p AND :au_p
          AND pl.type_ligne = 'article' AND pl.annule = 0
        UNION ALL
        SELECT DATE(lo.heure_vente - INTERVAL {$b} HOUR) AS jour, HOUR(lo.heure_vente) AS heure,
               COALESCE(lo.categorie_produit, '(sans catégorie)') AS categorie,
               lo.type_produit AS sous_categorie, lo.nom_produit AS produit,
               1 AS quantite, COALESCE(lo.ttc, 0) AS ttc, COALESCE(lo.ht, 0) AS ht, 0 AS offert,
               lo.nom_salle AS salle, NULL AS serveur, NULL AS ticket, 'laddition' AS source
        FROM laddition_order_lines lo
        JOIN caisse_etablissements ce
          ON ce.source = 'laddition' AND ce.id_externe = lo.id_company
        WHERE ce.project_id = :pid_l
          AND lo.heure_vente >= :du_l + INTERVAL {$b} HOUR
          AND lo.heure_vente <  :au_l + INTERVAL 1 DAY + INTERVAL {$b} HOUR
    ) AS v";

    return [$sql, [
        'pid_p' => $projectId, 'du_p' => $du, 'au_p' => $au,
        'pid_l' => $projectId, 'du_l' => $du, 'au_l' => $au,
    ]];
}

function caisse_requete(string $sql, array $params): array
{
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------------------
// Report quotidien vers daily_entries
// ---------------------------------------------------------------------------

/**
 * Résumé d'une journée pour une société, toutes caisses confondues.
 * Renvoie null si aucune caisse n'a de donnée ce jour-là (fermé ou non clôturé :
 * l'outil ne devine pas, il n'écrit rien).
 */
function caisse_resume_jour(int $projectId, string $jour): ?array
{
    $etabs = caisse_etablissements_projet($projectId);
    if (!$etabs) {
        return null;
    }

    $ttc = $ht = $remises = 0.0;
    $couverts = $commandes = 0;
    $aDesDonnees = false;
    $avecCouverts = false;

    // Popina : le Z de caisse fait foi pour les totaux
    $z = caisse_requete(
        "SELECT COUNT(*) AS n, SUM(pc.net_ttc) AS ttc, SUM(pc.net_ht) AS ht,
                SUM(pc.reductions_ttc) AS remises, SUM(pc.couverts) AS couverts,
                SUM(pc.commandes) AS commandes
         FROM popina_caisses pc
         JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pc.id_etablissement
         WHERE ce.project_id = :pid AND pc.jour_caisse = :jour",
        ['pid' => $projectId, 'jour' => $jour]
    )[0];
    if ((int) $z['n'] > 0) {
        $aDesDonnees = $avecCouverts = true;
        $ttc += (float) $z['ttc'];
        $ht += (float) $z['ht'];
        $remises += (float) $z['remises'];
        $couverts += (int) $z['couverts'];
        $commandes += (int) $z['commandes'];
    }

    // L'Addition : somme des lignes de la journée de service
    $b = CAISSE_HEURE_BASCULE;
    $l = caisse_requete(
        "SELECT COUNT(*) AS n, SUM(lo.ttc) AS ttc, SUM(lo.ht) AS ht
         FROM laddition_order_lines lo
         JOIN caisse_etablissements ce ON ce.source = 'laddition' AND ce.id_externe = lo.id_company
         WHERE ce.project_id = :pid
           AND lo.heure_vente >= :j1 + INTERVAL {$b} HOUR
           AND lo.heure_vente <  :j2 + INTERVAL 1 DAY + INTERVAL {$b} HOUR",
        ['pid' => $projectId, 'j1' => $jour, 'j2' => $jour]
    )[0];
    if ((int) $l['n'] > 0) {
        $aDesDonnees = true;
        $ttc += (float) $l['ttc'];
        $ht += (float) $l['ht'];
    }

    if (!$aDesDonnees) {
        return null;
    }

    // Répartition solide / liquide, à partir des articles
    [$v, $p] = caisse_articles_sql($projectId, $jour, $jour);
    $solide = $liquide = 0.0;
    foreach (caisse_requete(
        "SELECT v.source, v.categorie, SUM(v.ttc) AS ttc
         FROM {$v} GROUP BY v.source, v.categorie", $p
    ) as $r) {
        $famille = caisse_famille($r['source'], $r['categorie']);
        if ($famille === 'solide') {
            $solide += (float) $r['ttc'];
        } elseif ($famille === 'liquide') {
            $liquide += (float) $r['ttc'];
        }
    }

    return [
        'ca_ttc'   => round($ttc, 2),
        'couverts' => $couverts,
        'extra'    => [
            'ca_ht'        => $ht > 0 ? round($ht, 2) : null,
            'remises'      => $remises > 0 ? round($remises, 2) : null,
            // Popina compte les offerts dans les réductions du Z : les reporter aussi
            // ici les compterait deux fois. Le détail ligne à ligne reste dans l'analyse.
            'offerts'      => null,
            'ticket_moyen' => $commandes > 0 ? round($ttc / $commandes, 2) : null,
            'ca_solide'    => $solide > 0 ? round($solide, 2) : null,
            'ca_liquide'   => $liquide > 0 ? round($liquide, 2) : null,
        ],
        'couverts_connus' => $avecCouverts,
    ];
}

/**
 * Écrit le résumé du jour dans daily_entries. Une journée saisie à la main ou importée
 * d'un courriel est conservée, sauf $force.
 * Renvoie un message court pour le journal du cron.
 */
function caisse_maj_saisie(int $projectId, string $jour, bool $force = false): string
{
    $resume = caisse_resume_jour($projectId, $jour);
    if ($resume === null) {
        return "aucune vente clôturée, rien écrit";
    }

    $existante = get_daily_entry($projectId, $jour);
    if ($existante && $existante['source'] !== 'api' && !$force) {
        return "déjà saisi ({$existante['source']}), conservé";
    }

    $notes = $resume['couverts_connus'] ? 'Import caisse' : 'Import caisse (couverts non fournis par la caisse)';
    upsert_daily_entry($projectId, $jour, $resume['ca_ttc'], $resume['couverts'], $notes, 'api', null, $resume['extra']);

    $msg = sprintf('CA %.2f €, %d couverts', $resume['ca_ttc'], $resume['couverts']);
    if ($code = detecter_anomalie($projectId, $jour, $resume['ca_ttc'])) {
        $msg .= ' — ATTENTION : ' . libelle_anomalie($code);
    }
    return $msg;
}

// ---------------------------------------------------------------------------
// Familles de catégories (solide / liquide / autre)
// ---------------------------------------------------------------------------

function caisse_familles(): array
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (get_db()->query("SELECT source, categorie, famille FROM caisse_familles") as $r) {
            $cache[$r['source']][mb_strtolower($r['categorie'])] = $r['famille'];
        }
    }
    return $cache;
}

/** 'solide', 'liquide', 'autre', ou null si la catégorie n'est pas encore classée. */
function caisse_famille(string $source, ?string $categorie): ?string
{
    return caisse_familles()[$source][mb_strtolower((string) $categorie)] ?? null;
}

function caisse_definir_famille(string $source, string $categorie, string $famille): void
{
    if (!in_array($famille, ['solide', 'liquide', 'autre'], true) || !isset(CAISSE_SOURCES[$source])) {
        throw new InvalidArgumentException('Famille ou source inconnue');
    }
    get_db()->prepare(
        "INSERT INTO caisse_familles (source, categorie, famille) VALUES (:s, :c, :f)
         ON DUPLICATE KEY UPDATE famille = :f2"
    )->execute(['s' => $source, 'c' => $categorie, 'f' => $famille, 'f2' => $famille]);
}

/** Toutes les catégories vues dans les caisses, avec leur famille (null = à classer). */
function caisse_categories_vues(): array
{
    $rows = get_db()->query(
        "SELECT 'popina' AS source, categorie_parent AS categorie, SUM(net_ttc) AS ttc, MAX(jour_caisse) AS vu_le
         FROM popina_lignes WHERE type_ligne = 'article' AND categorie_parent IS NOT NULL
         GROUP BY categorie_parent
         UNION ALL
         SELECT 'laddition', categorie_produit, SUM(ttc), DATE(MAX(heure_vente))
         FROM laddition_order_lines WHERE categorie_produit IS NOT NULL
         GROUP BY categorie_produit
         ORDER BY source, categorie"
    )->fetchAll();
    foreach ($rows as &$r) {
        $r['famille'] = caisse_famille($r['source'], $r['categorie']);
    }
    return $rows;
}

// ---------------------------------------------------------------------------
// Analyses pour la page « Analyse »
// ---------------------------------------------------------------------------

/**
 * Indicateurs de la période, avec la même période un an plus tôt (364 jours :
 * même jour de la semaine, comme la page Évolution).
 */
function analyse_kpis(int $projectId, string $du, string $au): array
{
    $somme = function (string $d, string $a) use ($projectId) {
        return caisse_requete(
            "SELECT COUNT(*) AS jours, COALESCE(SUM(ca_ttc),0) AS ca_ttc, COALESCE(SUM(ca_ht),0) AS ca_ht,
                    COALESCE(SUM(couverts),0) AS couverts, COALESCE(SUM(remises),0) AS remises,
                    COALESCE(SUM(offerts),0) AS offerts, COALESCE(SUM(ca_solide),0) AS ca_solide,
                    COALESCE(SUM(ca_liquide),0) AS ca_liquide,
                    COALESCE(SUM(IF(couverts > 0, ca_ttc, 0)),0) AS ca_avec_couverts
             FROM daily_entries WHERE project_id = :pid AND entry_date BETWEEN :du AND :au",
            ['pid' => $projectId, 'du' => $d, 'au' => $a]
        )[0];
    };

    $k = $somme($du, $au);
    $n1du = (new DateTimeImmutable($du))->modify('-364 days')->format('Y-m-d');
    $n1au = (new DateTimeImmutable($au))->modify('-364 days')->format('Y-m-d');
    $n1 = $somme($n1du, $n1au);

    // Tickets, annulations et pourboires : Popina uniquement
    $p = caisse_requete(
        "SELECT COALESCE(SUM(pc.commandes),0) AS tickets, COALESCE(SUM(pc.pourboires),0) AS pourboires,
                COUNT(*) AS z
         FROM popina_caisses pc
         JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pc.id_etablissement
         WHERE ce.project_id = :pid AND pc.jour_caisse BETWEEN :du AND :au",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    )[0];
    $annul = caisse_requete(
        "SELECT COUNT(*) AS n, COALESCE(SUM(pl.brut_ttc),0) AS ttc
         FROM popina_lignes pl
         JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pl.id_etablissement
         WHERE ce.project_id = :pid AND pl.jour_caisse BETWEEN :du AND :au
           AND pl.type_ligne = 'article' AND pl.annule = 1",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    )[0];

    $ca = (float) $k['ca_ttc'];
    return [
        'jours'        => (int) $k['jours'],
        'ca_ttc'       => $ca,
        'ca_ht'        => (float) $k['ca_ht'],
        'couverts'     => (int) $k['couverts'],
        // Seulement sur les jours où les couverts sont connus (L'Addition ne les fournit pas)
        'panier_moyen' => ticket_moyen((float) $k['ca_avec_couverts'], (int) $k['couverts']),
        'tickets'      => (int) $p['tickets'],
        'ticket_moyen' => (int) $p['tickets'] > 0 ? $ca / (int) $p['tickets'] : null,
        'ca_par_jour'  => (int) $k['jours'] > 0 ? $ca / (int) $k['jours'] : null,
        'remises'      => (float) $k['remises'],
        'offerts'      => (float) $k['offerts'],
        'pourboires'   => (float) $p['pourboires'],
        'annulations'  => (int) $annul['n'],
        'annulations_ttc' => (float) $annul['ttc'],
        'ca_solide'    => (float) $k['ca_solide'],
        'ca_liquide'   => (float) $k['ca_liquide'],
        'n1_ca_ttc'    => (int) $n1['jours'] > 0 ? (float) $n1['ca_ttc'] : null,
        'n1_couverts'  => (int) $n1['jours'] > 0 ? (int) $n1['couverts'] : null,
        'n1_du'        => $n1du,
        'n1_au'        => $n1au,
    ];
}

function analyse_par_jour(int $projectId, string $du, string $au): array
{
    return caisse_requete(
        "SELECT entry_date AS jour, ca_ttc, couverts, ca_solide, ca_liquide, source
         FROM daily_entries WHERE project_id = :pid AND entry_date BETWEEN :du AND :au
         ORDER BY entry_date",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    );
}

/** CA moyen par jour de la semaine (1 = lundi … 7 = dimanche). */
function analyse_par_jour_semaine(int $projectId, string $du, string $au): array
{
    return caisse_requete(
        "SELECT WEEKDAY(entry_date) + 1 AS jour_semaine, COUNT(*) AS jours,
                AVG(ca_ttc) AS ca_moyen, AVG(couverts) AS couverts_moyen
         FROM daily_entries WHERE project_id = :pid AND entry_date BETWEEN :du AND :au AND ca_ttc > 0
         GROUP BY jour_semaine ORDER BY jour_semaine",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    );
}

function analyse_par_heure(int $projectId, string $du, string $au): array
{
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    return caisse_requete(
        "SELECT v.heure, SUM(v.ttc) AS ttc, SUM(v.quantite) AS quantite, COUNT(DISTINCT v.ticket) AS tickets
         FROM {$v} WHERE v.heure IS NOT NULL GROUP BY v.heure ORDER BY v.heure", $p
    );
}

/** Midi (avant 16h) / soir, pour comparer les deux services. */
function analyse_services(int $projectId, string $du, string $au): array
{
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    return caisse_requete(
        "SELECT CASE WHEN v.heure >= " . CAISSE_HEURE_BASCULE . " AND v.heure < 16 THEN 'Midi' ELSE 'Soir' END AS service,
                SUM(v.ttc) AS ttc, COUNT(DISTINCT v.ticket) AS tickets, COUNT(DISTINCT v.jour) AS jours
         FROM {$v} WHERE v.heure IS NOT NULL GROUP BY service ORDER BY service", $p
    );
}

function analyse_par_categorie(int $projectId, string $du, string $au): array
{
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    $rows = caisse_requete(
        "SELECT v.source, v.categorie, SUM(v.ttc) AS ttc, SUM(v.ht) AS ht, SUM(v.quantite) AS quantite
         FROM {$v} GROUP BY v.source, v.categorie ORDER BY ttc DESC", $p
    );
    foreach ($rows as &$r) {
        $r['famille'] = caisse_famille($r['source'], $r['categorie']);
    }
    return $rows;
}

/** Produits classés par CA ($ordre = 'DESC' pour les meilleurs, 'ASC' pour les moins vendus). */
function analyse_produits(int $projectId, string $du, string $au, int $limite = 15, string $ordre = 'DESC'): array
{
    $limite = max(1, min(100, $limite));
    $ordre = $ordre === 'ASC' ? 'ASC' : 'DESC';
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    return caisse_requete(
        "SELECT v.produit, MIN(v.categorie) AS categorie, SUM(v.quantite) AS quantite,
                SUM(v.ttc) AS ttc, SUM(v.ttc) / NULLIF(SUM(v.quantite), 0) AS prix_moyen
         FROM {$v} WHERE v.produit IS NOT NULL AND v.ttc > 0
         GROUP BY v.produit ORDER BY ttc {$ordre}, quantite {$ordre} LIMIT {$limite}", $p
    );
}

function analyse_par_salle(int $projectId, string $du, string $au): array
{
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    return caisse_requete(
        "SELECT COALESCE(v.salle, '(non précisée)') AS salle, SUM(v.ttc) AS ttc, COUNT(DISTINCT v.ticket) AS tickets
         FROM {$v} GROUP BY salle ORDER BY ttc DESC", $p
    );
}

/** Popina uniquement : L'Addition ne transmet pas le serveur. */
function analyse_par_serveur(int $projectId, string $du, string $au): array
{
    [$v, $p] = caisse_articles_sql($projectId, $du, $au);
    return caisse_requete(
        "SELECT v.serveur, SUM(v.ttc) AS ttc, COUNT(DISTINCT v.ticket) AS tickets,
                SUM(v.ttc) / NULLIF(COUNT(DISTINCT v.ticket), 0) AS ticket_moyen, SUM(v.offert) AS offert
         FROM {$v} WHERE v.serveur IS NOT NULL GROUP BY v.serveur ORDER BY ttc DESC", $p
    );
}

/** Popina uniquement. */
function analyse_paiements(int $projectId, string $du, string $au): array
{
    return caisse_requete(
        "SELECT pp.mode, SUM(pp.montant) AS montant
         FROM popina_caisses_paiements pp
         JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pp.id_etablissement
         WHERE ce.project_id = :pid AND pp.jour_caisse BETWEEN :du AND :au
         GROUP BY pp.mode HAVING montant <> 0 ORDER BY montant DESC",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    );
}

/** TVA collectée par taux, toutes caisses. */
function analyse_tva(int $projectId, string $du, string $au): array
{
    $b = CAISSE_HEURE_BASCULE;
    return caisse_requete(
        "SELECT taux, SUM(ttc) AS ttc, SUM(ht) AS ht, SUM(ttc) - SUM(ht) AS tva FROM (
            SELECT pl.taux_tva AS taux, pl.net_ttc AS ttc, pl.ht AS ht
            FROM popina_lignes pl
            JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pl.id_etablissement
            WHERE ce.project_id = :pid_p AND pl.jour_caisse BETWEEN :du_p AND :au_p
              AND pl.type_ligne = 'article' AND pl.annule = 0
            UNION ALL
            SELECT lo.taux_tva * 100, lo.ttc, lo.ht
            FROM laddition_order_lines lo
            JOIN caisse_etablissements ce ON ce.source = 'laddition' AND ce.id_externe = lo.id_company
            WHERE ce.project_id = :pid_l
              AND lo.heure_vente >= :du_l + INTERVAL {$b} HOUR
              AND lo.heure_vente <  :au_l + INTERVAL 1 DAY + INTERVAL {$b} HOUR
         ) t WHERE taux IS NOT NULL GROUP BY taux ORDER BY taux",
        ['pid_p' => $projectId, 'du_p' => $du, 'au_p' => $au,
         'pid_l' => $projectId, 'du_l' => $du, 'au_l' => $au]
    );
}

/** Offerts et annulations ligne par ligne (Popina), les plus gros en premier. */
function analyse_offerts_annulations(int $projectId, string $du, string $au, int $limite = 30): array
{
    $limite = max(1, min(200, $limite));
    return caisse_requete(
        "SELECT pl.jour_caisse AS jour, pl.date_ligne, pl.serveur, pl.table_nom, pl.nom AS produit,
                pl.quantite, pl.brut_ttc, pl.offert_ttc, pl.annule
         FROM popina_lignes pl
         JOIN caisse_etablissements ce ON ce.source = 'popina' AND ce.id_externe = pl.id_etablissement
         WHERE ce.project_id = :pid AND pl.jour_caisse BETWEEN :du AND :au
           AND pl.type_ligne = 'article' AND (pl.annule = 1 OR pl.offert_ttc > 0)
         ORDER BY GREATEST(COALESCE(pl.offert_ttc,0), IF(pl.annule = 1, COALESCE(pl.brut_ttc,0), 0)) DESC
         LIMIT {$limite}",
        ['pid' => $projectId, 'du' => $du, 'au' => $au]
    );
}

/** Dernières synchronisations, pour repérer une caisse qui ne remonte plus. */
function caisse_dernieres_synchros(int $limite = 50): array
{
    $limite = max(1, min(500, $limite));
    return get_db()->query(
        "SELECT * FROM sync_runs ORDER BY run_at DESC, id DESC LIMIT {$limite}"
    )->fetchAll();
}
