<?php
/**
 * Patrimoine d'Arnaud Duvivier : ce qui lui revient dans chaque société du groupe.
 *
 * Sa « part réelle » dans une société se calcule en suivant les liens de détention
 * (project_links, type detention : project_id_a détient percentage % de project_id_b)
 * depuis sa fiche « ARNAUD DUVIVIER ». Les parts se multiplient le long d'une chaîne
 * (Arnaud 100 % AD Holding × 34 % SCI DTL × 75 % Chez Côme = 25,5 %) et s'additionnent
 * quand il détient la même société par plusieurs chemins.
 *
 * Page réservée (fortune personnelle) : seuls les comptes listés dans PATRIMOINE_EMAILS
 * la voient. Pour ajouter quelqu'un, ajouter son adresse de connexion ci-dessous.
 */
require_once __DIR__ . '/access.php';
require_once __DIR__ . '/indicateurs.php';
require_once __DIR__ . '/biens.php';
require_once __DIR__ . '/../config/db.php';

const PATRIMOINE_EMAILS = [
    'ryad.haddad10@gmail.com',   // compte admin (Direction) de Kader
    'scihoteldieu@gmail.com',    // Arnaud Duvivier
];

/** Nom de la fiche « personne » dont on calcule le patrimoine. */
const PATRIMOINE_PERSONNE = 'ARNAUD DUVIVIER';

function voit_patrimoine(): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $u = current_user();
    if ($u === null) {
        return $cache = false;
    }
    try {
        $st = get_db()->prepare('SELECT email FROM users WHERE id = :id');
        $st->execute(['id' => $u['id']]);
        $email = mb_strtolower(trim((string) $st->fetchColumn()));
    } catch (Throwable $e) {
        return $cache = false;
    }
    return $cache = in_array($email, PATRIMOINE_EMAILS, true);
}

function patrimoine_personne_id(): ?int
{
    $st = get_db()->prepare("SELECT MIN(id) FROM projects WHERE UPPER(TRIM(name)) = :n");
    $st->execute(['n' => PATRIMOINE_PERSONNE]);
    $id = $st->fetchColumn();
    return $id ? (int) $id : null;
}

/**
 * Parts réelles de $racine dans toutes les sociétés qu'elle détient, directement ou non.
 * Retour : [project_id => ['part' => 0..1, 'directe' => % ou null, 'chemins' => [[ids…], …]]]
 * Un lien sans pourcentage est ignoré (on ne devine pas la part).
 */
function patrimoine_parts(int $racine): array
{
    $liens = get_db()->query(
        "SELECT project_id_a, project_id_b, percentage FROM project_links
         WHERE link_type = 'detention' AND percentage IS NOT NULL AND percentage > 0"
    )->fetchAll();
    $enfants = [];
    foreach ($liens as $l) {
        $enfants[(int) $l['project_id_a']][] = [(int) $l['project_id_b'], (float) $l['percentage'] / 100];
    }

    $res = [];
    $parcourir = function (int $pid, float $part, array $chemin) use (&$parcourir, &$res, $enfants, $racine) {
        foreach ($enfants[$pid] ?? [] as [$fille, $pct]) {
            if ($fille === $racine || in_array($fille, $chemin, true)) {
                continue; // boucle de détention : on ne repasse pas par une société déjà vue
            }
            $p = $part * $pct;
            $res[$fille] ??= ['part' => 0.0, 'directe' => null, 'chemins' => []];
            $res[$fille]['part'] += $p;
            $res[$fille]['chemins'][] = array_merge($chemin, [$fille]);
            if ($pid === $racine) {
                $res[$fille]['directe'] = $pct * 100;
            }
            $parcourir($fille, $p, array_merge($chemin, [$fille]));
        }
    };
    $parcourir($racine, 1.0, []);
    foreach ($res as &$r) {
        $r['part'] = min($r['part'], 1.0);
    }
    return $res;
}

