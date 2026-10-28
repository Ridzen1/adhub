<?php

/**
 * Petites briques d'affichage partagées par les pages : montants, variations,
 * tuiles d'indicateurs, icônes et en-tête d'une société avec ses onglets.
 */

require_once __DIR__ . '/access.php';

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

/** 12 345 € (espace insécable fine entre milliers, comme en français) */
function eur(?float $v, int $dec = 0): string
{
    if ($v === null) {
        return '—';
    }
    return number_format($v, $dec, ',', "\u{202F}") . "\u{00A0}€";
}

/** Montant compact pour les grands nombres : 1,2 M€, 625 k€ */
function eur_court(?float $v): string
{
    if ($v === null) {
        return '—';
    }
    $a = abs($v);
    if ($a >= 1_000_000) {
        return number_format($v / 1_000_000, 2, ',', "\u{202F}") . "\u{00A0}M€";
    }
    if ($a >= 10_000) {
        return number_format($v / 1000, 1, ',', "\u{202F}") . "\u{00A0}k€";
    }
    return eur($v);
}

function pourcent(?float $v, int $dec = 0): string
{
    return $v === null ? '—' : number_format($v, $dec, ',', "\u{202F}") . "\u{00A0}%";
}

/**
 * Pastille de variation (+4,2 %). $inverse : une hausse est mauvaise (charges, food cost).
 * Rien n'est affiché sans référence : une flèche sans base trompe plus qu'elle n'informe.
 */
function delta(?float $variationPct, bool $inverse = false, string $suffixe = ''): string
{
    if ($variationPct === null) {
        return '';
    }
    $sens = abs($variationPct) < 0.5 ? 'flat' : ($variationPct > 0 ? 'up' : 'down');
    $classe = $sens;
    if ($inverse && $sens !== 'flat') {
        $classe = $sens === 'up' ? 'down' : 'up';
    }
    $fleche = $sens === 'flat' ? '→' : ($sens === 'up' ? '↑' : '↓');
    $signe  = $variationPct > 0 ? '+' : '';
    return '<span class="delta ' . $classe . '">' . $fleche . ' ' . $signe
        . number_format($variationPct, 1, ',', "\u{202F}") . '&nbsp;%</span>'
        . ($suffixe !== '' ? ' <span>' . h($suffixe) . '</span>' : '');
}

/** Écart en euros (+10 533 €), pour un résultat où un % n'a pas de sens (base faible ou négative) */
function delta_eur(?float $ecart, string $suffixe = ''): string
{
    if ($ecart === null) {
        return '';
    }
    $classe = abs($ecart) < 1 ? 'flat' : ($ecart > 0 ? 'up' : 'down');
    return '<span class="delta ' . $classe . '">' . ($ecart > 0 ? '+' : '') . eur($ecart) . '</span>'
        . ($suffixe !== '' ? ' <span>' . h($suffixe) . '</span>' : '');
}

/** Variation en % sans dépendre de periodes.php */
function ecart_pct(?float $actuel, ?float $reference): ?float
{
    if ($actuel === null || $reference === null || abs($reference) < 0.01) {
        return null;
    }
    return ($actuel - $reference) / abs($reference) * 100;
}

/**
 * Tuile d'indicateur. $opts : icon, foot (HTML), hero (bool), href, spark (id d'un canvas), class
 */
function kpi(string $label, string $valeurHtml, array $opts = []): string
{
    $tag   = isset($opts['href']) ? 'a' : 'div';
    $href  = isset($opts['href']) ? ' href="' . h($opts['href']) . '"' : '';
    $class = 'kpi' . (!empty($opts['hero']) ? ' kpi-hero' : '') . (isset($opts['class']) ? ' ' . $opts['class'] : '');
    $icon  = isset($opts['icon']) ? '<span class="kpi-icon">' . icon($opts['icon'], 15) . '</span>' : '';
    $html  = "<{$tag} class=\"{$class}\"{$href}>"
           . '<div class="kpi-label">' . $icon . h($label) . '</div>'
           . '<div class="kpi-value">' . $valeurHtml . '</div>';
    if (!empty($opts['foot'])) {
        $html .= '<div class="kpi-foot">' . $opts['foot'] . '</div>';
    }
    if (!empty($opts['spark'])) {
        $html .= '<div class="kpi-spark"><canvas id="' . h($opts['spark']) . '"></canvas></div>';
    }
    return $html . "</{$tag}>";
}

/** « sept. 26 » à partir d'une date */
function mois_court(string $date): string
{
    static $noms = [1 => 'janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'];
    return $noms[(int) substr($date, 5, 2)] . ' ' . substr($date, 2, 2);
}

function initiales(string $nom): string
{
    $mots = preg_split('/\s+/', trim($nom)) ?: [];
    $i = '';
    foreach (array_slice($mots, 0, 2) as $m) {
        $i .= mb_strtoupper(mb_substr($m, 0, 1));
    }
    return $i ?: '?';
}

