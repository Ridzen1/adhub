<?php

/**
 * Banques des sociétés, lues dans Qalimo (agrégation bancaire Linxo de Qalimo).
 *
 * cron/qalimo/qalimo_banques_sync.php se connecte à l'espace Qalimo avec un identifiant,
 * lit les comptes (solde, état de la connexion) et les opérations, puis appelle
 * qalimo_banques_enregistrer(). Ce fichier fournit aussi les indicateurs de l'onglet Banque.
 *
 * Règles ADHUB : chaque compte appartient à une seule société ; on n'additionne jamais
 * deux sociétés entre elles ; les restaurants n'affichent pas de trésorerie.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/pennylane.php'; // pennylane_suggerer : rapprochement par nom

function qalimo_banques_disponible(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            $ok = (bool) get_db()->query("SHOW TABLES LIKE 'qalimo_comptes'")->fetchColumn();
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

/** Nombre décimal depuis la réponse Qalimo (nombre, chaîne « 1 234,56 » ou null) */
function qb_nombre($v): ?float
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = str_replace(["\u{202F}", "\u{00A0}", ' ', '€'], '', (string) $v);
    if (str_contains($s, ',') && !str_contains($s, '.')) {
        $s = str_replace(',', '.', $s);
    }
    return is_numeric($s) ? (float) $s : null;
}

/** Date AAAA-MM-JJ (ou date-heure) depuis la réponse Qalimo */
function qb_date($v, bool $avecHeure = false): ?string
{
    if (!$v) {
        return null;
    }
    $ts = strtotime((string) $v);
    return $ts ? date($avecHeure ? 'Y-m-d H:i:s' : 'Y-m-d', $ts) : null;
}

/**
 * Société ADHUB d'un compte, par ordre de priorité :
 * 1. correspondance forcée dans la config (unique_id ou id du compte) ;
 * 2. profil bailleur Qalimo déjà relié par la synchro des biens (table qalimo_profils) ;
 * 3. nom du compte rapproché des noms de sociétés (restaurants compris).
 */
function qalimo_societe_du_compte(array $compte, array $forcees, array $profils, array $projets): ?int
{
    foreach ([$compte['unique_id'] ?? null, $compte['id'] ?? null] as $cle) {
        if ($cle !== null && isset($forcees[(string) $cle])) {
            return (int) $forcees[(string) $cle];
        }
    }
    $profil = $compte['id_profile_nom'] ?? null;
    if ($profil !== null && !empty($profils[(int) $profil])) {
        return (int) $profils[(int) $profil];
    }
    $nom = qalimo_nom_pour_rapprochement((string) ($compte['nom'] ?? ''));
    return $nom !== null ? pennylane_suggerer($nom, $projets) : null;
}

/**
 * Nom d'un compte réduit à celui de la société : la banque y ajoute le type de compte et
 * un numéro (« COMPTE COURANT SCI PARTICULIERS - COUSIMMO (x8801) » → « SCI COUSIMMO »).
 * null pour un compte de particulier (« M OU MME … ») ou sans nom utile : à relier à la main.
 */
function qalimo_nom_pour_rapprochement(string $nom): ?string
{
    $n = strtr(mb_strtoupper(trim($nom)), ['É' => 'E', 'È' => 'E', 'Ê' => 'E', 'À' => 'A', 'Ô' => 'O', 'Ç' => 'C']);
    if ($n === '' || preg_match('/\b(M|MR|MME|MLLE|MONSIEUR|MADAME)\b/', $n)) {
        return null;
    }
    $n = preg_replace('/\(X?\d+\)/', ' ', $n);
    $n = preg_replace('/\bSTE\s+CIV(ILE)?\b|\bS\.?\s*C\.?\s*I\.?(?=\s|$)/', ' SCI ', $n);
    $generiques = ['COMPTE', 'COURANT', 'PARTICULIERS', 'PARTICULIER', 'PROFESSIONNEL', 'PROFESSIONNELS', 'PRO',
                   'ENTREPRISE', 'ENTREPRISES', 'EUR', 'EURO', 'EUROS', 'CHEQUES', 'CHEQUE', 'LIVRET', 'EPARGNE',
                   'DEPOT', 'DEPOTS', 'TERME', 'CAV'];
    $mots = array_filter(preg_split('/[^A-Z0-9]+/', $n, -1, PREG_SPLIT_NO_EMPTY), fn($m) => !in_array($m, $generiques, true));
    $mots = array_values(array_unique($mots));
    // Il doit rester autre chose qu'une forme juridique
    if (!array_diff($mots, ['SCI', 'SAS', 'SASU', 'SARL', 'EURL', 'SA', 'SNC'])) {
        return null;
    }
    return implode(' ', $mots);
}

