<?php

require_once __DIR__ . '/../config/db.php';

/**
 * Charges fixes et dates anniversaires.
 *
 * L'enjeu principal n'est pas de lister des montants, c'est de prévenir AVANT
 * la date limite de résiliation : un contrat à reconduction tacite se renouvelle
 * tout seul si personne n'a résilié dans le délai de préavis.
 */

const CHARGE_CATEGORIES = [
    'assurance'  => 'Assurance',
    'energie'    => 'Énergie',
    'telecom'    => 'Télécom / Internet',
    'entretien'  => 'Entretien / Maintenance',
    'abonnement' => 'Abonnement / Logiciel',
    'loyer'      => 'Loyer',
    'pret'       => 'Prêt bancaire',
    'honoraires' => 'Honoraires',
    'autre'      => 'Autre',
];

const CHARGE_FREQUENCES = [
    'mensuel'     => ['label' => 'Mensuel',     'mois' => 1,  'par_an' => 12],
    'trimestriel' => ['label' => 'Trimestriel', 'mois' => 3,  'par_an' => 4],
    'semestriel'  => ['label' => 'Semestriel',  'mois' => 6,  'par_an' => 2],
    'annuel'      => ['label' => 'Annuel',      'mois' => 12, 'par_an' => 1],
];

/**
 * Ajoute des mois en gardant une date valide.
 * Sans cette précaution, "31 janvier + 1 mois" donnerait le 3 mars en PHP.
 */
function ajouter_mois(DateTimeImmutable $date, int $n): DateTimeImmutable
{
    $jour = (int) $date->format('j');
    $base = $date->modify('first day of this month')->modify("+{$n} months");
    $dernierJour = (int) $base->format('t');
    return $base->setDate(
        (int) $base->format('Y'),
        (int) $base->format('n'),
        min($jour, $dernierJour)
    );
}

/**
 * Prochaine échéance à partir de la date de référence, à compter d'aujourd'hui.
 */
function prochaine_echeance(string $dateReference, string $frequence, ?DateTimeImmutable $aujourdhui = null): DateTimeImmutable
{
    $aujourdhui = $aujourdhui ?? new DateTimeImmutable('today');
    $ref = new DateTimeImmutable($dateReference);

    if ($ref >= $aujourdhui) {
        return $ref;
    }

    $pas = CHARGE_FREQUENCES[$frequence]['mois'] ?? 12;
    $n = 0;
    do {
        $n += $pas;
        $candidat = ajouter_mois($ref, $n);
    } while ($candidat < $aujourdhui);

    return $candidat;
}

/**
 * Coût ramené à l'année, pour pouvoir additionner des charges de fréquences
 * différentes sans se tromper.
 */
function cout_annuel(float $montant, string $frequence): float
{
    return $montant * (CHARGE_FREQUENCES[$frequence]['par_an'] ?? 1);
}

/**
 * Enrichit une charge avec ses dates calculées et son niveau d'alerte.
 *
 * Niveaux :
 *   'depasse' — la date limite de résiliation est passée, le contrat va se
 *               reconduire (ou s'est déjà reconduit) : information la plus utile
 *   'urgent'  — moins de 30 jours pour agir
 *   'a_venir' — moins de 90 jours
 *   null      — rien à signaler
 */
function enrichir_charge(array $charge, ?DateTimeImmutable $aujourdhui = null): array
{
    $aujourdhui = $aujourdhui ?? new DateTimeImmutable('today');

    $echeance = prochaine_echeance($charge['date_reference'], $charge['frequence'], $aujourdhui);
    $charge['prochaine_echeance'] = $echeance->format('Y-m-d');
    $charge['jours_avant_echeance'] = (int) $aujourdhui->diff($echeance)->format('%r%a');

    $preavis = (int) $charge['preavis_jours'];
    $charge['date_limite_resiliation'] = null;
    $charge['jours_avant_limite'] = null;
    $charge['alerte'] = null;

    if ($preavis > 0) {
        $limite = $echeance->modify("-{$preavis} days");
        $charge['date_limite_resiliation'] = $limite->format('Y-m-d');
        $jours = (int) $aujourdhui->diff($limite)->format('%r%a');
        $charge['jours_avant_limite'] = $jours;

        if ($jours < 0) {
            $charge['alerte'] = 'depasse';
        } elseif ($jours <= 30) {
            $charge['alerte'] = 'urgent';
        } elseif ($jours <= 90) {
            $charge['alerte'] = 'a_venir';
        }
    }

    $charge['cout_annuel'] = cout_annuel((float) $charge['montant'], $charge['frequence']);

    return $charge;
}

