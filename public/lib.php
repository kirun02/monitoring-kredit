<?php
declare(strict_types=1);
session_start();

const TEXT = ['kode_ao', 'nama_ao', 'cif', 'norek'];
const NUMF = ['plafon', 'bakidebet', 'tot_bunga', 'kw_bln_pkk', 'kw_bln_bg', 't_byr_pkk', 't_byr_bg',
              'byr_blnpkk', 'byr_blnbg', 'ppka', 'ckpn'];
const INTF = ['hari_tung', 'kolek'];
const DATEF = ['tgl_pinjam', 'tgl_tempo'];
const KOLEK_NAMA = [1 => 'Kolek 1 (Lancar)', 2 => 'Kolek 2 (DPK)', 3 => 'Kolek 3 (Kurang Lancar)',
                    4 => 'Kolek 4 (Diragukan)', 5 => 'Kolek 5 (Macet)'];

function cols(): array { return array_merge(TEXT, NUMF, INTF, DATEF); }

function db(): PDO {
    static $pdo = null;
    if (!$pdo) {
        $c = require __DIR__ . '/../config.php';
        $dsn = sprintf('pgsql:host=%s;port=%s;dbname=%s', $c['host'], $c['port'], $c['dbname']);
        $pdo = new PDO($dsn, $c['user'], $c['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        if (!$pdo->query("SELECT to_regclass('public.upload_batch')")->fetchColumn()) {
            $pdo->exec(file_get_contents(__DIR__ . '/../schema.sql'));
        }
    }
    return $pdo;
}
function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }

// ---------- format ----------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fmt_int($v): string { return number_format(round((float)$v), 0, ',', '.'); }
function jt($v): string { return fmt_int((float)$v / 1e6); }
function pct($v, int $d = 1): string { return $v === null ? '–' : number_format((float)$v, $d, ',', '.'); }
function rpm($v): string { return 'Rp ' . number_format((float)$v / 1e9, 2, ',', '.') . ' M'; }
function dlt(?float $v, string $text, bool $lowerGood = true): array {
    if ($v === null) return ['txt' => '–', 'cls' => 'muted'];
    if (round($v, 4) == 0) return ['txt' => 'tetap', 'cls' => 'muted'];
    $bad = $lowerGood ? $v > 0 : $v < 0;
    return ['txt' => ($v > 0 ? '▲ ' : '▼ ') . $text, 'cls' => $bad ? 'bad' : 'good'];
}
function flash(string $cat, string $msg): void { $_SESSION['flash'][] = [$cat, $msg]; }
function redirect(string $to) { header('Location: ' . $to); exit; }

function page_head(string $title, string $active): void {
    $flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . '</title><link rel="stylesheet" href="style.css"></head><body>'
       . '<header class="top"><a class="brand" href="index.php">Monitoring Kredit</a><nav>'
       . '<a href="index.php" class="' . ($active === 'dash' ? 'on' : '') . '">Dashboard</a>'
       . '<a href="upload.php" class="' . ($active === 'upload' ? 'on' : '') . '">Upload nominatif</a></nav></header><main>';
    foreach ($flashes as [$c, $m]) echo '<div class="flash ' . h($c) . '">' . h($m) . '</div>';
}
function page_foot(): void { echo '</main></body></html>'; }

// ---------- baca Excel ----------
function cell_text($v): string { return $v instanceof DateTimeInterface ? $v->format('Y-m-d') : trim((string)$v); }
function cell_num($v): float {
    if ($v === null || $v === '') return 0.0;
    if (is_string($v)) $v = str_replace(',', '', trim($v));
    return is_numeric($v) ? (float)$v : 0.0;
}
function cell_date($v): ?string {
    if ($v instanceof DateTimeInterface) return $v->format('Y-m-d');
    $s = trim((string)$v);
    if ($s === '') return null;
    if (is_numeric($s) && (float)$s > 1 && (float)$s < 80000) {
        return gmdate('Y-m-d', (int)round(((float)$s - 25569) * 86400));
    }
    foreach (['n/j/Y', 'Y-m-d', 'd/m/Y'] as $f) {
        $d = DateTime::createFromFormat($f, $s);
        if ($d && $d->format($f) === $s) return $d->format('Y-m-d');
    }
    $t = strtotime($s);
    return $t ? date('Y-m-d', $t) : null;
}

function col_index(string $ref): int {
    preg_match('/^[A-Z]+/', strtoupper($ref), $m);
    $n = 0;
    foreach (str_split($m[0]) as $ch) $n = $n * 26 + (ord($ch) - 64);
    return $n - 1;
}