/**
 * Lignes de la page : une par société détenue, avec les montants de la société
 * et la quote-part d'Arnaud. Les montants absents restent null (jamais 0 inventé).
 */
function patrimoine_lignes(int $racine, int $annee): array
{
    $parts = patrimoine_parts($racine);
    if (!$parts) {
        return [];
    }
    // Biens et prêts importés (migration 027) ; à défaut, les champs saisis sur la fiche société
    $biens = biens_totaux_par_projet();
    // Ses biens et prêts en nom propre comptent à 100 %
    if (isset($biens[$racine])) {
        $parts[$racine] = ['part' => 1.0, 'directe' => null, 'chemins' => [[$racine]], 'nom_propre' => true];
    }
    $in = implode(',', array_map('intval', array_keys($parts)));
    $societes = get_db()->query(
        "SELECT p.id, p.name, p.status, p.solde_bancaire, p.solde_maj_le, p.valeur_bien, p.montant_emprunt,
                pt.code AS type_code, pt.label AS type_label
         FROM projects p JOIN project_types pt ON pt.id = p.project_type_id
         WHERE p.id IN ($in)"
    )->fetchAll();

    $lignes = [];
    foreach ($societes as $s) {
        $pid  = (int) $s['id'];
        $part = $parts[$pid]['part'];
        $r    = resultat_annee([$pid], $annee);
        $resultat = $r['mois'] > 0 ? $r['revenus'] - $r['charges'] : null;
        $solde    = $s['solde_bancaire'] !== null ? (float) $s['solde_bancaire'] : null;
        $b        = $biens[$pid] ?? null;
        $valeur   = $b && $b['valeur'] !== null ? $b['valeur'] : ($s['valeur_bien'] !== null ? (float) $s['valeur_bien'] : null);
        $emprunt  = $b && $b['crd'] !== null ? $b['crd'] : ($s['montant_emprunt'] !== null ? (float) $s['montant_emprunt'] : null);
        $mensuel  = $b && $b['nb_prets'] ? $b['mensualites'] : null;
        $lignes[] = $s + [
            'part'          => $part,
            'directe'       => $parts[$pid]['directe'],
            'chemins'       => $parts[$pid]['chemins'],
            'resultat'      => $resultat,
            'resultat_mois' => $r['mois'],
            'qp_tresorerie' => $solde !== null ? $solde * $part : null,
            'qp_resultat'   => $resultat !== null ? $resultat * $part : null,
            'qp_valeur'     => $valeur !== null ? $valeur * $part : null,
            'qp_emprunt'    => $emprunt !== null ? $emprunt * $part : null,
            'qp_mensualites'=> $mensuel !== null ? $mensuel * $part : null,
            'nom_propre'    => !empty($parts[$pid]['nom_propre']),
        ];
    }
    // Ordre : la part la plus forte d'abord, puis le nom (jamais un classement par chiffre d'affaires)
    usort($lignes, fn($a, $b) => [$b['part'], $a['name']] <=> [$a['part'], $b['name']]);
    return $lignes;
}

/** Filtres de la page : seuil de détention et famille de société. */
function patrimoine_filtrer(array $lignes, string $seuil, string $famille): array
{
    return array_values(array_filter($lignes, function ($l) use ($seuil, $famille) {
        $pct = $l['part'] * 100;
        $okSeuil = match ($seuil) {
            'controle'   => $pct > 50 - 1e-9,
            'minoritaire'=> $pct < 50 - 1e-9,
            'totale'     => $pct >= 100 - 1e-9,
            default      => true,
        };
        $okFamille = match ($famille) {
            'sci'         => in_array($l['type_code'], ['immobilier', 'restauration_murs'], true),
            'restaurants' => $l['type_code'] === 'restauration_exploitation',
            'mdb'         => $l['type_code'] === 'mdb',
            'holding'     => $l['type_code'] === 'holding',
            default       => true,
        };
        return $okSeuil && $okFamille;
    }));
}
