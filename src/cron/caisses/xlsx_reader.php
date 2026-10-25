<?php
/**
 * Lecteur .xlsx minimal (première feuille), sans dépendance : ZipArchive + SimpleXML.
 * Renvoie un tableau de lignes, chaque ligne = tableau indexé par colonne (0 = A).
 * Gère les types : s (texte partagé), inlineStr, str, b (booléen), n (nombre).
 */
declare(strict_types=1);

function xlsxRows(string $xlsxBinary): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("Extension PHP 'zip' manquante (ZipArchive)");
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $xlsxBinary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Fichier Excel illisible (pas un .xlsx valide)');
    }

    // Textes partagés
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if ($ss !== false) {
        $x = simplexml_load_string($ss);
        foreach ($x->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string) $si->t;
            } else { // texte enrichi : concaténer les morceaux
                $txt = '';
                foreach ($si->r as $r) $txt .= (string) $r->t;
                $shared[] = $txt;
            }
        }
    }

    // Première feuille (via workbook.xml.rels pour être robuste)
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $wb   = $zip->getFromName('xl/workbook.xml');
    if ($rels !== false && $wb !== false) {
        $wbx = simplexml_load_string($wb);
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $wbx->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($first) {
            $rid = (string) $first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach (simplexml_load_string($rels)->Relationship as $rel) {
                if ((string) $rel['Id'] === $rid) {
                    $t = ltrim((string) $rel['Target'], '/');
                    $sheetPath = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
                }
            }
        }
    }
    $sheetXml = $zip->getFromName($sheetPath);
    $zip->close();
    @unlink($tmp);
    if ($sheetXml === false) throw new RuntimeException("Feuille introuvable dans l'Excel");

    $rows = [];
    $sx = simplexml_load_string($sheetXml);
    foreach ($sx->sheetData->row as $row) {
        $line = [];
        $max  = -1;
        foreach ($row->c as $c) {
            $col = colIndex((string) $c['r']);
            $t   = (string) $c['t'];
            $v   = isset($c->v) ? (string) $c->v : null;
            switch ($t) {
                case 's':         $val = $shared[(int) $v] ?? ''; break;
                case 'inlineStr': $val = isset($c->is->t) ? (string) $c->is->t : ''; break;
                case 'str':       $val = $v ?? ''; break;
                case 'b':         $val = $v === '1'; break;
                default:          $val = ($v === null || $v === '') ? null : (str_contains($v, '.') || stripos($v, 'e') !== false ? (float) $v : (int) $v);
            }
            $line[$col] = $val;
            $max = max($max, $col);
        }
        $out = [];
        for ($i = 0; $i <= $max; $i++) $out[$i] = $line[$i] ?? null;
        $rows[] = $out;
    }
    return $rows;
}

/** "B12" -> 1 ; "AA3" -> 26 */
function colIndex(string $ref): int
{
    $letters = preg_replace('/\d+/', '', $ref);
    $n = 0;
    foreach (str_split($letters) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/**
 * Toutes les feuilles d'un .xlsx : [nom de la feuille => lignes] (mêmes conventions que xlsxRows).
 * Les cellules de type date restent des numéros de série Excel : voir xlsxDate().
 */
function xlsxSheets(string $xlsxBinary): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException("Extension PHP 'zip' manquante (ZipArchive)");
    }
    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    file_put_contents($tmp, $xlsxBinary);
    $zip = new ZipArchive();
    if ($zip->open($tmp) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Fichier Excel illisible (pas un .xlsx valide)');
    }
    try {
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            foreach (simplexml_load_string($ss)->si as $si) {
                if (isset($si->t)) {
                    $shared[] = (string) $si->t;
                } else {
                    $txt = '';
                    foreach ($si->r as $r) $txt .= (string) $r->t;
                    $shared[] = $txt;
                }
            }
        }

        $cibles = [];
        foreach (simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'))->Relationship as $rel) {
            $t = ltrim((string) $rel['Target'], '/');
            $cibles[(string) $rel['Id']] = str_starts_with($t, 'xl/') ? $t : 'xl/' . $t;
        }
        $wbx = simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'));
        $wbx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $feuilles = [];
        foreach ($wbx->xpath('//m:sheets/m:sheet') as $sheet) {
            $rid = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $xml = isset($cibles[$rid]) ? $zip->getFromName($cibles[$rid]) : false;
            if ($xml === false) {
                continue;
            }
            $rows = [];
            foreach (simplexml_load_string($xml)->sheetData->row as $row) {
                $line = [];
                $max  = -1;
                foreach ($row->c as $c) {
                    $col = colIndex((string) $c['r']);
                    $t   = (string) $c['t'];
                    $v   = isset($c->v) ? (string) $c->v : null;
                    switch ($t) {
                        case 's':         $val = $shared[(int) $v] ?? ''; break;
                        case 'inlineStr': $val = isset($c->is->t) ? (string) $c->is->t : ''; break;
                        case 'str':       $val = $v ?? ''; break;
                        case 'b':         $val = $v === '1'; break;
                        default:          $val = ($v === null || $v === '') ? null : (str_contains($v, '.') || stripos($v, 'e') !== false ? (float) $v : (int) $v);
                    }
                    $line[$col] = $val;
                    $max = max($max, $col);
                }
                $out = [];
                for ($i = 0; $i <= $max; $i++) $out[$i] = $line[$i] ?? null;
                $rows[] = $out;
            }
            $feuilles[(string) $sheet['name']] = $rows;
        }
        return $feuilles;
    } finally {
        $zip->close();
        @unlink($tmp);
    }
}

/** Numéro de série Excel (ou texte jj/mm/aaaa, aaaa-mm-jj) → 'Y-m-d', sinon null */
function xlsxDate($v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    if (is_int($v) || is_float($v)) {
        return gmdate('Y-m-d', (int) round(($v - 25569) * 86400));
    }
    $v = trim((string) $v);
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})#', $v, $m)) {
        return "$m[3]-$m[2]-$m[1]";
    }
    if (preg_match('#^\d{4}-\d{2}-\d{2}#', $v)) {
        return substr($v, 0, 10);
    }
    return null;
}
