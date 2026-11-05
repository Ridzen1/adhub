<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/daily.php';
require_once __DIR__ . '/caisses.php';
require_once __DIR__ . '/rapport_caisse.php';

/**
 * Z quotidien L'Addition reçu par courriel.
 *
 * Chaque nuit, L'Addition envoie « L'Addition - Votre Z quotidien (Valentino | 2026-11-04) »
 * à contact@ad-hub.fr. Ce fichier lit le courriel brut (.eml, fichier de la boîte mail
 * o2switch ou entrée standard), en tire le rapport avec le même analyseur que la page
 * « Importer un rapport de caisse » (parser_rapport_caisse), puis :
 *   - écrit le résumé du jour dans daily_entries (source 'email') ;
 *   - garde le détail des ventes par produit dans laddition_z_ventes (migration 024),
 *     pour la page « Analyse des ventes ».
 *
 * Aucune extension PHP particulière : ni imap ni mailparse (absentes de PHP 8.4+ chez
 * o2switch). Le courriel est décodé ici (multipart, quoted-printable, base64).
 */

const Z_NOTE = "Z quotidien L'Addition (courriel)";

// ---------------------------------------------------------------------------
// Décodage du courriel
// ---------------------------------------------------------------------------

/** Sépare en-têtes et corps. En-têtes : [nom en minuscules => valeur décodée]. */
function z_mime_decouper(string $brut): array
{
    $brut = str_replace("\r\n", "\n", $brut);
    $pos = strpos($brut, "\n\n");
    $tete = $pos === false ? $brut : substr($brut, 0, $pos);
    $corps = $pos === false ? '' : substr($brut, $pos + 2);

    $entetes = [];
    $tete = preg_replace("/\n[ \t]+/", ' ', $tete); // lignes repliées
    foreach (explode("\n", $tete) as $ligne) {
        if (!str_contains($ligne, ':')) {
            continue;
        }
        [$nom, $valeur] = explode(':', $ligne, 2);
        $nom = strtolower(trim($nom));
        $valeur = trim($valeur);
        if (str_contains($valeur, '=?')) {
            $decode = @iconv_mime_decode($valeur, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
            if ($decode !== false) {
                $valeur = $decode;
            }
        }
        $entetes[$nom] ??= $valeur; // la première occurrence
    }
    return [$entetes, $corps];
}

/** Paramètre d'un en-tête : boundary, charset… */
function z_mime_parametre(string $entete, string $nom): ?string
{
    if (preg_match('/\b' . preg_quote($nom, '/') . '\s*=\s*("([^"]*)"|[^;\s]+)/i', $entete, $m)) {
        return isset($m[2]) && $m[2] !== '' ? $m[2] : trim($m[1], '"');
    }
    return null;
}

/**
 * Renvoie les parties texte du courriel : [['type' => 'text/html', 'contenu' => UTF-8], …].
 */
function z_mime_parties(string $brut, int $profondeur = 0): array
{
    [$entetes, $corps] = z_mime_decouper($brut);
    $type = strtolower(trim(explode(';', $entetes['content-type'] ?? 'text/plain')[0]));

    if (str_starts_with($type, 'multipart/') && $profondeur < 5) {
        $limite = z_mime_parametre($entetes['content-type'] ?? '', 'boundary');
        if ($limite === null) {
            return [];
        }
        $parties = [];
        $morceaux = preg_split('/^--' . preg_quote($limite, '/') . '(--)?[ \t]*$/m', $corps);
        foreach (array_slice($morceaux, 1) as $morceau) {
            if (trim($morceau) === '') {
                continue;
            }
            array_push($parties, ...z_mime_parties(ltrim($morceau, "\n"), $profondeur + 1));
        }
        return $parties;
    }

    if (!str_starts_with($type, 'text/')) {
        return [];
    }
    $encodage = strtolower(trim($entetes['content-transfer-encoding'] ?? ''));
    if ($encodage === 'quoted-printable') {
        $corps = quoted_printable_decode($corps);
    } elseif ($encodage === 'base64') {
        $corps = (string) base64_decode(preg_replace('/\s+/', '', $corps));
    }
    $charset = strtoupper(z_mime_parametre($entetes['content-type'] ?? '', 'charset') ?? 'UTF-8');
    if ($charset !== 'UTF-8' && $charset !== 'US-ASCII') {
        $converti = @mb_convert_encoding($corps, 'UTF-8', $charset);
        if ($converti !== false) {
            $corps = $converti;
        }
    }
    return [['type' => $type, 'contenu' => $corps]];
}

/**
 * Texte du rapport à partir du HTML : une ligne par ligne de tableau, cellules séparées
 * par des tabulations, comme un copier-coller du courriel (format attendu par
 * parser_rapport_caisse).
 */
function z_html_vers_texte(string $html): string
{
    $html = preg_replace('#<(style|script|head)\b.*?</\1>#is', '', $html);
    $html = preg_replace('#\s+#u', ' ', $html);
    $html = preg_replace('#</t[dh]\s*>#i', "\t", $html);
    $html = preg_replace('#<(br|/tr|/p|/div|/h[1-6]|/li|/table)\b[^>]*>#i', "\n", $html);
    $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    $lignes = [];
    foreach (explode("\n", $texte) as $ligne) {
        $cellules = array_map(fn($c) => trim($c, " \t\u{a0}"), explode("\t", $ligne));
        $cellules = array_values(array_filter($cellules, fn($c) => $c !== ''));
        if ($cellules) {
            $lignes[] = implode("\t", $cellules);
        }
    }
    return implode("\n", $lignes);
}

/**
 * Lit un courriel brut. Renvoie null si ce n'est pas un Z quotidien de L'Addition,
 * sinon : etablissement, jour, message_id, rapport (sortie de parser_rapport_caisse).
 */
function z_lire_courriel(string $brut): ?array
{
    [$entetes] = z_mime_decouper($brut);
    $sujet = $entetes['subject'] ?? '';
    $expediteur = strtolower($entetes['from'] ?? '');

    if (!str_contains($expediteur, 'laddition.com') || !preg_match('/Z quotidien/iu', $sujet)) {
        return null;
    }
    // « L'Addition - Votre Z quotidien  (Valentino | 2026-11-04) »
    if (!preg_match('/\(\s*(.+?)\s*\|\s*(\d{4}-\d{2}-\d{2})\s*\)/u', $sujet, $m)) {
        return null;
    }

    $html = $texte = '';
    foreach (z_mime_parties($brut) as $p) {
        if ($p['type'] === 'text/html' && $html === '') {
            $html = $p['contenu'];
        } elseif ($p['type'] === 'text/plain' && trim($texte) === '') {
            $texte = $p['contenu'];
        }
    }
    $contenu = $html !== '' ? z_html_vers_texte($html) : $texte;
    $rapport = parser_rapport_caisse($contenu);

    if (($rapport['entry_date'] ?? $m[2]) !== $m[2]) {
        $rapport['avertissements'][] = "Date du sujet ({$m[2]}) différente de celle du rapport ({$rapport['entry_date']}).";
    }
    return [
        'etablissement' => $m[1],
        'jour'          => $m[2],
        'message_id'    => trim($entetes['message-id'] ?? '', '<> '),
        'rapport'       => $rapport,
    ];
}

// ---------------------------------------------------------------------------
// Rattachement à une société et enregistrement
// ---------------------------------------------------------------------------

function z_normaliser(string $s): string
{
    $s = mb_strtolower(trim($s));
    $sans = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
    return preg_replace('/[^a-z0-9]+/', ' ', $sans !== false ? $sans : $s);
}

/**
 * Société ADHUB d'un établissement L'Addition (« Valentino »).
 * $forcees vient de config/caisses.local.php, clé 'z_quotidien' : ['Valentino' => 16].
 * Sinon : le seul restaurant actif (Restauration - Exploitation) dont le nom contient
 * celui de l'établissement. La SCI du même nom n'est pas un restaurant : écartée.
 */
function z_societe(string $etablissement, array $forcees = []): ?int
{
    foreach ($forcees as $nom => $pid) {
        if (z_normaliser((string) $nom) === z_normaliser($etablissement)) {
            return (int) $pid;
        }
    }
    $cible = ' ' . trim(z_normaliser($etablissement)) . ' ';
    if (trim($cible) === '') {
        return null;
    }
    $trouves = [];
    foreach (get_db()->query(
        "SELECT p.id, p.name FROM projects p JOIN project_types pt ON pt.id = p.project_type_id
         WHERE p.status = 'active' AND pt.code = 'restauration_exploitation'"
    ) as $p) {
        if (str_contains(' ' . trim(z_normaliser($p['name'])) . ' ', $cible)) {
            $trouves[] = (int) $p['id'];
        }
    }
    return count($trouves) === 1 ? $trouves[0] : null;
}

/** La société reçoit-elle le Z par courriel ? (pour la page Analyse) */
function z_projet_alimente(int $projectId): bool
{
    if (!caisse_z_disponible()) {
        return false;
    }
    $stmt = get_db()->prepare("SELECT 1 FROM laddition_z_ventes WHERE project_id = :pid LIMIT 1");
    $stmt->execute(['pid' => $projectId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Enregistre un Z lu par z_lire_courriel. Une journée saisie à la main est conservée,
 * sauf $force. Renvoie un message court pour le journal.
 */
function z_enregistrer(int $projectId, array $z, bool $force = false): string
{
    $r = $z['rapport'];
    $jour = $z['jour'];
    if ($r['erreurs']) {
        throw new RuntimeException(implode(' ', $r['erreurs']));
    }

    $db = get_db();
    $existante = get_daily_entry($projectId, $jour);
    $garderSaisie = $existante && $existante['source'] === 'manuel' && !$force;

    $db->beginTransaction();
    try {
        if (!$garderSaisie) {
            upsert_daily_entry(
                $projectId, $jour, (float) $r['ca_ttc'], (int) round((float) $r['couverts']),
                Z_NOTE, 'email', null,
                [
                    'ca_ht'               => $r['ca_ht'],
                    'remises'             => $r['remises'],
                    'offerts'             => $r['offerts'],
                    'ticket_moyen'        => $r['ticket_moyen'],
                    'corrections_cuisine' => $r['corrections_cuisine'],
                    'ca_solide'           => $r['ca_solide'],
                    'ca_liquide'          => $r['ca_liquide'],
                ]
            );
        }
        // Le détail des ventes est toujours gardé : il alimente l'analyse, pas la saisie.
        if (caisse_z_disponible()) {
            $db->prepare("DELETE FROM laddition_z_ventes WHERE project_id = :pid AND jour = :jour")
               ->execute(['pid' => $projectId, 'jour' => $jour]);
            $ins = $db->prepare(
                "INSERT INTO laddition_z_ventes (project_id, jour, categorie, produit, quantite, ttc, ht, recu_le)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
            $maintenant = date('Y-m-d H:i:s'); // heure de PHP, comparée à la date des fichiers
            foreach ($r['ventes'] as $v) {
                $ins->execute([$projectId, $jour, mb_substr($v['categorie'], 0, 100), mb_substr($v['produit'], 0, 255),
                               $v['quantite'], $v['ttc'], $v['ht'], $maintenant]);
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    $msg = sprintf('%s : CA %.2f €, %d couverts, %d lignes de vente', $jour, $r['ca_ttc'],
                   (int) round((float) $r['couverts']), count($r['ventes']));
    if ($garderSaisie) {
        $msg .= ' (saisie manuelle conservée, détail des ventes enregistré)';
    } elseif ($code = detecter_anomalie($projectId, $jour, (float) $r['ca_ttc'])) {
        $msg .= ' — ATTENTION : ' . libelle_anomalie($code);
    }
    foreach ($r['avertissements'] as $a) {
        $msg .= ' — ' . $a;
    }
    return $msg;
}

/**
 * Recalcule ca_solide / ca_liquide des journées importées du Z par courriel, avec le
 * classement actuel des catégories. Renvoie le nombre de journées mises à jour.
 */
function z_recalculer_familles(): int
{
    if (!caisse_z_disponible()) {
        return 0;
    }
    $db = get_db();
    $jours = [];
    foreach ($db->query(
        "SELECT project_id, jour, categorie, SUM(ttc) AS ttc FROM laddition_z_ventes
         GROUP BY project_id, jour, categorie"
    ) as $r) {
        $cle = $r['project_id'] . '|' . $r['jour'];
        $jours[$cle] ??= ['solide' => 0.0, 'liquide' => 0.0];
        $famille = caisse_famille('laddition', $r['categorie']);
        if ($famille === 'solide' || $famille === 'liquide') {
            $jours[$cle][$famille] += (float) $r['ttc'];
        }
    }
    $maj = $db->prepare(
        "UPDATE daily_entries SET ca_solide = :s, ca_liquide = :l
         WHERE project_id = :pid AND entry_date = :jour AND source = 'email' AND notes = :note"
    );
    $nb = 0;
    foreach ($jours as $cle => $f) {
        [$pid, $jour] = explode('|', $cle);
        $maj->execute([
            's' => $f['solide'] > 0 ? round($f['solide'], 2) : null,
            'l' => $f['liquide'] > 0 ? round($f['liquide'], 2) : null,
            'pid' => $pid, 'jour' => $jour, 'note' => Z_NOTE,
        ]);
        $nb += $maj->rowCount();
    }
    return $nb;
}