function get_charges(int $projectId, bool $inclureInactives = false): array
{
    $sql = "SELECT * FROM fixed_charges WHERE project_id = :pid";
    if (!$inclureInactives) {
        $sql .= " AND is_active = 1";
    }
    $sql .= " ORDER BY label";

    $stmt = get_db()->prepare($sql);
    $stmt->execute(['pid' => $projectId]);

    return array_map('enrichir_charge', $stmt->fetchAll());
}

function get_charge(int $id): ?array
{
    $stmt = get_db()->prepare("SELECT * FROM fixed_charges WHERE id = :id");
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ? enrichir_charge($row) : null;
}

function create_charge(int $projectId, array $data): int
{
    $pdo = get_db();
    $stmt = $pdo->prepare(
        "INSERT INTO fixed_charges
            (project_id, label, category, fournisseur, montant, frequence,
             date_reference, preavis_jours, date_fin, notes)
         VALUES (:pid, :label, :cat, :fourn, :montant, :freq, :ref, :preavis, :fin, :notes)"
    );
    $stmt->execute([
        'pid'     => $projectId,
        'label'   => $data['label'],
        'cat'     => $data['category'],
        'fourn'   => $data['fournisseur'] ?? null,
        'montant' => $data['montant'],
        'freq'    => $data['frequence'],
        'ref'     => $data['date_reference'],
        'preavis' => $data['preavis_jours'] ?? 0,
        'fin'     => $data['date_fin'] ?? null,
        'notes'   => $data['notes'] ?? null,
    ]);
    return (int) $pdo->lastInsertId();
}

function update_charge(int $id, int $projectId, array $data): void
{
    // project_id dans le WHERE : empêche de modifier la charge d'une autre société
    // en manipulant l'identifiant dans le formulaire.
    $stmt = get_db()->prepare(
        "UPDATE fixed_charges SET
            label = :label, category = :cat, fournisseur = :fourn,
            montant = :montant, frequence = :freq, date_reference = :ref,
            preavis_jours = :preavis, date_fin = :fin, notes = :notes
         WHERE id = :id AND project_id = :pid"
    );
    $stmt->execute([
        'label'   => $data['label'],
        'cat'     => $data['category'],
        'fourn'   => $data['fournisseur'] ?? null,
        'montant' => $data['montant'],
        'freq'    => $data['frequence'],
        'ref'     => $data['date_reference'],
        'preavis' => $data['preavis_jours'] ?? 0,
        'fin'     => $data['date_fin'] ?? null,
        'notes'   => $data['notes'] ?? null,
        'id'      => $id,
        'pid'     => $projectId,
    ]);
}

function set_charge_active(int $id, int $projectId, bool $active): void
{
    $stmt = get_db()->prepare(
        "UPDATE fixed_charges SET is_active = :a WHERE id = :id AND project_id = :pid"
    );
    $stmt->execute(['a' => $active ? 1 : 0, 'id' => $id, 'pid' => $projectId]);
}

function delete_charge(int $id, int $projectId): void
{
    $stmt = get_db()->prepare("DELETE FROM fixed_charges WHERE id = :id AND project_id = :pid");
    $stmt->execute(['id' => $id, 'pid' => $projectId]);
}

/**
 * Toutes les alertes du groupe, tous projets confondus, les plus urgentes d'abord.
 * Alimente le bandeau d'alertes de la vue globale.
 */
function get_alertes_toutes_societes(int $limiteJours = 90): array
{
    $sql = "SELECT fc.*, p.name AS project_name
            FROM fixed_charges fc
            JOIN projects p ON p.id = fc.project_id
            WHERE fc.is_active = 1 AND fc.preavis_jours > 0 AND p.status = 'active'";
    $charges = array_map('enrichir_charge', get_db()->query($sql)->fetchAll());

    $alertes = array_filter($charges, fn($c) => $c['alerte'] !== null);

    usort($alertes, fn($a, $b) => $a['jours_avant_limite'] <=> $b['jours_avant_limite']);

    return $alertes;
}

/**
 * Total des charges annuelles d'une société (charges actives uniquement).
 */
function total_charges_annuelles(int $projectId): float
{
    $total = 0.0;
    foreach (get_charges($projectId) as $c) {
        $total += $c['cout_annuel'];
    }
    return $total;
}