/** Famille visuelle d'un type de société (couleur des pastilles) */
function type_famille(?string $typeCode): string
{
    return match ($typeCode) {
        'immobilier', 'restauration_murs' => 't-sci',
        'restauration_exploitation'       => 't-resto',
        'mdb'                             => 't-mdb',
        'holding'                         => 't-holding',
        default                           => '',
    };
}

function type_chip(?string $typeCode, ?string $label): string
{
    return '<span class="type-chip ' . type_famille($typeCode) . '"><span class="type-dot"></span>' . h($label) . '</span>';
}

/**
 * Icônes au trait (dessin inspiré de Lucide, licence ISC), inline pour ne dépendre d'aucun CDN.
 */
function icon(string $nom, int $taille = 18): string
{
    static $chemins = [
        'grid'      => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
        'building'  => '<rect x="4" y="2" width="16" height="20" rx="2"/><path d="M9 22v-4h6v4M8 6h.01M16 6h.01M12 6h.01M12 10h.01M12 14h.01M16 10h.01M16 14h.01M8 10h.01M8 14h.01"/>',
        'settings'  => '<path d="M12.22 2h-.44a2 2 0 0 0-2 2v.18a2 2 0 0 1-1 1.73l-.43.25a2 2 0 0 1-2 0l-.15-.08a2 2 0 0 0-2.73.73l-.22.38a2 2 0 0 0 .73 2.73l.15.1a2 2 0 0 1 1 1.72v.51a2 2 0 0 1-1 1.74l-.15.09a2 2 0 0 0-.73 2.73l.22.38a2 2 0 0 0 2.73.73l.15-.08a2 2 0 0 1 2 0l.43.25a2 2 0 0 1 1 1.73V20a2 2 0 0 0 2 2h.44a2 2 0 0 0 2-2v-.18a2 2 0 0 1 1-1.73l.43-.25a2 2 0 0 1 2 0l.15.08a2 2 0 0 0 2.73-.73l.22-.39a2 2 0 0 0-.73-2.73l-.15-.08a2 2 0 0 1-1-1.74v-.5a2 2 0 0 1 1-1.74l.15-.09a2 2 0 0 0 .73-2.73l-.22-.38a2 2 0 0 0-2.73-.73l-.15.08a2 2 0 0 1-2 0l-.43-.25a2 2 0 0 1-1-1.73V4a2 2 0 0 0-2-2z"/><circle cx="12" cy="12" r="3"/>',
        'chevron'   => '<path d="m6 9 6 6 6-6"/>',
        'search'    => '<circle cx="11" cy="11" r="7"/><path d="m21 21-4.3-4.3"/>',
        'wallet'    => '<path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/>',
        'trend'     => '<path d="M22 7 13.5 15.5 8.5 10.5 2 17"/><path d="M16 7h6v6"/>',
        'utensils'  => '<path d="M3 2v7c0 1.1.9 2 2 2h4a2 2 0 0 0 2-2V2M7 2v20M21 15V2a5 5 0 0 0-5 5v6c0 1.1.9 2 2 2h3Zm0 0v7"/>',
        'home'      => '<path d="m3 9 9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><path d="M9 22V12h6v10"/>',
        'briefcase' => '<rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/>',
        'layers'    => '<path d="m12 2 10 5-10 5L2 7z"/><path d="m2 17 10 5 10-5M2 12l10 5 10-5"/>',
        'alert'     => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/>',
        'users'     => '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
        'receipt'   => '<path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/>',
        'calendar'  => '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
        'percent'   => '<path d="M19 5 5 19"/><circle cx="6.5" cy="6.5" r="2.5"/><circle cx="17.5" cy="17.5" r="2.5"/>',
        'key'       => '<circle cx="7.5" cy="15.5" r="5.5"/><path d="m21 2-9.6 9.6M15.5 7.5l3 3L22 7l-3-3"/>',
        'logout'    => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/>',
        'menu'      => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
        'plus'      => '<path d="M12 5v14M5 12h14"/>',
        'file'      => '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/><path d="M14 2v4a2 2 0 0 0 2 2h4"/>',
        'clock'     => '<circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/>',
        'pie'       => '<path d="M21.21 15.89A10 10 0 1 1 8 2.83"/><path d="M22 12A10 10 0 0 0 12 2v10z"/>',
        'bank'      => '<path d="M3 22h18M6 18v-7M10 18v-7M14 18v-7M18 18v-7M12 2l8 5H4z"/>',
        'target'    => '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/>',
        'trophy'    => '<path d="M6 9H4.5a2.5 2.5 0 0 1 0-5H6M18 9h1.5a2.5 2.5 0 0 0 0-5H18M4 22h16M10 14.66V17c0 .55-.47.98-.97 1.21C7.85 18.75 7 20.24 7 22M14 14.66V17c0 .55.47.98.97 1.21C16.15 18.75 17 20.24 17 22"/><path d="M18 2H6v7a6 6 0 0 0 12 0V2Z"/>',
        'flag'      => '<path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1zM4 22v-7"/>',
        'flame'     => '<path d="M8.5 14.5A2.5 2.5 0 0 0 11 12c0-1.38-.5-2-1-3-1.07-2.14-.22-4.05 2-6 .5 2.5 2 4.9 4 6.5 2 1.6 3 3.5 3 5.5a7 7 0 1 1-14 0c0-1.15.43-2.29 1-3a2.5 2.5 0 0 0 2.5 2.5z"/>',
    ];
    $p = $chemins[$nom] ?? '';
    return '<svg width="' . $taille . '" height="' . $taille . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>';
}

