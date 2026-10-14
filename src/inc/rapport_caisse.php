<?php

/**
 * Lecture des rapports de caisse quotidiens reçus par courriel.
 *
 * Le rapport ne couvre que les services CLÔTURÉS en caisse. Si un service n'a pas
 * été fermé, la journée sera partielle ou absente — d'où la détection de valeurs
 * atypiques côté affichage (voir daily.php), qui signale sans corriger.
 */

/**
 * Répartition solide / liquide, utilisée pour le suivi du food cost.
 * À confirmer avec les équipes : toute catégorie absente des deux listes est
 * comptée à part et signalée, plutôt que rangée arbitrairement.
 */
const CATEGORIES_SOLIDE = [
    'Carte Après-Midi', 'Entrée', 'Desserts Midi', 'Plats',
    'Végé', 'Dessert Soir', 'Iodé', 'Carné', 'Glace',
];

const CATEGORIES_LIQUIDE = [
    'Boisson Chaude', 'Soft', 'Cocktail', 'Alcool',
    'Vin Au Verre', 'Vin Rosé', 'Vin Rouge', 'Vin Blanc', 'Champagne',
];

/**
 * "4 950.80" → 4950.80. Gère l'espace fine et l'espace insécable, qu'un
 * copier-coller depuis un courriel ramène souvent.
 */
function nettoyer_nombre(string $valeur): float
{
    $valeur = str_replace(["\xc2\xa0", "\xe2\x80\xaf", ' '], '', $valeur);
    $valeur = str_replace(',', '.', $valeur);
    return (float) $valeur;
}

function extraire_champ(string $texte, string $motif): ?float
{
    if (preg_match($motif, $texte, $m)) {
        return nettoyer_nombre($m[1]);
    }
    return null;
}

/**
 * Analyse le corps du rapport et renvoie les valeurs exploitables.
 * Retourne ['erreurs' => [...]] si le texte n'est pas reconnu.
 */
function parser_rapport_caisse(string $texte): array
{
    $nombre = '([\d \x{00a0}\x{202f}.,]+)';
    $res = ['erreurs' => [], 'avertissements' => []];

    // Date de la journée
    if (preg_match('/journ[ée]e du\s+(\d{4}-\d{2}-\d{2})/iu', $texte, $m)) {
        $res['entry_date'] = $m[1];
    } else {
        $res['erreurs'][] = "Date de la journée introuvable dans le rapport.";
    }

    $res['ca_ttc']       = extraire_champ($texte, '/CA TTC\s*:\s*' . $nombre . '/iu');
    $res['ca_ht']        = extraire_champ($texte, '/CA HT\s*:\s*' . $nombre . '/iu');
    $res['remises']      = extraire_champ($texte, '/Remises\s*:\s*' . $nombre . '/iu');
    $res['offerts']      = extraire_champ($texte, '/Offerts\s*:\s*' . $nombre . '/iu');
    $res['couverts']     = extraire_champ($texte, '/Couvert\(s\)\s*:\s*' . $nombre . '/iu');
    $res['panier_moyen'] = extraire_champ($texte, '/Panier moyen\s*:\s*' . $nombre . '/iu');
    $res['ticket_moyen'] = extraire_champ($texte, '/Ticket moyen\s*:\s*' . $nombre . '/iu');
    $res['corrections_cuisine'] = extraire_champ(
        $texte, '/Corrections apr[èe]s envoi en cuisine\s*:\s*' . $nombre . '/iu'
    );

    if ($res['ca_ttc'] === null) {
        $res['erreurs'][] = "Chiffre d'affaires TTC introuvable.";
    }
    if ($res['couverts'] === null) {
        $res['erreurs'][] = "Nombre de couverts introuvable.";
    }

    // Répartition solide / liquide à partir du tableau des ventes
    $solide = 0.0; $liquide = 0.0; $autre = 0.0;
    $categoriesInconnues = [];

    foreach (preg_split('/\r\n|\r|\n/', $texte) as $ligne) {
        $cols = explode("\t", $ligne);
        if (count($cols) !== 5) {
            continue;
        }
        $categorie = trim($cols[0]);
        if ($categorie === '' || $categorie === 'Catégorie') {
            continue;
        }
        if (!preg_match('/^[\d \x{00a0}\x{202f}.,]+$/u', trim($cols[3]))) {
            continue; // ligne d'en-tête ou de stock, pas une vente
        }
        $ttc = nettoyer_nombre($cols[3]);

        if (in_array($categorie, CATEGORIES_SOLIDE, true)) {
            $solide += $ttc;
        } elseif (in_array($categorie, CATEGORIES_LIQUIDE, true)) {
            $liquide += $ttc;
        } else {
            $autre += $ttc;
            $categoriesInconnues[$categorie] = true;
        }
    }

    $res['ca_solide']  = $solide > 0 ? round($solide, 2) : null;
    $res['ca_liquide'] = $liquide > 0 ? round($liquide, 2) : null;

    if ($categoriesInconnues) {
        $res['avertissements'][] = "Catégories non classées en solide/liquide : "
            . implode(', ', array_keys($categoriesInconnues))
            . " (" . number_format($autre, 2, ',', ' ') . " € non répartis).";
    }

    // Contrôle de cohérence : le panier moyen annoncé doit correspondre à CA / couverts.
    if ($res['ca_ttc'] !== null && $res['couverts'] > 0 && $res['panier_moyen'] !== null) {
        $recalcule = $res['ca_ttc'] / $res['couverts'];
        if (abs($recalcule - $res['panier_moyen']) > 0.05) {
            $res['avertissements'][] = sprintf(
                "Panier moyen annoncé (%.2f €) différent du calcul CA / couverts (%.2f €).",
                $res['panier_moyen'], $recalcule
            );
        }
    }

    return $res;
}
