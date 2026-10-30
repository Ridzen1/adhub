<?php

/**
 * Données Pennylane importées à la main (export Excel déposé par la direction).
 *
 * Aucune connexion à Pennylane : l'outil lit le fichier, met à jour les tables
 * pennylane_* par identifiant Pennylane (réimporter ne crée pas de doublon), puis
 * relie chaque société Pennylane à une société ADHUB.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../cron/caisses/xlsx_reader.php';
require_once __DIR__ . '/financials.php';

/** En-têtes attendus dans chaque feuille (colonne repérée par son titre, pas par sa position) */
const PENNYLANE_FEUILLES = [
    'Factures fournisseurs'  => 'fournisseur',
    'Factures clients'       => 'client',
    'Transactions bancaires' => 'transactions',
];

/* ------------------------------------------------------------------ */
/* Lecture du fichier                                                  */
/* ------------------------------------------------------------------ */

function pl_normaliser_entete(string $s): string
{
    $s = mb_strtolower(trim($s));
    $s = strtr($s, ['é' => 'e', 'è' => 'e', 'ê' => 'e', 'à' => 'a', 'â' => 'a', 'ô' => 'o', 'û' => 'u', 'ç' => 'c', '°' => '']);
    return preg_replace('/[^a-z0-9]+/', ' ', $s);
}

/** Tableau de lignes → liste de [en-tête normalisé => valeur] (la 1re ligne non vide contenant « societe » sert d'en-tête) */
function pl_lignes_nommees(array $rows): array
{
    $entete = null;
    $out = [];
    foreach ($rows as $r) {
        if ($entete === null) {
            if (in_array('societe', array_map(fn($c) => pl_normaliser_entete((string) $c), $r), true)) {
                $entete = array_map(fn($c) => pl_normaliser_entete((string) $c), $r);
            }
            continue;
        }
        if (!array_filter($r, fn($c) => $c !== null && $c !== '')) {
            continue;
        }
        $ligne = [];
        foreach ($entete as $i => $nom) {
            if ($nom !== '') {
                $ligne[$nom] = $r[$i] ?? null;
            }
        }
        $out[] = $ligne;
    }
    return $out;
}

function pl_nombre($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $v = str_replace(["\u{202F}", "\u{00A0}", ' ', '€'], '', (string) $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float) $v : null;
}

function pl_oui($v): ?int
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_bool($v)) {
        return $v ? 1 : 0;
    }
    return in_array(mb_strtolower(trim((string) $v)), ['oui', 'yes', 'true', '1', 'vrai'], true) ? 1 : 0;
}