/** Correspondances profil Qalimo → société ADHUB connues (synchro des biens), [] si absente */
function qalimo_profils_relies(): array
{
    try {
        $rows = get_db()->query("SELECT id, project_id FROM qalimo_profils WHERE project_id IS NOT NULL")->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
    return array_column($rows, 'project_id', 'id');
}

/**
 * Enregistre les comptes lus et les opérations de la fenêtre [$du, aujourd'hui].
 * Les comptes sont remplacés ; les opérations de la fenêtre sont remplacées (une opération
 * supprimée dans Qalimo disparaît aussi), celles d'avant $du sont gardées.
 * $du = null : rechargement complet de l'historique.
 * Puis met à jour la trésorerie des sociétés reliées (hors restaurants).
 * Renvoie [project_id => solde] pour les sociétés mises à jour.
 */
function qalimo_banques_enregistrer(array $comptes, array $operations, array $liens, ?string $du): array
{
    $pdo = get_db();
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
        $pdo->exec("DELETE FROM qalimo_comptes");
        $insC = $pdo->prepare(
            "INSERT INTO qalimo_comptes (id, unique_id, profil_id, project_id, banque, libelle, iban_fin, solde, statut,
                                         compte_gestion, maj_banque, synced_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $uids = [];
        foreach ($comptes as $c) {
            $uid = (string) ($c['unique_id'] ?? $c['id']);
            $uids[] = $uid;
            $iban = preg_replace('/\s+/', '', (string) ($c['iban'] ?? $c['account_number'] ?? ''));
            $insC->execute([
                (int) $c['id'], $uid,
                isset($c['id_profile_nom']) ? (int) $c['id_profile_nom'] : null,
                $liens[(int) $c['id']] ?? null,
                mb_substr((string) ($c['bank_metadata']['name'] ?? ''), 0, 150) ?: null,
                mb_substr(trim((string) ($c['nom'] ?? '')), 0, 200) ?: null,
                $iban !== '' ? substr($iban, -4) : null,
                qb_nombre($c['montant'] ?? null),
                isset($c['status']) ? mb_substr((string) $c['status'], 0, 30) : null,
                empty($c['compte_gestion']) ? 0 : 1,
                qb_date($c['date_maj'] ?? null, true),
                $now,
            ]);
        }

        if ($du === null) {
            $pdo->exec("DELETE FROM qalimo_operations");
        } else {
            $pdo->prepare("DELETE FROM qalimo_operations WHERE date_operation >= ?")->execute([$du]);
        }
        $insO = $pdo->prepare(
            "INSERT INTO qalimo_operations (id, compte_uid, date_operation, libelle, montant, synced_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE compte_uid = VALUES(compte_uid), date_operation = VALUES(date_operation),
                                     libelle = VALUES(libelle), montant = VALUES(montant), synced_at = VALUES(synced_at)"
        );
        foreach ($operations as $o) {
            $date = qb_date($o['date_transac'] ?? $o['date'] ?? null);
            $montant = qb_nombre($o['montant'] ?? null);
            // Comme la page Transactions de Qalimo : les lignes rattachées à une autre
            // (id_transaction rempli) ne sont pas des opérations à part entière
            if (!$date || $montant === null || empty($o['unique_account_id']) || !empty($o['id_transaction'])) {
                continue;
            }
            $insO->execute([(int) $o['id'], (string) $o['unique_account_id'], $date,
                            mb_substr(trim(preg_replace('/\s+/', ' ', (string) ($o['description'] ?? ''))), 0, 500) ?: null,
                            $montant, $now]);
        }

        $soldes = qalimo_banques_maj_tresorerie($pdo);
        $pdo->commit();
        return $soldes;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Trésorerie d'une société = somme des soldes de SES comptes Qalimo (jamais d'une autre).
 * Les restaurants ne reçoivent pas de trésorerie (règle ADHUB).
 */
function qalimo_banques_maj_tresorerie(?PDO $pdo = null): array
{
    $pdo ??= get_db();
    $rows = $pdo->query(
        "SELECT c.project_id, SUM(c.solde) AS solde, DATE(MAX(COALESCE(c.maj_banque, c.synced_at))) AS le
         FROM qalimo_comptes c
         JOIN projects p ON p.id = c.project_id
         JOIN project_types pt ON pt.id = p.project_type_id
         WHERE c.solde IS NOT NULL AND pt.code <> 'restauration_exploitation'
         GROUP BY c.project_id"
    )->fetchAll();
    $maj = $pdo->prepare("UPDATE projects SET solde_bancaire = :s, solde_maj_le = :d WHERE id = :p");
    $out = [];
    foreach ($rows as $r) {
        $s = round((float) $r['solde'], 2);
        $maj->execute(['s' => $s, 'd' => $r['le'], 'p' => (int) $r['project_id']]);
        $out[(int) $r['project_id']] = $s;
    }
    return $out;
}

/** Ids des sociétés dont la trésorerie vient de Qalimo (Pennylane ne doit pas l'écraser) */
function qalimo_banques_projets(): array
{
    if (!qalimo_banques_disponible()) {
        return [];
    }
    return array_map('intval', get_db()->query(
        "SELECT DISTINCT project_id FROM qalimo_comptes WHERE project_id IS NOT NULL AND solde IS NOT NULL"
    )->fetchAll(PDO::FETCH_COLUMN));
}

/* ------------------------------------------------------------------ */
/* Indicateurs de l'onglet Banque (une seule société à la fois)        */
/* ------------------------------------------------------------------ */

function qalimo_comptes_projet(int $projectId): array
{
    $st = get_db()->prepare("SELECT * FROM qalimo_comptes WHERE project_id = :p ORDER BY solde DESC, banque, libelle");
    $st->execute(['p' => $projectId]);
    return $st->fetchAll();
}

/** Entrées / sorties d'une société entre deux dates (incluses) */
function qalimo_flux_projet(int $projectId, string $du, string $au): array
{
    $st = get_db()->prepare(
        "SELECT COALESCE(SUM(CASE WHEN o.montant > 0 THEN o.montant END), 0) AS entrees,
                COALESCE(-SUM(CASE WHEN o.montant < 0 THEN o.montant END), 0) AS sorties,
                COUNT(*) AS nb
         FROM qalimo_operations o JOIN qalimo_comptes c ON c.unique_id = o.compte_uid
         WHERE c.project_id = :p AND o.date_operation BETWEEN :du AND :au"
    );
    $st->execute(['p' => $projectId, 'du' => $du, 'au' => $au]);
    $r = $st->fetch();
    return ['entrees' => (float) $r['entrees'], 'sorties' => (float) $r['sorties'], 'nb' => (int) $r['nb']];
}

/** Entrées / sorties par mois (AAAA-MM) sur les N derniers mois, mois en cours compris */
function qalimo_flux_mensuels(int $projectId, int $nbMois = 12): array
{
    $mois = [];
    $d = new DateTimeImmutable('first day of this month');
    for ($i = $nbMois - 1; $i >= 0; $i--) {
        $mois[$d->modify("-$i months")->format('Y-m')] = ['entrees' => 0.0, 'sorties' => 0.0];
    }
    $st = get_db()->prepare(
        "SELECT DATE_FORMAT(o.date_operation, '%Y-%m') AS mois,
                SUM(CASE WHEN o.montant > 0 THEN o.montant ELSE 0 END) AS entrees,
                -SUM(CASE WHEN o.montant < 0 THEN o.montant ELSE 0 END) AS sorties
         FROM qalimo_operations o JOIN qalimo_comptes c ON c.unique_id = o.compte_uid
         WHERE c.project_id = :p AND o.date_operation >= :du
         GROUP BY mois"
    );
    $st->execute(['p' => $projectId, 'du' => array_key_first($mois) . '-01']);
    foreach ($st->fetchAll() as $r) {
        if (isset($mois[$r['mois']])) {
            $mois[$r['mois']] = ['entrees' => (float) $r['entrees'], 'sorties' => (float) $r['sorties']];
        }
    }
    return $mois;
}

/** Dernières opérations d'une société, éventuellement d'un seul compte */
function qalimo_operations_projet(int $projectId, int $limite = 100, ?string $compteUid = null, ?string $sens = null): array
{
    $sql = "SELECT o.*, c.banque, c.libelle AS compte
            FROM qalimo_operations o JOIN qalimo_comptes c ON c.unique_id = o.compte_uid
            WHERE c.project_id = :p";
    $params = ['p' => $projectId];
    if ($compteUid !== null) {
        $sql .= " AND o.compte_uid = :u";
        $params['u'] = $compteUid;
    }
    if ($sens === 'entrees') {
        $sql .= " AND o.montant > 0";
    } elseif ($sens === 'sorties') {
        $sql .= " AND o.montant < 0";
    }
    $sql .= " ORDER BY o.date_operation DESC, o.id DESC LIMIT " . max(1, min(1000, $limite));
    $st = get_db()->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** Plus grosses sorties d'une société sur une période (regroupées par libellé) */
function qalimo_principales_sorties(int $projectId, string $du, string $au, int $limite = 8): array
{
    $st = get_db()->prepare(
        "SELECT o.libelle, COUNT(*) AS nb, -SUM(o.montant) AS total
         FROM qalimo_operations o JOIN qalimo_comptes c ON c.unique_id = o.compte_uid
         WHERE c.project_id = :p AND o.montant < 0 AND o.date_operation BETWEEN :du AND :au
         GROUP BY o.libelle ORDER BY total DESC LIMIT " . max(1, min(50, $limite))
    );
    $st->execute(['p' => $projectId, 'du' => $du, 'au' => $au]);
    return $st->fetchAll();
}

/** Comptes Qalimo qu'aucune société ne reprend (affichés à la direction pour les relier) */
function qalimo_comptes_non_relies(): array
{
    if (!qalimo_banques_disponible()) {
        return [];
    }
    return get_db()->query(
        "SELECT * FROM qalimo_comptes WHERE project_id IS NULL ORDER BY banque, libelle"
    )->fetchAll();
}

/** Libellé lisible de l'état de connexion Qalimo ↔ banque */
function qalimo_statut_compte(?string $statut): array
{
    // Qalimo code l'état en nombre : 1 = active, 2 = session expirée, 3 = non synchronisé
    return match (strtolower((string) $statut)) {
        '1', 'active', ''    => ['Connectée', 'success'],
        '2', 'inactive'      => ['Session expirée', 'warning'],
        '3', 'unconsented'   => ['Non synchronisé', 'warning'],
        default              => ['État ' . $statut, 'secondary'],
    };
}