/** Pembaca .xlsx tanpa library tambahan (ZipArchive + XMLReader): menghasilkan satu baris per iterasi. */
function xlsx_rows(string $path) {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('file bukan .xlsx yang valid');
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbXml = $zip->getFromName('xl/workbook.xml');
    $relXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wbXml !== false && $relXml !== false) {
        $wb = simplexml_load_string($wbXml);
        $rid = (string)($wb->sheets->sheet[0]->attributes('r', true)['id'] ?? '');
        foreach (simplexml_load_string($relXml)->Relationship as $r) {
            if ((string)$r['Id'] === $rid) {
                $t = ltrim((string)$r['Target'], '/');
                $sheetPath = strpos($t, 'xl/') === 0 ? $t : 'xl/' . $t;
            }
        }
    }
    $ss = [];
    $ssXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($ssXml !== false) {
        $x = new XMLReader();
        $x->XML($ssXml);
        while ($x->read()) {
            if ($x->nodeType === XMLReader::ELEMENT && $x->name === 'si') {
                preg_match_all('/<t[^>]*>(.*?)<\/t>/s', $x->readOuterXml(), $m);
                $ss[] = html_entity_decode(implode('', $m[1]), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
        $x->close();
    }
    $zip->close();
    $x = new XMLReader();
    if (!$x->open('zip://' . $path . '#' . $sheetPath)) throw new RuntimeException('sheet pertama tidak ditemukan');
    $doc = new DOMDocument();
    while ($x->read()) {
        if ($x->nodeType !== XMLReader::ELEMENT || $x->name !== 'row') continue;
        $row = [];
        foreach ($x->expand($doc)->getElementsByTagName('c') as $c) {
            $t = $c->getAttribute('t');
            $v = '';
            if ($t === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $tn) $v .= $tn->nodeValue;
            } else {
                $vn = $c->getElementsByTagName('v')->item(0);
                if ($vn) {
                    $v = $vn->nodeValue;
                    if ($t === 's') $v = $ss[(int)$v] ?? '';
                    elseif ($t === 'e') $v = '';
                }
            }
            $row[col_index($c->getAttribute('r'))] = $v;
        }
        yield $row;
    }
    $x->close();
}

/** Baca sheet pertama; kembalikan baris dengan urutan nilai sesuai cols().
 *  Satu norek hanya diambil sekali (baris pertama); jumlah baris dobel yang dibuang dikembalikan lewat $dobel. */
function parse_excel(string $path, int &$dobel = 0): array {
    $idx = null; $out = []; $seen = []; $dobel = 0;
    foreach (xlsx_rows($path) as $c) {
        if ($idx === null) {
            $idx = [];
            foreach ($c as $i => $name) $idx[strtolower(trim((string)$name))] = $i;
            $miss = array_diff(cols(), array_keys($idx));
            if ($miss) throw new RuntimeException('kolom tidak ditemukan: ' . implode(', ', $miss));
            continue;
        }
        $g = function (string $k) use ($c, $idx) { return $c[$idx[$k]] ?? null; };
        $norek = cell_text($g('norek'));
        if ($norek === '') continue;
        if (isset($seen[$norek])) { $dobel++; continue; }
        $seen[$norek] = true;
        $r = [];
        foreach (TEXT as $k) $r[] = cell_text($g($k));
        foreach (NUMF as $k) $r[] = sprintf('%.2F', cell_num($g($k)));
        foreach (INTF as $k) $r[] = (string)(int)cell_num($g($k));
        foreach (DATEF as $k) $r[] = cell_date($g($k));
        $out[] = $r;
    }
    return $out;
}

function copy_rows(PDO $pdo, int $bid, array $rows): void {
    $esc = function ($v) {
        return $v === null ? '\\N' : str_replace(['\\', "\t", "\n", "\r"], ['\\\\', '\\t', '\\n', '\\r'], (string)$v);
    };
    foreach (array_chunk($rows, 5000) as $chunk) {
        $lines = [];
        foreach ($chunk as $r) $lines[] = $bid . "\t" . implode("\t", array_map($esc, $r));
        $pdo->pgsqlCopyFromArray('nominatif', $lines, "\t", '\\N', 'batch_id,' . implode(',', cols()));
    }
}

function set_baseline(string $periode, int $bid): void {
    q('INSERT INTO periode (periode, baseline_batch_id) VALUES (?, ?) ON CONFLICT (periode) DO UPDATE SET baseline_batch_id = EXCLUDED.baseline_batch_id', [$periode, $bid]);
}