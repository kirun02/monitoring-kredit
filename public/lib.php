<?php
declare(strict_types=1);
session_start();

const TEXT = ['kode_ao', 'nama_ao', 'cif', 'norek'];
const NUMF = ['plafon', 'bakidebet', 'tot_bunga', 'kw_bln_pkk', 'kw_bln_bg', 't_byr_pkk', 't_byr_bg',
              'byr_blnpkk', 'byr_blnbg', 'ppka', 'ckpn'];
const INTF = ['hari_tung', 'kolek'];
const DATEF = ['tgl_pinjam', 'tgl_tempo'];
// Kolom opsional: kalau tidak ada di Excel, upload tetap jalan dan isinya kosong.
// Nilai = nama header yang dikenali (huruf kecil), dipakai urut dari yang pertama ketemu.
const OPTF = ['nama_nasabah', 'alamat'];
const OPT_ALIAS = [
    'nama_nasabah' => ['namadb', 'nama_nasabah', 'nama nasabah', 'nama_debitur', 'nama debitur', 'nama_nsb', 'nasabah', 'debitur', 'nama'],
    'alamat'       => ['alamatdb', 'alamat', 'alamat_nasabah', 'alamat nasabah', 'alamat_debitur', 'alamat debitur', 'alamat_ktp', 'alamat ktp'],
];
// Kolek 3 dimulai di atas sekian hari tunggak. Sesuaikan dengan kebijakan kantor (dipakai menu Prioritas tagih).
const BATAS_NPL_HARI = 90;
const KOLEK_NAMA = [1 => 'Kolek 1 (Lancar)', 2 => 'Kolek 2 (DPK)', 3 => 'Kolek 3 (Kurang Lancar)',
                    4 => 'Kolek 4 (Diragukan)', 5 => 'Kolek 5 (Macet)'];

function cols(): array { return array_merge(TEXT, NUMF, INTF, DATEF, OPTF); }
function cols_wajib(): array { return array_merge(TEXT, NUMF, INTF, DATEF); }

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
        // migrasi: kolom nama_nasabah & alamat (tidak berbuat apa-apa kalau sudah ada)
        $ada = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = current_schema()
                                 AND table_name = 'nominatif' AND column_name IN ('nama_nasabah', 'alamat')")->fetchColumn();
        if ($ada < 2) {
            $pdo->exec('ALTER TABLE nominatif ADD COLUMN IF NOT EXISTS nama_nasabah character(100) DEFAULT \'\', ADD COLUMN IF NOT EXISTS alamat text');
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
       . '<a href="pergerakan.php" class="' . ($active === 'gerak' ? 'on' : '') . '">Pergerakan</a>'
       . '<a href="prioritas.php" class="' . ($active === 'prioritas' ? 'on' : '') . '">Prioritas tagih</a>'
       . '<a href="nasabah.php" class="' . ($active === 'debitur' ? 'on' : '') . '">Debitur</a>'
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
function parse_excel(string $path, int &$dobel = 0, array &$optAda = []): array {
    $idx = null; $out = []; $seen = []; $dobel = 0; $optAda = []; $optIdx = [];
    foreach (xlsx_rows($path) as $c) {
        if ($idx === null) {
            $idx = [];
            foreach ($c as $i => $name) $idx[strtolower(trim((string)$name))] = $i;
            $miss = array_diff(cols_wajib(), array_keys($idx));
            if ($miss) throw new RuntimeException('kolom tidak ditemukan: ' . implode(', ', $miss));
            foreach (OPTF as $k) {
                $optIdx[$k] = null;
                foreach (OPT_ALIAS[$k] as $alias) if (isset($idx[$alias])) { $optIdx[$k] = $idx[$alias]; $optAda[] = $k; break; }
            }
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
        foreach (OPTF as $k) {
            $t = $optIdx[$k] === null ? '' : cell_text($c[$optIdx[$k]] ?? null);
            if ($k === 'nama_nasabah') $t = (string)preg_replace('/^(.{0,100}).*$/su', '$1', $t); // kolom character(100): potong 100 karakter
            $r[] = $t === '' ? null : $t;
        }
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

// ---------- pembantu bersama (Pergerakan, Prioritas tagih, Debitur) ----------
/** Rupiah penuh, mis. 'Rp 1.234.567.890' */
function rp($v): string { return 'Rp ' . fmt_int($v); }

/** Batch posisi untuk tanggal tertentu (harian); kosong = terbaru. Cadangan: batch apa pun yang terbaru. */
function batch_posisi(string $tgl): ?array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = '';
    $r = q("SELECT * FROM upload_batch WHERE jenis = 'harian'" . ($tgl ? ' AND tgl_posisi = ?' : '') .
           ' ORDER BY tgl_posisi DESC, id DESC LIMIT 1', $tgl ? [$tgl] : [])->fetch();
    if (!$r) $r = q('SELECT * FROM upload_batch ORDER BY tgl_posisi DESC, id DESC LIMIT 1')->fetch();
    return $r ?: null;
}

/** Daftar tanggal upload harian untuk dropdown (tanggal posisi $cur selalu ikut). */
function tanggal_harian(array $cur): array {
    $dates = [];
    foreach (q("SELECT DISTINCT tgl_posisi FROM upload_batch WHERE jenis = 'harian' ORDER BY tgl_posisi DESC LIMIT 90")->fetchAll() as $t) {
        $dates[] = ['v' => $t['tgl_posisi'], 'l' => date('d M Y', strtotime($t['tgl_posisi']))];
    }
    if (!in_array($cur['tgl_posisi'], array_column($dates, 'v'), true)) {
        array_unshift($dates, ['v' => $cur['tgl_posisi'], 'l' => date('d M Y', strtotime($cur['tgl_posisi']))]);
    }
    return $dates;
}

/** Tentukan batch pembanding: 'kemarin', 'baseline', atau tanggal upload harian (Y-m-d). */
function pembanding(array $cur, string $banding): array {
    if ($banding !== 'baseline' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $banding, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1]))) {
        $banding = 'kemarin';
    }
    if ($banding === $cur['tgl_posisi']) $banding = 'kemarin';
    if ($banding === 'baseline') {
        $id = q('SELECT baseline_batch_id FROM periode WHERE periode = ?', [$cur['periode']])->fetchColumn() ?: null;
    } elseif ($banding === 'kemarin') {
        $id = q("SELECT id FROM upload_batch WHERE jenis = 'harian' AND tgl_posisi < ? ORDER BY tgl_posisi DESC, id DESC LIMIT 1", [$cur['tgl_posisi']])->fetchColumn() ?: null;
    } else {
        $id = q("SELECT id FROM upload_batch WHERE jenis = 'harian' AND tgl_posisi = ? LIMIT 1", [$banding])->fetchColumn() ?: null;
    }
    return ['banding' => $banding, 'id' => $id === null ? null : (int)$id,
            'nama' => in_array($banding, ['kemarin', 'baseline'], true) ? $banding : date('d/m/Y', strtotime($banding))];
}

