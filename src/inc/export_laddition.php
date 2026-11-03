<?php

require_once __DIR__ . '/daily.php';
require_once __DIR__ . '/../cron/caisses/xlsx_reader.php';

/**
 * Import de l'export Excel « Détails par service » du back-office L'Addition.
 *
 * Une ligne de l'export = une clôture de caisse (Z), en général une par journée.
 * On en tire le résumé du jour dans daily_entries (source 'email', comme les autres
 * imports de fichier) : CA TTC/HT, couverts, remises, offerts, ticket moyen par
 * addition, ventes solide / liquide (alcool + sans alcool).
 *
 * Les colonnes sont retrouvées par leur titre, pas par leur position : L'Addition
 * peut en ajouter (un taux de TVA, un mode de paiement) sans casser l'import.
 */

/** Titre de colonne de l'export → clé interne. */
const LADDITION_COLONNES = [
    'service'    => 'Services',          // « Services (Valentino) » : on compare le début
    'ca_ttc'     => 'CA TTC Net',
    'ca_ht'      => 'CA HT',
    'remises'    => 'Remises',
    'offerts'    => 'Offerts',
    'solide'     => 'CA TTC Solide',
    'alcool'     => 'CA TTC Alcool',
    'non_alcool' => 'CA TTC Non alcool',
    'couverts'   => 'Couverts',
    'commandes'  => 'Commandes',
    'commentaire'=> 'Commentaires',
];

function laddition_normaliser_titre(string $t): string
{
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $t)));
}

/**
 * Lit les lignes de l'export (sortie de xlsxRows) et renvoie :
 *   'etablissement' => nom lu dans l'en-tête (« Valentino »), ou null
 *   'jours'         => [date Y-m-d => champs du jour], trié par date
 *   'ignores'       => [[date, motif]] : services de test, journées à 0 €
 *   'erreurs'       => messages bloquants (fichier non reconnu)
 *
 * Plusieurs clôtures le même jour (service rouvert) sont additionnées.
 * La journée retenue est celle de l'ouverture du service (« Du 12/09/2025 (23:51)… »),
 * comme sur le Z de caisse.
 */
function parser_export_laddition(array $rows): array
{
    $res = ['etablissement' => null, 'jours' => [], 'ignores' => [], 'erreurs' => []];

    // Ligne d'en-tête : la première dont une cellule commence par « Services »
    $entete = null;
    foreach ($rows as $i => $r) {
        foreach ($r as $cell) {
            if (is_string($cell) && str_starts_with(laddition_normaliser_titre($cell), 'services')) {
                $entete = $i;
                break 2;
            }
        }
        if ($i > 10) break;
    }
    if ($entete === null) {
        $res['erreurs'][] = "Ce fichier ne ressemble pas à l'export « Détails par service » de L'Addition (colonne « Services » introuvable).";
        return $res;
    }

    $index = [];
    foreach ($rows[$entete] as $col => $titre) {
        if (!is_string($titre)) continue;
        $t = laddition_normaliser_titre($titre);
        foreach (LADDITION_COLONNES as $cle => $attendu) {
            $a = laddition_normaliser_titre($attendu);
            if ($t === $a || ($cle === 'service' && str_starts_with($t, $a))) {
                $index[$cle] ??= $col;
            }
        }
        if (preg_match('/^services\s*\((.+)\)$/iu', trim($titre), $m)) {
            $res['etablissement'] = trim($m[1]);
        }
    }
    foreach (['service', 'ca_ttc', 'couverts'] as $obligatoire) {
        if (!isset($index[$obligatoire])) {
            $res['erreurs'][] = "Colonne « " . LADDITION_COLONNES[$obligatoire] . " » introuvable dans le fichier.";
        }
    }
    if ($res['erreurs']) return $res;

    $nombre = fn(array $r, string $cle): ?float =>
        isset($index[$cle]) && is_numeric($r[$index[$cle]] ?? null) ? (float) $r[$index[$cle]] : null;

    foreach (array_slice($rows, $entete + 1) as $r) {
        $libelle = (string) ($r[$index['service']] ?? '');
        if (!preg_match('#^Du (\d{2})/(\d{2})/(\d{4})#u', trim($libelle), $m)) {
            continue; // ligne TOTAL, ligne vide
        }
        $jour = "{$m[3]}-{$m[2]}-{$m[1]}";
        $ca   = $nombre($r, 'ca_ttc') ?? 0.0;
        $commentaire = trim((string) ($r[$index['commentaire'] ?? -1] ?? ''));

        if (preg_match('/\btest\b/iu', $commentaire)) {
            $res['ignores'][] = [$jour, 'service de test'];
            continue;
        }
        if (abs($ca) < 0.005) {
            $res['ignores'][] = [$jour, 'clôture à 0 €'];
            continue;
        }

        $j = &$res['jours'][$jour];
        $j ??= ['ca_ttc' => 0.0, 'ca_ht' => 0.0, 'remises' => 0.0, 'offerts' => 0.0,
                'ca_solide' => 0.0, 'ca_liquide' => 0.0, 'couverts' => 0, 'commandes' => 0, 'clotures' => 0];
        $j['ca_ttc']     += $ca;
        $j['ca_ht']      += $nombre($r, 'ca_ht') ?? 0.0;
        $j['remises']    += $nombre($r, 'remises') ?? 0.0;
        $j['offerts']    += $nombre($r, 'offerts') ?? 0.0;
        $j['ca_solide']  += $nombre($r, 'solide') ?? 0.0;
        $j['ca_liquide'] += ($nombre($r, 'alcool') ?? 0.0) + ($nombre($r, 'non_alcool') ?? 0.0);
        $j['couverts']   += (int) round($nombre($r, 'couverts') ?? 0);
        $j['commandes']  += (int) round($nombre($r, 'commandes') ?? 0);
        $j['clotures']++;
        unset($j);
    }

    ksort($res['jours']);
    if (!$res['jours']) {
        $res['erreurs'][] = "Aucune journée avec du chiffre d'affaires dans ce fichier.";
    }
    return $res;
}

/**
 * Enregistre les journées dans daily_entries pour un restaurant.
 * Une journée saisie à la main est conservée, sauf $remplacerManuel.
 * Les journées déjà importées (fichier, courriel, caisse) sont mises à jour.
 * Renvoie ['ecrites' => n, 'conservees' => [dates saisies à la main laissées telles quelles]].
 */
function importer_export_laddition(int $projectId, array $jours, ?int $userId, bool $remplacerManuel = false): array
{
    $ecrites = 0;
    $conservees = [];
    $db = get_db();
    $db->beginTransaction();
    try {
        foreach ($jours as $date => $j) {
            $existante = get_daily_entry($projectId, $date);
            if ($existante && $existante['source'] === 'manuel' && !$remplacerManuel) {
                $conservees[] = $date;
                continue;
            }
            upsert_daily_entry(
                $projectId, $date, round($j['ca_ttc'], 2), (int) $j['couverts'],
                'Import export L\'Addition', 'email', $userId,
                [
                    'ca_ht'        => round($j['ca_ht'], 2),
                    'remises'      => round($j['remises'], 2),
                    'offerts'      => round($j['offerts'], 2),
                    'ticket_moyen' => $j['commandes'] > 0 ? round($j['ca_ttc'] / $j['commandes'], 2) : null,
                    'ca_solide'    => round($j['ca_solide'], 2),
                    'ca_liquide'   => round($j['ca_liquide'], 2),
                ]
            );
            $ecrites++;
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return ['ecrites' => $ecrites, 'conservees' => $conservees];
}