function pl_texte($v, int $max = 255): ?string
{
    if ($v === null) {
        return null;
    }
    $v = trim((string) $v);
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/**
 * Importe un export Excel Pennylane. Retourne un résumé, ou lève une exception
 * si le fichier n'a pas la forme attendue (aucune écriture dans ce cas).
 */
function pennylane_importer_xlsx(string $binaire, string $nomFichier, ?int $userId): array
{
    $feuilles = xlsxSheets($binaire);
    $trouvees = array_intersect_key($feuilles, PENNYLANE_FEUILLES);
    if (!$trouvees) {
        throw new RuntimeException("Le fichier ne contient aucune des feuilles attendues : « "
            . implode(" », « ", array_keys(PENNYLANE_FEUILLES)) . " ».");
    }

    $pdo = get_db();
    $insFacture = $pdo->prepare(
        "INSERT INTO pennylane_factures
            (id, sens, societe, numero, tiers, libelle, date_facture, echeance, ht, tva, ttc,
             reste_a_payer, statut_paiement, payee, date_paiement, avoir, archivee)
         VALUES (:id, :sens, :societe, :numero, :tiers, :libelle, :date_facture, :echeance, :ht, :tva, :ttc,
             :reste, :statut, :payee, :date_paiement, :avoir, 0)
         ON DUPLICATE KEY UPDATE sens = VALUES(sens), societe = VALUES(societe), numero = VALUES(numero),
             tiers = VALUES(tiers), libelle = VALUES(libelle), date_facture = VALUES(date_facture),
             echeance = VALUES(echeance), ht = VALUES(ht), tva = VALUES(tva), ttc = VALUES(ttc),
             reste_a_payer = VALUES(reste_a_payer), statut_paiement = VALUES(statut_paiement),
             payee = VALUES(payee), date_paiement = VALUES(date_paiement), avoir = VALUES(avoir)"
    );
    $insTransaction = $pdo->prepare(
        "INSERT INTO pennylane_transactions
            (id, societe, date_operation, libelle, montant, devise, compte, tiers, statut, nb_justificatifs, justificatif_requis)
         VALUES (:id, :societe, :date, :libelle, :montant, 'EUR', :compte, :tiers, :statut, :nb, :requis)
         ON DUPLICATE KEY UPDATE societe = VALUES(societe), date_operation = VALUES(date_operation),
             libelle = VALUES(libelle), montant = VALUES(montant), compte = VALUES(compte), tiers = VALUES(tiers),
             statut = VALUES(statut), nb_justificatifs = VALUES(nb_justificatifs), justificatif_requis = VALUES(justificatif_requis)"
    );

    $res = ['factures' => 0, 'transactions' => 0, 'ignorees' => 0, 'societes' => [], 'du' => null, 'au' => null];
    $borne = function (?string $d) use (&$res) {
        if ($d === null) return;
        $res['du'] = $res['du'] === null || $d < $res['du'] ? $d : $res['du'];
        $res['au'] = $res['au'] === null || $d > $res['au'] ? $d : $res['au'];
    };

    $pdo->beginTransaction();
    try {
        foreach ($trouvees as $nomFeuille => $rows) {
            $type = PENNYLANE_FEUILLES[$nomFeuille];
            foreach (pl_lignes_nommees($rows) as $l) {
                $id      = pl_nombre($l['id pennylane'] ?? null);
                $societe = pl_texte($l['societe'] ?? null, 100);
                if (!$id || !$societe) {
                    $res['ignorees']++;
                    continue;
                }
                $res['societes'][$societe] = true;

                if ($type === 'transactions') {
                    $date = xlsxDate($l['date'] ?? null);
                    $montant = pl_nombre($l['montant'] ?? null);
                    if ($montant === null) {
                        $montant = (pl_nombre($l['encaissement'] ?? null) ?? 0) - (pl_nombre($l['decaissement'] ?? null) ?? 0);
                    }
                    if ($date === null) {
                        $res['ignorees']++;
                        continue;
                    }
                    $insTransaction->execute([
                        'id' => (int) $id, 'societe' => $societe, 'date' => $date,
                        'libelle' => pl_texte($l['libelle'] ?? null, 500), 'montant' => $montant,
                        'compte' => pl_texte($l['compte'] ?? null, 100), 'tiers' => pl_texte($l['tiers'] ?? null),
                        'statut' => pl_texte($l['statut'] ?? null, 40),
                        'nb' => ($n = pl_nombre($l['nb justificatifs'] ?? null)) === null ? null : (int) $n,
                        'requis' => pl_oui($l['justificatif requis'] ?? null),
                    ]);
                    $borne($date);
                    $res['transactions']++;
                } else {
                    $date = xlsxDate($l['date'] ?? null);
                    $insFacture->execute([
                        'id' => (int) $id, 'sens' => $type, 'societe' => $societe,
                        'numero' => pl_texte($l['n facture'] ?? null, 100), 'tiers' => pl_texte($l['tiers'] ?? null),
                        'libelle' => pl_texte($l['libelle'] ?? null, 500),
                        'date_facture' => $date, 'echeance' => xlsxDate($l['echeance'] ?? null),
                        'ht' => pl_nombre($l['ht'] ?? null), 'tva' => pl_nombre($l['tva'] ?? null), 'ttc' => pl_nombre($l['ttc'] ?? null),
                        'reste' => pl_nombre($l['reste a payer'] ?? null), 'statut' => pl_texte($l['statut paiement'] ?? null, 40),
                        'payee' => pl_oui($l['payee'] ?? null), 'date_paiement' => xlsxDate($l['date paiement'] ?? null),
                        'avoir' => mb_strtolower((string) ($l['type'] ?? '')) === 'avoir' ? 1 : 0,
                    ]);
                    $borne($date);
                    $res['factures']++;
                }
            }
        }

        $pdo->prepare(
            "INSERT INTO pennylane_imports (fichier, nb_factures, nb_transactions, donnees_du, donnees_au, user_id)
             VALUES (:f, :nf, :nt, :du, :au, :u)"
        )->execute(['f' => mb_substr($nomFichier, 0, 255), 'nf' => $res['factures'], 'nt' => $res['transactions'],
                    'du' => $res['du'], 'au' => $res['au'], 'u' => $userId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    $res['societes'] = array_keys($res['societes']);
    pennylane_relier_automatiquement();
    pennylane_retirer_factures_fournisseurs_restaurants();
    pennylane_alimenter_suivi_mensuel();
    pennylane_maj_tresorerie();
    return $res;
}

/* ------------------------------------------------------------------ */
/* Correspondance des sociétés                                         */
/* ------------------------------------------------------------------ */

/** Mots d'un nom de société, sans formes juridiques ni mots vides */
function pl_mots(string $nom): array
{
    $nom = strtr(mb_strtoupper($nom), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ô' => 'O', 'Ç' => 'C']);
    $mots = preg_split('/[^A-Z0-9]+/', $nom, -1, PREG_SPLIT_NO_EMPTY);
    $vides = ['SCI', 'SAS', 'SASU', 'SARL', 'EURL', 'SA', 'SNC', 'DU', 'DE', 'LA', 'LE', 'LES', 'DES', 'RUE', 'ET'];
    return array_values(array_diff($mots, $vides));
}

/**
 * Société ADHUB la plus proche d'un nom Pennylane, ou null si le doute est trop grand.
 * Exemple : « SCI HALLES-HOTEL-DIEU » → « SCI HOTEL DIEU », « VALENTINO SAS » → « SAS LE VALENTINO ».
 */
function pennylane_suggerer(string $nomPennylane, array $projets): ?int
{
    $cible = pl_mots($nomPennylane);
    $formeP = preg_match('/\bSCI\b/i', $nomPennylane) ? 'SCI' : (preg_match('/\bSAS\b/i', $nomPennylane) ? 'SAS' : null);
    $meilleur = null;
    $score = 0.0;
    foreach ($projets as $p) {
        $mots = pl_mots($p['name']);
        if (!$mots || !$cible) {
            continue;
        }
        $communs = count(array_intersect($cible, $mots));
        if ($communs === 0) {
            continue;
        }
        $s = $communs / max(count($cible), count($mots)) + $communs * 0.1;
        // Même forme juridique (VALENTINO SAS ↔ SAS LE VALENTINO, pas SCI LE VALENTINO)
        $formeA = preg_match('/\bSCI\b/i', $p['name']) ? 'SCI' : (preg_match('/\bSAS\b/i', $p['name']) ? 'SAS' : null);
        if ($formeP && $formeA) {
            $s += $formeP === $formeA ? 0.3 : -0.5;
        }
        if ($s > $score) {
            $score = $s;
            $meilleur = (int) $p['id'];
        }
    }
    // Plusieurs sociétés portent tous les mots du nom (« JAURES » : SCI JAURES et SAS JAURES / CHEZ COME) :
    // on laisse choisir plutôt que de deviner.
    $complets = 0;
    foreach ($projets as $p) {
        $formeA = preg_match('/\bSCI\b/i', $p['name']) ? 'SCI' : (preg_match('/\bSAS\b/i', $p['name']) ? 'SAS' : null);
        if ($formeP && $formeA && $formeP !== $formeA) {
            continue;
        }
        if ($cible && !array_diff($cible, pl_mots($p['name']))) {
            $complets++;
        }
    }
    if ($complets > 1) {
        return null;
    }
    return $score >= 0.5 ? $meilleur : null;
}

/** Enregistre les sociétés Pennylane inconnues, reliées d'office quand le nom est clair */
function pennylane_relier_automatiquement(): void
{
    $pdo = get_db();
    $projets = $pdo->query("SELECT id, name FROM projects WHERE status = 'active'")->fetchAll();
    $noms = $pdo->query(
        "SELECT societe FROM pennylane_factures UNION SELECT societe FROM pennylane_transactions"
        . (pennylane_table_existe('pennylane_comptes') ? " UNION SELECT societe FROM pennylane_comptes" : "")
    )->fetchAll(PDO::FETCH_COLUMN);
    $connus = $pdo->query("SELECT societe FROM pennylane_societes")->fetchAll(PDO::FETCH_COLUMN);
    $ins = $pdo->prepare("INSERT INTO pennylane_societes (societe, project_id) VALUES (:s, :p)");
    foreach (array_diff($noms, $connus) as $nom) {
        $ins->execute(['s' => $nom, 'p' => pennylane_suggerer($nom, $projets)]);
    }
}

function pennylane_correspondances(): array
{
    return get_db()->query(
        "SELECT ps.societe, ps.project_id, p.name AS project_name,
                (SELECT COUNT(*) FROM pennylane_factures f WHERE f.societe = ps.societe) AS nb_factures,
                (SELECT COUNT(*) FROM pennylane_transactions t WHERE t.societe = ps.societe) AS nb_transactions
         FROM pennylane_societes ps
         LEFT JOIN projects p ON p.id = ps.project_id
         ORDER BY ps.societe"
    )->fetchAll();
}

function pennylane_definir_correspondance(string $societe, ?int $projectId): void
{
    get_db()->prepare("UPDATE pennylane_societes SET project_id = :p WHERE societe = :s")
        ->execute(['p' => $projectId ?: null, 's' => $societe]);
}

/**
 * Efface les factures fournisseurs Pennylane des restaurants (choix de la direction :
 * restes à payer et retards ne doivent pas apparaître) et remet à zéro les charges que
 * l'outil en avait tirées dans le suivi mensuel. Une saisie à la main n'est pas touchée.
 * Appelé après chaque import, changement de correspondance, et à l'ouverture des pages
 * concernées (cas du script SQL passé dans phpMyAdmin). Renvoie le nombre de factures effacées.
 */
function pennylane_retirer_factures_fournisseurs_restaurants(): int
{
    $pdo = get_db();
    $restos = "SELECT p.id FROM projects p JOIN project_types pt ON pt.id = p.project_type_id
               WHERE pt.code = 'restauration_exploitation'";
    $n = $pdo->exec(
        "DELETE f FROM pennylane_factures f
         JOIN pennylane_societes ps ON ps.societe = f.societe
         WHERE f.sens = 'fournisseur' AND ps.project_id IN ($restos)"
    );
    $pdo->prepare(
        "UPDATE financial_entries SET charges = 0
         WHERE notes = :auto AND charges <> 0 AND project_id IN ($restos)"
    )->execute(['auto' => PL_SUIVI_AUTO]);
    return $n;
}

/** Noms Pennylane reliés à une société ADHUB */
function pennylane_noms_projet(int $projectId): array
{
    $stmt = get_db()->prepare("SELECT societe FROM pennylane_societes WHERE project_id = :p");
    $stmt->execute(['p' => $projectId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function pennylane_derniers_imports(int $limite = 10): array
{
    return get_db()->query(
        "SELECT i.*, u.name AS user_name FROM pennylane_imports i
         LEFT JOIN users u ON u.id = i.user_id ORDER BY i.imported_at DESC LIMIT " . max(1, $limite)
    )->fetchAll();
}

function pennylane_dernier_import(): ?array
{
    $r = get_db()->query("SELECT * FROM pennylane_imports ORDER BY imported_at DESC LIMIT 1")->fetch();
    return $r ?: null;
}

/* ------------------------------------------------------------------ */
/* Indicateurs                                                         */
/* ------------------------------------------------------------------ */

/** Reste dû signé : un avoir non soldé vient en déduction */
const PL_RESTE = "(CASE WHEN avoir = 1 THEN -reste_a_payer ELSE reste_a_payer END)";

/** Fragment SQL « societe IN (…) » sûr, et ses paramètres */
function pl_in(array $noms, string $prefixe = 's'): array
{
    $marques = [];
    $params = [];
    foreach (array_values($noms) as $i => $n) {
        $marques[] = ':' . $prefixe . $i;
        $params[$prefixe . $i] = $n;
    }
    return [$marques ? implode(',', $marques) : "''", $params];
}

function pl_requete(string $sql, array $params): array
{
    $stmt = get_db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Synthèse d'un ensemble de sociétés Pennylane sur une période */
function pennylane_synthese(array $noms, string $du, string $au): array
{
    [$in, $p] = pl_in($noms);
    $p += ['du' => $du, 'au' => $au];
    $banque = pl_requete(
        "SELECT COALESCE(SUM(CASE WHEN montant > 0 THEN montant END), 0) AS encaissements,
                COALESCE(-SUM(CASE WHEN montant < 0 THEN montant END), 0) AS decaissements,
                COUNT(*) AS nb,
                SUM(statut IN ('À justifier', 'to_be_justified')) AS a_justifier,
                MAX(date_operation) AS derniere
         FROM pennylane_transactions WHERE societe IN ($in) AND date_operation BETWEEN :du AND :au", $p)[0];
    $factures = pl_requete(
        "SELECT sens,
                COUNT(*) AS nb,
                COALESCE(SUM(ht), 0) AS ht,
                COALESCE(SUM(ttc), 0) AS ttc
         FROM pennylane_factures WHERE societe IN ($in) AND date_facture BETWEEN :du AND :au
         GROUP BY sens", $p);
    [$in2, $p2] = pl_in($noms);
    $p2['jour1'] = $p2['jour2'] = date('Y-m-d');
    $restes = pl_requete(
        "SELECT sens,
                COUNT(*) AS nb,
                COALESCE(SUM(" . PL_RESTE . "), 0) AS reste,
                COALESCE(SUM(CASE WHEN echeance < :jour1 THEN " . PL_RESTE . " END), 0) AS echu,
                SUM(echeance < :jour2 AND avoir = 0) AS nb_echues
         FROM pennylane_factures WHERE societe IN ($in2) AND reste_a_payer > 0.009
         GROUP BY sens", $p2);

    $out = [
        'encaissements' => (float) $banque['encaissements'],
        'decaissements' => (float) $banque['decaissements'],
        'nb_transactions' => (int) $banque['nb'],
        'a_justifier' => (int) $banque['a_justifier'],
        'derniere_operation' => $banque['derniere'],
        'fournisseur' => ['nb' => 0, 'ht' => 0.0, 'ttc' => 0.0, 'reste' => 0.0, 'echu' => 0.0, 'nb_reste' => 0, 'nb_echues' => 0],
        'client'      => ['nb' => 0, 'ht' => 0.0, 'ttc' => 0.0, 'reste' => 0.0, 'echu' => 0.0, 'nb_reste' => 0, 'nb_echues' => 0],
    ];
    foreach ($factures as $f) {
        $out[$f['sens']]['nb'] = (int) $f['nb'];
        $out[$f['sens']]['ht'] = (float) $f['ht'];
        $out[$f['sens']]['ttc'] = (float) $f['ttc'];
    }
    foreach ($restes as $r) {
        $out[$r['sens']]['reste'] = (float) $r['reste'];
        $out[$r['sens']]['echu'] = (float) $r['echu'];
        $out[$r['sens']]['nb_reste'] = (int) $r['nb'];
        $out[$r['sens']]['nb_echues'] = (int) $r['nb_echues'];
    }
    return $out;
}

/** Par mois : encaissements, décaissements (banque) et achats HT (factures fournisseurs) */
function pennylane_par_mois(array $noms, string $du, string $au): array
{
    $mois = [];
    for ($d = new DateTimeImmutable(substr($du, 0, 7) . '-01'); $d->format('Y-m-d') <= $au; $d = $d->modify('+1 month')) {
        $mois[$d->format('Y-m')] = ['mois' => $d->format('Y-m'), 'encaissements' => 0.0, 'decaissements' => 0.0, 'achats' => 0.0, 'ventes' => 0.0];
    }
    [$in, $p] = pl_in($noms);
    $p += ['du' => $du, 'au' => $au];
    foreach (pl_requete(
        "SELECT DATE_FORMAT(date_operation, '%Y-%m') AS m,
                SUM(CASE WHEN montant > 0 THEN montant ELSE 0 END) AS enc,
                -SUM(CASE WHEN montant < 0 THEN montant ELSE 0 END) AS dec_
         FROM pennylane_transactions WHERE societe IN ($in) AND date_operation BETWEEN :du AND :au GROUP BY m", $p) as $r) {
        if (isset($mois[$r['m']])) {
            $mois[$r['m']]['encaissements'] = (float) $r['enc'];
            $mois[$r['m']]['decaissements'] = (float) $r['dec_'];
        }
    }
    foreach (pl_requete(
        "SELECT DATE_FORMAT(date_facture, '%Y-%m') AS m, sens, SUM(ht) AS ht
         FROM pennylane_factures WHERE societe IN ($in) AND date_facture BETWEEN :du AND :au GROUP BY m, sens", $p) as $r) {
        if (isset($mois[$r['m']])) {
            $mois[$r['m']][$r['sens'] === 'fournisseur' ? 'achats' : 'ventes'] = (float) $r['ht'];
        }
    }
    return array_values($mois);
}

/** Principaux fournisseurs (TTC des factures, avoirs déduits) */
function pennylane_top_fournisseurs(array $noms, string $du, string $au, int $limite = 10): array
{
    [$in, $p] = pl_in($noms);
    $p += ['du' => $du, 'au' => $au];
    return pl_requete(
        "SELECT COALESCE(tiers, '(sans nom)') AS tiers, COUNT(*) AS nb, SUM(ht) AS ht, SUM(ttc) AS ttc
         FROM pennylane_factures
         WHERE societe IN ($in) AND sens = 'fournisseur' AND date_facture BETWEEN :du AND :au
         GROUP BY tiers ORDER BY ttc DESC LIMIT " . max(1, $limite), $p);
}

/** Factures restant à payer (fournisseurs) ou à encaisser (clients), les plus anciennes échéances d'abord */
function pennylane_factures_ouvertes(array $noms, string $sens, int $limite = 50): array
{
    [$in, $p] = pl_in($noms);
    $p['sens'] = $sens;
    return pl_requete(
        "SELECT * FROM pennylane_factures
         WHERE societe IN ($in) AND sens = :sens AND reste_a_payer > 0.009
         ORDER BY echeance IS NULL, echeance, date_facture LIMIT " . max(1, $limite), $p);
}

function pennylane_dernieres_transactions(array $noms, int $limite = 30): array
{
    [$in, $p] = pl_in($noms);
    return pl_requete(
        "SELECT * FROM pennylane_transactions WHERE societe IN ($in)
         ORDER BY date_operation DESC, id DESC LIMIT " . max(1, $limite), $p);
}

/** Pour la vue d'ensemble : restes à payer par société ADHUB (hors restaurants, dont les factures fournisseurs ne sont pas montrées) */
function pennylane_par_projet(string $du, string $au): array
{
    $jour = date('Y-m-d');
    $out = [];
    foreach (get_db()->query(
        "SELECT ps.project_id,
                SUM(CASE WHEN f.avoir = 1 THEN -f.reste_a_payer ELSE f.reste_a_payer END) AS reste,
                SUM(CASE WHEN f.echeance IS NULL OR f.echeance >= '$jour' THEN 0 WHEN f.avoir = 1 THEN -f.reste_a_payer ELSE f.reste_a_payer END) AS echu
         FROM pennylane_factures f JOIN pennylane_societes ps ON ps.societe = f.societe
         JOIN projects p ON p.id = ps.project_id
         JOIN project_types pt ON pt.id = p.project_type_id
         WHERE f.sens = 'fournisseur' AND f.reste_a_payer > 0.009 AND pt.code <> 'restauration_exploitation'
         GROUP BY ps.project_id"
    )->fetchAll() as $r) {
        $out[(int) $r['project_id']] = ['reste' => (float) $r['reste'], 'echu' => (float) $r['echu']];
    }
    return $out;
}

/* ------------------------------------------------------------------ */
/* Suivi mensuel du tableau de bord                                    */
/* ------------------------------------------------------------------ */

/** Marque des mois écrits par l'outil : une saisie à la main n'a pas cette note et n'est jamais écrasée */
const PL_SUIVI_AUTO = 'auto:pennylane';

/**
 * Encaissements qui ne sont pas des revenus : prêts, apports, fonds passés par le
 * notaire, virements internes. Les virements venant d'une autre société du groupe
 * sont aussi écartés (repérés par son nom dans le libellé).
 */
const PL_PAS_UN_REVENU = '/COMPTE COURAN|PRET|PRÊT|APPORT|NOTAIRE|MAITRE|SCP |VIRT TRESO|TRESO COMPTE|RBT |REMBOURSEMENT|EMPRUNT/u';

function pl_est_un_revenu(string $libelle, array $nomsGroupe): bool
{
    $l = mb_strtoupper($libelle);
    if (preg_match(PL_PAS_UN_REVENU, $l)) {
        return false;
    }
    foreach ($nomsGroupe as $nom) {
        if ($nom !== '' && str_contains($l, $nom)) {
            return false;
        }
    }
    return true;
}

/**
 * Remplit le suivi mensuel (financial_entries) de chaque société reliée à Pennylane :
 *  - charges  = factures fournisseurs HT du mois (avoirs déduits), sauf restaurants (aucune) ;
 *  - revenus  = CA HT des caisses pour un restaurant, encaissements bancaires sinon
 *               (Pennylane ne contient presque pas de factures clients).
 * Appelé après chaque import Pennylane, changement de correspondance et synchro caisse.
 * Renvoie le nombre de mois écrits.
 */
function pennylane_alimenter_suivi_mensuel(): int
{
    $pdo = get_db();
    $projets = $pdo->query(
        "SELECT DISTINCT p.id, pt.code
         FROM pennylane_societes ps
         JOIN projects p ON p.id = ps.project_id
         JOIN project_types pt ON pt.id = p.project_type_id"
    )->fetchAll();

    // Noms des sociétés du groupe, sans forme juridique, pour repérer les virements internes
    $nomsGroupe = [];
    foreach ($pdo->query("SELECT name FROM projects") as $p) {
        foreach (preg_split('#\s*/\s*#', mb_strtoupper($p['name'])) as $n) {
            $n = trim(preg_replace('/^(SCI|SAS|SARL|SASU|EURL)\s+/u', '', $n));
            if (mb_strlen($n) >= 4) $nomsGroupe[] = $n;
        }
    }

    $existant = $pdo->prepare(
        "SELECT notes FROM financial_entries WHERE project_id = :p AND period_date = :m"
    );
    $ecrits = 0;

    foreach ($projets as $projet) {
        $pid = (int) $projet['id'];
        [$in, $params] = pl_in(pennylane_noms_projet($pid));
        $mois = [];

        foreach (pl_requete(
            "SELECT DATE_FORMAT(date_facture, '%Y-%m-01') AS m, SUM(ht) AS v
             FROM pennylane_factures
             WHERE sens = 'fournisseur' AND societe IN ($in) AND date_facture IS NOT NULL
             GROUP BY m", $params
        ) as $r) {
            $mois[$r['m']]['charges'] = (float) $r['v'];
        }

        if ($projet['code'] === 'restauration_exploitation') {
            $stmt = $pdo->prepare(
                "SELECT DATE_FORMAT(entry_date, '%Y-%m-01') AS m,
                        SUM(COALESCE(ca_ht, ca_ttc / 1.1)) AS v
                 FROM daily_entries WHERE project_id = :p GROUP BY m"
            );
            $stmt->execute(['p' => $pid]);
            foreach ($stmt->fetchAll() as $r) {
                $mois[$r['m']]['revenus'] = (float) $r['v'];
            }
        } else {
            $transactions = pl_requete(
                "SELECT DATE_FORMAT(date_operation, '%Y-%m-01') AS m, montant, libelle
                 FROM pennylane_transactions WHERE societe IN ($in)", $params
            );
            // Banque non connectée à Pennylane : revenus inconnus, on n'écrit rien
            if (!$transactions) {
                continue;
            }
            foreach ($transactions as $t) {
                $mois[$t['m']]['revenus'] ??= 0.0;
                if ((float) $t['montant'] > 0 && pl_est_un_revenu((string) $t['libelle'], $nomsGroupe)) {
                    $mois[$t['m']]['revenus'] += (float) $t['montant'];
                }
            }
        }

        foreach ($mois as $m => $v) {
            // Restaurant sans caisse ce mois-là : on n'affiche pas une perte qui n'en est pas une
            if ($projet['code'] === 'restauration_exploitation' && !isset($v['revenus'])) {
                continue;
            }
            $existant->execute(['p' => $pid, 'm' => $m]);
            $ligne = $existant->fetch();
            if ($ligne && $ligne['notes'] !== PL_SUIVI_AUTO) {
                continue;
            }
            upsert_financial_entry($pid, $m, round($v['revenus'] ?? 0, 2), round($v['charges'] ?? 0, 2), PL_SUIVI_AUTO);
            $ecrits++;
        }
    }
    return $ecrits;
}

/** La migration 015 (solde de départ) est-elle passée sur cette base ? */
function pennylane_tresorerie_prete(): bool
{
    static $prete = null;
    return $prete ??= (bool) get_db()->query("SHOW COLUMNS FROM projects LIKE 'solde_reference_le'")->fetch();
}

/** Une table existe-t-elle ? (les tables de trésorerie arrivent avec le script SQL de l'export) */
function pennylane_table_existe(string $table): bool
{
    static $cache = [];
    return $cache[$table] ??= (bool) get_db()->query("SHOW TABLES LIKE " . get_db()->quote($table))->fetch();
}

/**
 * Trésorerie de chaque société reliée.
 * 1. Si l'export contient les soldes des comptes (table pennylane_comptes), leur somme à la
 *    date du relevé devient le point de départ : rien à saisir.
 * 2. Sinon, le point de départ est le solde saisi dans la fiche ou la page Trésorerie.
 * On y ajoute ensuite les opérations bancaires Pennylane postérieures au point de départ.
 * Renvoie le nombre de sociétés mises à jour.
 */
function pennylane_maj_tresorerie(): int
{
    if (!pennylane_tresorerie_prete()) {
        return 0;
    }
    $pdo = get_db();

    if (pennylane_table_existe('pennylane_comptes')) {
        $ref = $pdo->prepare(
            "UPDATE projects SET solde_reference = :s, solde_reference_le = :d
             WHERE id = :p AND (solde_reference_le IS NULL OR solde_reference_le <= :d2)"
        );
        $soldes = $pdo->query(
            "SELECT ps.project_id, SUM(c.solde) AS solde, MAX(c.releve_le) AS releve_le
             FROM pennylane_comptes c
             JOIN pennylane_societes ps ON ps.societe = c.societe AND ps.project_id IS NOT NULL
             JOIN (SELECT societe_id, MAX(releve_le) AS dernier FROM pennylane_comptes GROUP BY societe_id) d
               ON d.societe_id = c.societe_id AND d.dernier = c.releve_le
             GROUP BY ps.project_id"
        )->fetchAll();
        foreach ($soldes as $r) {
            $ref->execute(['s' => round((float) $r['solde'], 2), 'd' => $r['releve_le'],
                           'd2' => $r['releve_le'], 'p' => (int) $r['project_id']]);
        }
    }

    $projets = $pdo->query(
        "SELECT DISTINCT p.id, p.solde_reference, p.solde_reference_le
         FROM projects p JOIN pennylane_societes ps ON ps.project_id = p.id
         WHERE p.solde_reference IS NOT NULL AND p.solde_reference_le IS NOT NULL"
    )->fetchAll();
    $maj = $pdo->prepare("UPDATE projects SET solde_bancaire = :s, solde_maj_le = :d WHERE id = :p");
    $n = 0;
    foreach ($projets as $projet) {
        [$in, $params] = pl_in(pennylane_noms_projet((int) $projet['id']));
        $params['depuis'] = $projet['solde_reference_le'];
        $r = pl_requete(
            "SELECT COALESCE(SUM(montant), 0) AS mouvements, MAX(date_operation) AS derniere
             FROM pennylane_transactions WHERE societe IN ($in) AND date_operation > :depuis", $params
        )[0];
        $maj->execute([
            's' => round((float) $projet['solde_reference'] + (float) $r['mouvements'], 2),
            'd' => $r['derniere'] ?? $projet['solde_reference_le'],
            'p' => (int) $projet['id'],
        ]);
        $n++;
    }
    return $n;
}

/** Comptes bancaires Pennylane d'une société, au dernier relevé */
function pennylane_comptes_projet(array $noms): array
{
    if (!$noms || !pennylane_table_existe('pennylane_comptes')) {
        return [];
    }
    [$in, $p] = pl_in($noms);
    return pl_requete(
        "SELECT c.* FROM pennylane_comptes c
         WHERE c.societe IN ($in)
           AND c.releve_le = (SELECT MAX(c2.releve_le) FROM pennylane_comptes c2 WHERE c2.societe_id = c.societe_id)
         ORDER BY c.solde DESC, c.banque", $p
    );
}

/** Soldes de fin de mois d'une société (toutes ses sociétés Pennylane additionnées) */
function pennylane_tresorerie_mensuelle(array $noms): array
{
    if (!$noms || !pennylane_table_existe('pennylane_tresorerie_mensuelle')) {
        return [];
    }
    [$in, $p] = pl_in($noms);
    return pl_requete(
        "SELECT mois, SUM(solde_debut) AS solde_debut, SUM(encaissements) AS encaissements,
                SUM(decaissements) AS decaissements, SUM(solde_fin) AS solde_fin, MAX(mois_en_cours) AS mois_en_cours
         FROM pennylane_tresorerie_mensuelle WHERE societe IN ($in) GROUP BY mois ORDER BY mois", $p
    );
}

/** TVA de la période en cours (montant estimé par Pennylane) */
function pennylane_tva_projet(array $noms): array
{
    if (!$noms || !pennylane_table_existe('pennylane_tva')) {
        return [];
    }
    [$in, $p] = pl_in($noms);
    return pl_requete(
        "SELECT t.* FROM pennylane_tva t WHERE t.societe IN ($in)
           AND t.debut = (SELECT MAX(t2.debut) FROM pennylane_tva t2 WHERE t2.societe_id = t.societe_id)", $p
    );
}

/**
 * Point de départ de la trésorerie, pour toutes les sociétés actives : solde de référence,
 * solde affiché, et opérations Pennylane reçues depuis la date de référence.
 */
function pennylane_soldes_depart(): array
{
    $projets = get_db()->query(
        "SELECT p.id, p.name, p.solde_bancaire, p.solde_maj_le, p.solde_reference, p.solde_reference_le,
                (SELECT COUNT(*) FROM pennylane_societes ps WHERE ps.project_id = p.id) AS nb_liens
         FROM projects p WHERE p.status = 'active' ORDER BY p.name"
    )->fetchAll();
    foreach ($projets as &$p) {
        $p['nb_operations'] = 0;
        $p['derniere_operation'] = null;
        $noms = pennylane_noms_projet((int) $p['id']);
        $p['nb_comptes'] = count(pennylane_comptes_projet($noms));
        if (!$noms) {
            continue;
        }
        [$in, $params] = pl_in($noms);
        $params['depuis'] = $p['solde_reference_le'] ?? '1900-01-01';
        $r = pl_requete(
            "SELECT COUNT(*) AS nb, MAX(date_operation) AS derniere
             FROM pennylane_transactions WHERE societe IN ($in) AND date_operation > :depuis", $params
        )[0];
        $p['nb_operations'] = (int) $r['nb'];
        $p['derniere_operation'] = $r['derniere'];
    }
    return $projets;
}

/** Enregistre le solde bancaire réel d'une société au soir de la date $le */
function pennylane_definir_solde_depart(int $projectId, float $solde, string $le): void
{
    get_db()->prepare(
        "UPDATE projects SET solde_reference = :s, solde_reference_le = :d,
                solde_bancaire = :s2, solde_maj_le = :d2 WHERE id = :p"
    )->execute(['s' => $solde, 'd' => $le, 's2' => $solde, 'd2' => $le, 'p' => $projectId]);
}
