<?php
/**
 * Biens immobiliers et prêts des sociétés (migration 027).
 *
 * La direction dépose le fichier Excel « Suivi des emprunts » (feuilles Biens, Prêts,
 * Restaurants) dans Administration → Biens et prêts. Chaque ligne est reconnue par son
 * identifiant (B01, P01, R01…) : réimporter met à jour sans doublon, et une ligne retirée
 * du fichier est retirée d'ADHUB.
 *
 * Le capital restant dû est recalculé chaque jour à partir du capital à la date d'ancrage,
 * du taux et de la mensualité : le fichier n'a pas besoin d'être redéposé chaque mois.
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../cron/caisses/xlsx_reader.php';

/* ------------------------------------------------------------------ */
/* Disponibilité                                                       */
/* ------------------------------------------------------------------ */

function biens_tables_pretes(): bool
{
    static $ok = null;
    if ($ok === null) {
        try {
            get_db()->query("SELECT 1 FROM prets LIMIT 1");
            get_db()->query("SELECT 1 FROM biens_societes LIMIT 1");
            $ok = true;
        } catch (PDOException $e) {
            $ok = false;
        }
    }
    return $ok;
}

/* ------------------------------------------------------------------ */
/* Noms de société du fichier -> sociétés ADHUB                        */
/* ------------------------------------------------------------------ */