/** Pergerakan kolek: debitur yang sama (norek) di posisi ini vs pembanding. */
function kolek_gerak(int $cur, int $prv): array
{
    $moves = q('SELECT LEAST(GREATEST(p.kolek, 1), 5) AS dari, LEAST(GREATEST(c.kolek, 1), 5) AS ke,
                       COUNT(*) AS debitur, SUM(c.bakidebet) AS nominal
                FROM nominatif c JOIN nominatif p ON p.norek = c.norek AND p.batch_id = :prv
                WHERE c.batch_id = :cur GROUP BY 1, 2', ['cur' => $cur, 'prv' => $prv])->fetchAll();

    $list = []; $masuk = [0, 0.0]; $keluar = [0, 0.0];
    foreach ($moves as $m) {
        $d = (int)$m['dari']; $k = (int)$m['ke'];
        if ($d === $k) continue;
        $n = (int)$m['debitur']; $nom = (float)$m['nominal'];
        $list[] = ['dari' => $d, 'ke' => $k, 'worse' => $k > $d, 'n' => $n, 'nom' => $nom];
        if ($d <= 2 && $k >= 3) { $masuk[0] += $n; $masuk[1] += $nom; }
        if ($d >= 3 && $k <= 2) { $keluar[0] += $n; $keluar[1] += $nom; }
    }
    usort($list, function ($a, $b) {
        return [$b['worse'], $b['nom']] <=> [$a['worse'], $a['nom']]; // memburuk dulu, lalu nominal terbesar
    });
    $rows = [];
    foreach ($list as $x) {
        $rows[] = ['label' => 'Kolek ' . $x['dari'] . ' → Kolek ' . $x['ke'],
                   'ket' => $x['worse'] ? 'Memburuk' : 'Membaik', 'cls' => $x['worse'] ? 'bad' : 'good',
                   'debitur' => fmt_int($x['n']), 'nominal' => fmt_int($x['nom'])];
    }

    // debitur yang hanya ada di salah satu posisi
    $baru = q('SELECT COUNT(*) AS n, COALESCE(SUM(c.bakidebet), 0) AS nom,
                      COALESCE(SUM(c.bakidebet) FILTER (WHERE c.kolek >= 3), 0) AS npl
               FROM nominatif c WHERE c.batch_id = :cur
                 AND NOT EXISTS (SELECT 1 FROM nominatif p WHERE p.batch_id = :prv AND p.norek = c.norek)',
              ['cur' => $cur, 'prv' => $prv])->fetch();
    $hilang = q('SELECT COUNT(*) AS n, COALESCE(SUM(p.bakidebet), 0) AS nom,
                        COALESCE(SUM(p.bakidebet) FILTER (WHERE p.kolek >= 3), 0) AS npl
                 FROM nominatif p WHERE p.batch_id = :prv
                   AND NOT EXISTS (SELECT 1 FROM nominatif c WHERE c.batch_id = :cur AND c.norek = p.norek)',
                ['cur' => $cur, 'prv' => $prv])->fetch();

    return [
        'rows' => $rows,
        'masuk' => ['n' => fmt_int($masuk[0]), 'nom' => rp($masuk[1])],
        'keluar' => ['n' => fmt_int($keluar[0]), 'nom' => rp($keluar[1])],
        'baru' => ['n' => fmt_int($baru['n']), 'nom' => rp($baru['nom']), 'npl' => rp($baru['npl'])],
        'hilang' => ['n' => fmt_int($hilang['n']), 'nom' => rp($hilang['nom']), 'npl' => rp($hilang['npl'])],
    ];
}