/** Première page de la société que l'utilisateur a le droit d'ouvrir */
function lien_societe(int $pid): string
{
    if (can_view_project($pid, 'dashboard')) {
        return '/pages/project_dashboard.php?id=' . $pid;
    }
    if (can_view_project($pid, 'documents')) {
        return '/pages/project_documents.php?id=' . $pid;
    }
    return '/pages/project_detail.php?id=' . $pid;
}

/**
 * En-tête commun des pages d'une société : nom, type, onglets.
 * $actif : clé de l'onglet courant (dashboard, saisie, evolution, analyse, objectifs, fiche, charges, documents).
 * $actions : HTML des boutons à droite (optionnel).
 */
/** Vrai si un export Pennylane a été relié à cette société (onglet Comptabilité) */
function societe_a_compta_pennylane(int $projectId): bool
{
    try {
        $st = get_db()->prepare("SELECT 1 FROM pennylane_societes WHERE project_id = :id LIMIT 1");
        $st->execute(['id' => $projectId]);
        return (bool) $st->fetchColumn();
    } catch (PDOException $e) {
        return false; // migration 013 pas encore passée
    }
}

function company_header(array $project, string $actif, string $actions = '', string $meta = ''): string
{
    $id = (int) $project['id'];
    $type = get_db()->prepare("SELECT code, label FROM project_types WHERE id = :id");
    $type->execute(['id' => $project['project_type_id']]);
    $t = $type->fetch() ?: ['code' => null, 'label' => ''];
    $resto = $t['code'] === 'restauration_exploitation';

    $onglets = [
        'dashboard' => ['Tableau de bord', '/pages/project_dashboard.php', 'dashboard'],
    ];
    if ($resto) {
        $onglets['analyse']   = ['Analyse des ventes', '/pages/analyse_caisse.php', 'dashboard'];
        $onglets['evolution'] = ['Évolution', '/pages/evolution.php', 'dashboard'];
        $onglets['saisie']    = ['Saisie quotidienne', '/pages/saisie_quotidienne.php', 'dashboard'];
        $onglets['objectifs'] = ['Objectifs', '/pages/objectifs.php', 'dashboard'];
    }
    $onglets['charges']   = ['Charges fixes', '/pages/charges.php', 'dashboard'];
    if (societe_a_compta_pennylane($id)) {
        $onglets['compta'] = ['Comptabilité', '/pages/comptabilite.php', 'dashboard'];
    }
    $onglets['documents'] = ['Documents', '/pages/project_documents.php', 'documents'];
    $onglets['fiche']     = ['Fiche société', '/pages/project_detail.php', 'fiche'];

    $tabs = '';
    foreach ($onglets as $cle => [$libelle, $url, $section]) {
        if (!can_view_project($id, $section)) {
            continue;
        }
        $tabs .= '<a href="' . $url . '?id=' . $id . '"' . ($cle === $actif ? ' class="active" aria-current="page"' : '') . '>'
               . h($libelle) . '</a>';
    }

    $statut = ($project['status'] ?? 'active') === 'archived' ? ' <span class="badge badge-archived">Archivée</span>' : '';

    return '<div class="company-head">'
         . '<div class="company-head-top"><div>'
         . '<div class="page-eyebrow">' . type_chip($t['code'], $t['label']) . $statut . '</div>'
         . '<h1>' . h($project['name']) . '</h1>'
         . ($meta !== '' ? '<div class="company-meta">' . $meta . '</div>' : '')
         . '</div>'
         . ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '')
         . '</div>'
         . '<nav class="company-tabs">' . $tabs . '</nav>'
         . '</div>';
}

/** En-tête simple d'une page hors société */
function page_header(string $titre, string $sousTitre = '', string $actions = '', string $eyebrow = ''): string
{
    return '<div class="page-head"><div>'
         . ($eyebrow !== '' ? '<div class="page-eyebrow">' . $eyebrow . '</div>' : '')
         . '<h1>' . h($titre) . '</h1>'
         . ($sousTitre !== '' ? '<p class="page-sub">' . $sousTitre . '</p>' : '')
         . '</div>'
         . ($actions !== '' ? '<div class="page-actions">' . $actions . '</div>' : '')
         . '</div>';
}