/** Majuscules sans accents ni ponctuation, sans « (à confirmer) » ni « - Nom propre » */
function biens_normaliser(string $s): string
{
    $s = mb_strtoupper($s, 'UTF-8');
    $s = preg_replace('/\(.*?\)/u', ' ', $s);
    $s = preg_replace('/-\s*NOM PROPRE/u', ' ', $s);
    $s = strtr($s, [
        'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Á' => 'A', 'Ç' => 'C', 'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Î' => 'I', 'Ï' => 'I', 'Í' => 'I', 'Ô' => 'O', 'Ö' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U', 'Ú' => 'U',
        'Œ' => 'OE', 'Æ' => 'AE', '’' => ' ', "'" => ' ',
    ]);
    $s = preg_replace('/[^A-Z0-9\/]+/', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

/** Clé de comparaison : sans forme juridique (SCI, SAS…) ni article */
function biens_cle(string $normalise): string
{
    $mots = array_filter(explode(' ', $normalise), fn($m) => $m !== '' && !in_array($m, [
        'SCI', 'SAS', 'SASU', 'SARL', 'EURL', 'STE', 'SOCIETE', 'LE', 'LA', 'LES', 'DU', 'DE', 'DES', 'L', 'D',
    ], true));
    return implode(' ', $mots);
}

/** Société ADHUB la plus probable pour un nom du fichier, ou null si aucune / plusieurs */
function biens_suggerer(string $nomFichier, array $projets): ?int
{
    $n   = biens_normaliser($nomFichier);
    $cle = biens_cle($n);
    if ($cle === '') {
        return null;
    }
    $candidats = [];
    foreach ($projets as $p) {
        foreach (explode('/', biens_normaliser($p['name'])) as $partie) {
            if (biens_cle(trim($partie)) === $cle) {
                $candidats[(int) $p['id']] = $p;
                break;
            }
        }
    }
    if (count($candidats) > 1) {
        // « SCI VALENTINO » / « SAS VALENTINO » : la forme juridique départage
        $estSci = str_starts_with($n, 'SCI ');
        $estSas = (bool) preg_match('/^(SAS|SASU|SARL|EURL) /', $n);
        if ($estSci || $estSas) {
            $candidats = array_filter($candidats, function ($p) use ($estSci) {
                $sci = in_array($p['type_code'] ?? '', ['immobilier', 'restauration_murs'], true)
                    || str_contains(biens_normaliser($p['name']), 'SCI');
                return $estSci ? $sci : !$sci;
            });
        }
    }
    return count($candidats) === 1 ? (int) array_key_first($candidats) : null;
}

/** Ajoute à la table de correspondance les noms nouveaux, reliés d'office quand c'est évident */
function biens_relier_automatiquement(): void
{
    if (!biens_tables_pretes()) {
        return;
    }
    $pdo = get_db();
    $noms = $pdo->query(
        "SELECT s.societe FROM (SELECT societe FROM biens UNION SELECT societe FROM prets) s
         WHERE s.societe NOT IN (SELECT societe FROM biens_societes)"
    )->fetchAll(PDO::FETCH_COLUMN);
    if (!$noms) {
        return;
    }
    $projets = $pdo->query(
        "SELECT p.id, p.name, pt.code AS type_code FROM projects p
         JOIN project_types pt ON pt.id = p.project_type_id WHERE p.status = 'active'"
    )->fetchAll();
    $ins = $pdo->prepare("INSERT IGNORE INTO biens_societes (societe, project_id) VALUES (:s, :p)");
    foreach ($noms as $nom) {
        $ins->execute(['s' => $nom, 'p' => biens_suggerer($nom, $projets)]);
    }
}

function biens_correspondances(): array
{
    return get_db()->query(
        "SELECT bs.societe, bs.project_id,
                (SELECT COUNT(*) FROM biens b WHERE b.societe = bs.societe) AS nb_biens,
                (SELECT COUNT(*) FROM prets pr WHERE pr.societe = bs.societe) AS nb_prets
         FROM biens_societes bs
         ORDER BY bs.project_id IS NOT NULL, bs.societe"
    )->fetchAll();
}

function biens_definir_correspondance(string $societe, ?int $projectId): void
{
    $st = get_db()->prepare("UPDATE biens_societes SET project_id = :p WHERE societe = :s");
    $st->execute(['p' => $projectId, 's' => $societe]);
}

/* ------------------------------------------------------------------ */
/* Import du fichier Excel                                             */
/* ------------------------------------------------------------------ */

/** Repère la ligne d'en-tête (celle qui contient $marqueur) et renvoie [index de ligne, colonnes par titre normalisé] */
function biens_entetes(array $rows, string $marqueur): ?array
{
    foreach ($rows as $i => $r) {
        $cols = [];
        foreach ($r as $j => $v) {
            if (is_string($v) && trim($v) !== '') {
                $cols[biens_normaliser($v)] = $j;
            }
        }
        if (isset($cols[biens_normaliser($marqueur)])) {
            return [$i, $cols];
        }
    }
    return null;
}

/** Valeur d'une colonne trouvée par le début de son titre (ex. « CAPITAL A LA DATE ») */
function biens_col(array $row, array $cols, string $debut)
{
    $debut = biens_normaliser($debut);
    foreach ($cols as $titre => $j) {
        if (str_starts_with($titre, $debut)) {
            $v = $row[$j] ?? null;
            return is_string($v) ? (trim($v) === '' || trim($v) === '—' ? null : trim($v)) : $v;
        }
    }
    return null;
}

function biens_nombre($v): ?float
{
    if ($v === null || $v === '' || is_bool($v)) {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return (float) $v;
    }
    $s = str_replace([' ', "\u{a0}", "\u{202f}", '€', '%'], '', (string) $v);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? (float) $s : null;
}

/** Nom de société nettoyé de « (à confirmer) », et le drapeau correspondant */
function biens_societe_fichier(?string $brut): array
{
    $brut = trim((string) $brut);
    $aConfirmer = (bool) preg_match('/\(\s*[àa]\s+confirmer\s*\)/iu', $brut);
    $nom = trim(preg_replace('/\(\s*[àa]\s+confirmer\s*\)/iu', '', $brut));
    return [$nom, $aConfirmer];
}

function biens_importer_xlsx(string $binaire, string $nomFichier, ?int $userId): array
{
    $feuilles = xlsxSheets($binaire);
    $parNom = [];
    foreach ($feuilles as $nom => $rows) {
        $parNom[biens_normaliser($nom)] = $rows;
    }
    if (!isset($parNom['BIENS']) && !isset($parNom['PRETS']) && !isset($parNom['RESTAURANTS'])) {
        throw new RuntimeException("Le fichier ne contient aucune des feuilles attendues : « Biens », « Prêts », « Restaurants ».");
    }

    $pdo = get_db();
    $res = ['biens' => 0, 'prets' => 0, 'retires' => 0];
    $pdo->beginTransaction();
    try {
        // --- Biens
        if (isset($parNom['BIENS'])) {
            $rows = $parNom['BIENS'];
            $h = biens_entetes($rows, 'Type de bien');
            if (!$h) {
                throw new RuntimeException("Feuille « Biens » : ligne d'en-tête introuvable (colonne « Type de bien »).");
            }
            [$debut, $cols] = $h;
            $ins = $pdo->prepare(
                "INSERT INTO biens (ref, societe, type_bien, adresse, valeur) VALUES (:ref, :societe, :type, :adresse, :valeur)
                 ON DUPLICATE KEY UPDATE societe = VALUES(societe), type_bien = VALUES(type_bien),
                     adresse = VALUES(adresse), valeur = VALUES(valeur)"
            );
            $refs = [];
            foreach (array_slice($rows, $debut + 1) as $r) {
                $ref = trim((string) biens_col($r, $cols, 'ID'));
                if (!preg_match('/^[A-Z]{1,3}\d+$/', $ref)) {
                    continue;
                }
                [$societe] = biens_societe_fichier(biens_col($r, $cols, 'Société'));
                if ($societe === '') {
                    continue;
                }
                $ins->execute([
                    'ref'     => $ref,
                    'societe' => $societe,
                    'type'    => rtrim((string) biens_col($r, $cols, 'Type de bien'), ' ,') ?: null,
                    'adresse' => biens_col($r, $cols, 'Adresse'),
                    'valeur'  => biens_nombre(biens_col($r, $cols, 'Valeur')),
                ]);
                $refs[] = $ref;
            }
            $res['biens'] = count($refs);
            $res['retires'] += biens_retirer_absents('biens', $refs);
        }

        // --- Prêts des sociétés (feuille Prêts) et des restaurants (feuille Restaurants)
        $refsPrets = [];
        $vuePrets  = false;
        $ins = $pdo->prepare(
            "INSERT INTO prets (ref, bien_ref, societe, a_confirmer, restaurant, bien_libelle, banque, numero, objet, statut,
                                capital_ancrage, date_ancrage, taux, mensualite, fin_differe, mensualite_post_differe,
                                crd_fichier, date_fin, date_fin_estimee)
             VALUES (:ref, :bien_ref, :societe, :a_confirmer, :restaurant, :bien_libelle, :banque, :numero, :objet, :statut,
                     :capital, :ancrage, :taux, :mensualite, :fin_differe, :post, :crd, :date_fin, :estimee)
             ON DUPLICATE KEY UPDATE bien_ref = VALUES(bien_ref), societe = VALUES(societe), a_confirmer = VALUES(a_confirmer),
                 restaurant = VALUES(restaurant), bien_libelle = VALUES(bien_libelle), banque = VALUES(banque),
                 numero = VALUES(numero), objet = VALUES(objet), statut = VALUES(statut),
                 capital_ancrage = VALUES(capital_ancrage), date_ancrage = VALUES(date_ancrage), taux = VALUES(taux),
                 mensualite = VALUES(mensualite), fin_differe = VALUES(fin_differe),
                 mensualite_post_differe = VALUES(mensualite_post_differe), crd_fichier = VALUES(crd_fichier),
                 date_fin = VALUES(date_fin), date_fin_estimee = VALUES(date_fin_estimee)"
        );
        foreach (['PRETS' => 'Société', 'RESTAURANTS' => 'Société emprunteuse'] as $feuille => $colSociete) {
            if (!isset($parNom[$feuille])) {
                continue;
            }
            $vuePrets = true;
            $rows = $parNom[$feuille];
            $h = biens_entetes($rows, 'ID Prêt');
            if (!$h) {
                throw new RuntimeException("Feuille « " . ($feuille === 'PRETS' ? 'Prêts' : 'Restaurants') . " » : ligne d'en-tête introuvable (colonne « ID Prêt »).");
            }
            [$debut, $cols] = $h;
            foreach (array_slice($rows, $debut + 1) as $r) {
                $ref = trim((string) biens_col($r, $cols, 'ID Prêt'));
                if (!preg_match('/^[A-Z]{1,3}\d+$/', $ref)) {
                    continue;
                }
                [$societe, $aConfirmer] = biens_societe_fichier(biens_col($r, $cols, $colSociete));
                if ($societe === '') {
                    continue;
                }
                $taux = biens_nombre(biens_col($r, $cols, 'Taux'));
                if ($taux !== null && $taux > 1) {
                    $taux /= 100; // saisi en pourcentage (3,32 au lieu de 0,0332)
                }
                $finBrut = biens_col($r, $cols, 'Date de fin');
                $ins->execute([
                    'ref'          => $ref,
                    'bien_ref'     => biens_col($r, $cols, 'ID Bien'),
                    'societe'      => $societe,
                    'a_confirmer'  => $aConfirmer ? 1 : 0,
                    'restaurant'   => $feuille === 'RESTAURANTS' ? biens_col($r, $cols, 'Restaurant') : null,
                    'bien_libelle' => $feuille === 'PRETS' ? biens_col($r, $cols, 'Bien') : biens_col($r, $cols, 'Adresse'),
                    'banque'       => biens_col($r, $cols, 'Banque'),
                    'numero'       => biens_col($r, $cols, 'N de prêt') ?? biens_col($r, $cols, 'N° de prêt'),
                    'objet'        => biens_col($r, $cols, 'Objet'),
                    'statut'       => biens_col($r, $cols, 'Statut'),
                    'capital'      => biens_nombre(biens_col($r, $cols, 'Capital à la date')),
                    'ancrage'      => xlsxDate(biens_col($r, $cols, "Date d'ancrage")),
                    'taux'         => $taux,
                    'mensualite'   => biens_nombre(biens_col($r, $cols, 'Mensualité hors')),
                    'fin_differe'  => xlsxDate(biens_col($r, $cols, 'Fin de différé')),
                    'post'         => biens_nombre(biens_col($r, $cols, 'Mensualité post')),
                    'crd'          => biens_nombre(biens_col($r, $cols, 'Capital restant')),
                    'date_fin'     => xlsxDate($finBrut),
                    'estimee'      => is_string($finBrut) && str_contains($finBrut, '*') ? 1 : 0,
                ]);
                $refsPrets[] = $ref;
            }
        }
        if ($vuePrets) {
            $res['prets'] = count($refsPrets);
            $res['retires'] += biens_retirer_absents('prets', $refsPrets);
        }

        $pdo->prepare("INSERT INTO biens_imports (user_id, fichier, nb_biens, nb_prets) VALUES (:u, :f, :b, :p)")
            ->execute(['u' => $userId, 'f' => $nomFichier, 'b' => $res['biens'], 'p' => $res['prets']]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    biens_relier_automatiquement();
    return $res;
}

/** Supprime les lignes dont l'identifiant n'est plus dans le fichier ; renvoie leur nombre */
function biens_retirer_absents(string $table, array $refs): int
{
    $pdo = get_db();
    if (!$refs) {
        return (int) $pdo->exec("DELETE FROM $table");
    }
    $marques = implode(',', array_fill(0, count($refs), '?'));
    $st = $pdo->prepare("DELETE FROM $table WHERE ref NOT IN ($marques)");
    $st->execute(array_values($refs));
    return $st->rowCount();
}

function biens_dernier_import(): ?array
{
    try {
        $r = get_db()->query(
            "SELECT bi.*, u.name AS user_name FROM biens_imports bi
             LEFT JOIN users u ON u.id = bi.user_id ORDER BY bi.id DESC LIMIT 1"
        )->fetch();
        return $r ?: null;
    } catch (PDOException $e) {
        return null;
    }
}

/* ------------------------------------------------------------------ */
/* Calcul du capital restant dû                                        */
/* ------------------------------------------------------------------ */

/** Nombre d'échéances mensuelles strictement après $depuis et jusqu'à $jusqua inclus (même jour du mois) */
function pret_nb_echeances(string $depuis, DateTimeImmutable $jusqua): int
{
    $d0  = new DateTimeImmutable($depuis);
    $jour = (int) $d0->format('d');
    $n = 0;
    for ($k = 1; $k <= 600; $k++) {
        $m = $d0->modify('first day of +' . $k . ' month');
        $e = $m->setDate((int) $m->format('Y'), (int) $m->format('m'), min($jour, (int) $m->format('t')));
        if ($e > $jusqua) {
            break;
        }
        $n = $k;
    }
    return $n;
}

/** Capital restant après $n mensualités $mens au taux mensuel $r, à partir de $c */
function pret_crd_apres(float $c, float $r, float $mens, int $n): float
{
    if ($n <= 0) {
        return $c;
    }
    if ($r <= 0) {
        return max(0.0, $c - $mens * $n);
    }
    $f = (1 + $r) ** $n;
    return max(0.0, $c * $f - $mens * ($f - 1) / $r);
}

/**
 * Situation d'un prêt à une date : capital restant dû, mensualité en cours, phase.
 * phase : differe (intérêts seuls), amortissement, fige (en déblocage), termine, inconnu.
 * calcule = false quand les données manquent : on reprend alors le chiffre du fichier.
 */
function pret_situation(array $p, ?DateTimeImmutable $au = null): array
{
    $au  = $au ?? new DateTimeImmutable('today');
    $c   = $p['capital_ancrage'] !== null ? (float) $p['capital_ancrage'] : null;
    $r   = $p['taux'] !== null ? (float) $p['taux'] / 12 : null;
    $out = ['crd' => $p['crd_fichier'] !== null ? (float) $p['crd_fichier'] : $c, 'mensualite' => null, 'phase' => 'inconnu', 'calcule' => false];

    if ($p['date_fin'] !== null && new DateTimeImmutable($p['date_fin']) < $au) {
        return ['crd' => 0.0, 'mensualite' => 0.0, 'phase' => 'termine', 'calcule' => true];
    }
    if ($c === null || $p['date_ancrage'] === null) {
        return $out;
    }
    $statut = mb_strtolower((string) $p['statut']);

    if (str_contains($statut, 'déblocage') || str_contains($statut, 'deblocage') || str_contains($statut, 'fig')) {
        return ['crd' => $c, 'mensualite' => null, 'phase' => 'fige', 'calcule' => true];
    }
    if ($p['fin_differe'] !== null) {
        $fin = new DateTimeImmutable($p['fin_differe']);
        if ($au <= $fin) {
            return ['crd' => $c, 'mensualite' => $r !== null ? round($c * $r, 2) : null, 'phase' => 'differe', 'calcule' => true];
        }
        $mens = $p['mensualite_post_differe'] !== null ? (float) $p['mensualite_post_differe'] : null;
        if ($mens === null || $r === null) {
            return $out;
        }
        $n = pret_nb_echeances($p['fin_differe'], $au);
        return ['crd' => pret_crd_apres($c, $r, $mens, $n), 'mensualite' => $mens, 'phase' => 'amortissement', 'calcule' => true];
    }
    $mens = $p['mensualite'] !== null ? (float) $p['mensualite'] : null;
    if ($mens === null || $r === null) {
        return $out;
    }
    $n = pret_nb_echeances($p['date_ancrage'], $au);
    return ['crd' => pret_crd_apres($c, $r, $mens, $n), 'mensualite' => $mens, 'phase' => 'amortissement', 'calcule' => true];
}

/* ------------------------------------------------------------------ */
/* Lecture par société                                                 */
/* ------------------------------------------------------------------ */

function biens_du_projet(int $projectId): array
{
    if (!biens_tables_pretes()) {
        return [];
    }
    $st = get_db()->prepare(
        "SELECT b.* FROM biens b JOIN biens_societes bs ON bs.societe = b.societe
         WHERE bs.project_id = :p ORDER BY b.ref"
    );
    $st->execute(['p' => $projectId]);
    return $st->fetchAll();
}

function prets_du_projet(int $projectId): array
{
    if (!biens_tables_pretes()) {
        return [];
    }
    $st = get_db()->prepare(
        "SELECT pr.*, b.adresse AS bien_adresse FROM prets pr
         JOIN biens_societes bs ON bs.societe = pr.societe
         LEFT JOIN biens b ON b.ref = pr.bien_ref
         WHERE bs.project_id = :p ORDER BY pr.ref"
    );
    $st->execute(['p' => $projectId]);
    $prets = $st->fetchAll();
    foreach ($prets as &$p) {
        $p['situation'] = pret_situation($p);
    }
    return $prets;
}

/** Totaux par société : [project_id => valeur, crd, mensualites, nb_biens, nb_prets] */
function biens_totaux_par_projet(): array
{
    if (!biens_tables_pretes()) {
        return [];
    }
    $pdo = get_db();
    $tot = [];
    $init = ['valeur' => null, 'crd' => null, 'mensualites' => 0.0, 'nb_biens' => 0, 'nb_prets' => 0];
    foreach ($pdo->query(
        "SELECT bs.project_id, SUM(b.valeur) AS valeur, COUNT(*) AS nb FROM biens b
         JOIN biens_societes bs ON bs.societe = b.societe WHERE bs.project_id IS NOT NULL GROUP BY bs.project_id"
    ) as $r) {
        $pid = (int) $r['project_id'];
        $tot[$pid] = ($tot[$pid] ?? $init);
        $tot[$pid]['valeur']   = $r['valeur'] !== null ? (float) $r['valeur'] : null;
        $tot[$pid]['nb_biens'] = (int) $r['nb'];
    }
    foreach ($pdo->query(
        "SELECT pr.*, bs.project_id FROM prets pr
         JOIN biens_societes bs ON bs.societe = pr.societe WHERE bs.project_id IS NOT NULL"
    ) as $p) {
        $pid = (int) $p['project_id'];
        $tot[$pid] = ($tot[$pid] ?? $init);
        $s = pret_situation($p);
        if ($s['crd'] !== null) {
            $tot[$pid]['crd'] = ($tot[$pid]['crd'] ?? 0.0) + $s['crd'];
        }
        $tot[$pid]['mensualites'] += (float) ($s['mensualite'] ?? 0);
        $tot[$pid]['nb_prets']++;
    }
    return $tot;
}

/**
 * Échéances à surveiller : fin de différé (la mensualité change) dans les 90 jours ou passée
 * depuis moins de 30 jours, fin de prêt dans les 12 mois. $projectId null = toutes les sociétés.
 */
function prets_alertes(?int $projectId = null): array
{
    if (!biens_tables_pretes()) {
        return [];
    }
    $sql = "SELECT pr.*, bs.project_id, p.name AS project_name FROM prets pr
            JOIN biens_societes bs ON bs.societe = pr.societe
            LEFT JOIN projects p ON p.id = bs.project_id";
    $params = [];
    if ($projectId !== null) {
        $sql .= " WHERE bs.project_id = :p";
        $params['p'] = $projectId;
    }
    $st = get_db()->prepare($sql);
    $st->execute($params);

    $auj = new DateTimeImmutable('today');
    $alertes = [];
    foreach ($st->fetchAll() as $p) {
        if ($p['fin_differe'] !== null) {
            $d = new DateTimeImmutable($p['fin_differe']);
            if ($d >= $auj->modify('-30 days') && $d <= $auj->modify('+90 days')) {
                $alertes[] = [
                    'date' => $p['fin_differe'], 'type' => 'differe', 'pret' => $p,
                    'texte' => 'Fin du différé' . ($p['mensualite_post_differe'] !== null
                        ? ' : la mensualité passe à ' . number_format((float) $p['mensualite_post_differe'], 2, ',', "\u{202F}") . "\u{00A0}€"
                        : ''),
                ];
            }
        }
        if ($p['date_fin'] !== null) {
            $d = new DateTimeImmutable($p['date_fin']);
            if ($d >= $auj && $d <= $auj->modify('+12 months')) {
                $alertes[] = ['date' => $p['date_fin'], 'type' => 'fin', 'pret' => $p, 'texte' => 'Dernière échéance du prêt'];
            }
        }
    }
    usort($alertes, fn($a, $b) => strcmp($a['date'], $b['date']));
    return $alertes;
}
