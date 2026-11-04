<?php
declare(strict_types=1);
/**
 * Ekspor laporan: Excel (.xlsx) lewat PhpSpreadsheet, PDF lewat mPDF. Satu definisi laporan ($spec) untuk dua format.
 *
 * Pemasangan (sekali, di folder proyek hosting yang berisi / di atas folder public):
 *     composer require phpoffice/phpspreadsheet mpdf/mpdf
 * Ekstensi PHP yang dibutuhkan: gd, zip, mbstring, xml (di XAMPP: aktifkan di php.ini lalu restart Apache).
 *
 * Kop dan logo perusahaan dibaca dari config.kop.php (sejajar dengan file ini). Tanpa file itu, laporan dicetak tanpa kop.
 *
 * $spec = [
 *   'berkas'  => 'belum_bayar',                    // awalan nama berkas
 *   'judul'   => 'Judul laporan',
 *   'meta'    => ['Posisi data: ...', 'Filter: ...'],   // baris keterangan di bawah judul
 *   'kolom'   => [ ['h' => 'Nama', 'k' => 'nama', 't' => 'text', 'w' => 24, 'xlsx' => false], ... ],
 *                 // t: text | int | rp | date | datetime | no (nomor urut); w: lebar; xlsx => true: kolom hanya di Excel
 *   'rows'    => [ ['nama' => '...', ...], ... ],
 *   'total'   => ['baki' => 123, ...] | null,      // baris JUMLAH, kunci = 'k' kolom
 *   'catatan' => ['...'],                          // baris catatan di bawah tabel
 *   'ringkasan' => ['judul' => '', 'sub' => '', 'kolom' => ['Uraian', ...], 'baris' => [[...], ...], 'catatan' => ''],
 *                 // opsional: lembar "Ringkasan" di Excel, halaman terakhir di PDF
 * ]
 */

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Halaman galat sederhana (unduhan dibuka lewat tautan, jadi galat ditampilkan sebagai halaman, bukan JSON). */
function export_galat(int $kode, string $pesan): void
{
    http_response_code($kode);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Unduhan gagal</title></head><body style="font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:12vh auto;padding:0 16px">'
       . '<h1 style="font-size:1.2rem">Unduhan tidak bisa dibuat</h1><p>' . htmlspecialchars($pesan, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<p><a href="javascript:history.back()">Kembali</a></p></body></html>';
    exit;
}

/** Muat autoload Composer (di folder ini atau satu tingkat di atasnya). */
function export_siap(): void
{
    foreach ([__DIR__ . '/vendor/autoload.php', __DIR__ . '/../vendor/autoload.php'] as $f) {
        if (is_file($f)) { require_once $f; return; }
    }
    export_galat(500, 'Pustaka PhpSpreadsheet/mPDF belum terpasang. Jalankan: composer require phpoffice/phpspreadsheet mpdf/mpdf');
}

function h_e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ---------------------------------------------------------------------------------------------
// KOP + LOGO
// ---------------------------------------------------------------------------------------------

/** Pengaturan kop dari config.kop.php: ['nama' => ..., 'baris' => [...], 'logo' => '/jalur/logo.png']; null bila tidak ada. */
function export_kop(): ?array
{
    $f = __DIR__ . '/config.kop.php';
    if (!is_file($f)) return null;
    $k = require $f;
    return (is_array($k) && trim((string)($k['nama'] ?? '')) !== '') ? $k : null;
}

/** Logo yang valid (PNG/JPEG, maks. 1 MB) beserta data URI dan ukurannya; null bila tidak ada atau tidak valid. */
function export_logo(?array $kop): ?array
{
    $p = (string)($kop['logo'] ?? '');
    if ($p === '' || !is_file($p) || !is_readable($p) || filesize($p) > 1048576) return null;
    $info = @getimagesize($p);
    if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG], true) || $info[0] < 1 || $info[1] < 1) return null;
    $mime = $info[2] === IMAGETYPE_PNG ? 'image/png' : 'image/jpeg';
    return ['path' => $p, 'w' => (int)$info[0], 'h' => (int)$info[1], 'uri' => 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($p))];
}

// ---------------------------------------------------------------------------------------------
// EXCEL
// ---------------------------------------------------------------------------------------------

/** Isi satu sel menurut jenisnya. Teks selalu disimpan sebagai teks (tidak pernah dibaca Excel sebagai rumus). */
function export_isi($ws, string $coord, $v, string $t): void
{
    if ($v === null || $v === '') return;
    switch ($t) {
        case 'no':
        case 'int':
        case 'rp':
            if (is_numeric($v)) $ws->setCellValueExplicit($coord, 0 + $v, DataType::TYPE_NUMERIC);
            return;
        case 'date':
        case 'datetime':
            $ts = strtotime((string)$v);
            if ($ts !== false) $ws->setCellValueExplicit($coord, XlDate::PHPToExcel($ts), DataType::TYPE_NUMERIC);
            return;
        default:
            $ws->setCellValueExplicit($coord, (string)$v, DataType::TYPE_STRING);
    }
}

/**
 * Lembar kedua "Ringkasan" (opsional, dari $spec['ringkasan']):
 *   ['judul' => '', 'sub' => '', 'kolom' => ['Uraian', 'Debitur', 'Pokok (Rp)', ...], 'baris' => [[teks, jumlah, rp, rp, ...], ...], 'catatan' => '']
 * Kolom pertama teks, kolom kedua bilangan bulat, sisanya Rupiah; null tampil sebagai "–".
 */
function export_xlsx_ringkasan(Spreadsheet $ss, array $rg): void
{
    $ws  = $ss->createSheet();                       // lembar aktif tetap "Laporan"
    $ws->setTitle('Ringkasan');
    $kol = array_values((array)($rg['kolom'] ?? []));
    $n   = max(1, count($kol));
    $ak  = Coordinate::stringFromColumnIndex($n);

    $ws->setCellValueExplicit('A1', (string)($rg['judul'] ?? 'Ringkasan'), DataType::TYPE_STRING);
    $ws->getStyle('A1')->getFont()->setBold(true)->setSize(13);
    $ws->setCellValueExplicit('A2', (string)($rg['sub'] ?? ''), DataType::TYPE_STRING);
    $ws->getStyle('A2')->getFont()->setItalic(true)->getColor()->setRGB('4A5961');

    $hdr = 4;
    foreach ($kol as $i => $h) {
        $ws->setCellValueExplicit(Coordinate::stringFromColumnIndex($i + 1) . $hdr, (string)$h, DataType::TYPE_STRING);
    }
    $sh = $ws->getStyle("A$hdr:$ak$hdr");
    $sh->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sh->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F6B63');
    $sh->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    $tipe = ['text', 'int', 'rp', 'rp', 'rp'];
    $r = $hdr;
    foreach ((array)($rg['baris'] ?? []) as $b) {
        $r++;
        foreach (array_values((array)$b) as $i => $v) {
            if ($i >= $n) break;
            $coord = Coordinate::stringFromColumnIndex($i + 1) . $r;
            if ($i > 0 && $v === null) $ws->setCellValueExplicit($coord, '–', DataType::TYPE_STRING);
            else export_isi($ws, $coord, $v, $tipe[$i] ?? 'text');
        }
    }
    if ($r > $hdr) {
        if ($n >= 2) {
            $st = $ws->getStyle('B' . ($hdr + 1) . ":$ak$r");
            $st->getNumberFormat()->setFormatCode('#,##0');
            $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        }
        $ws->getStyle("A$hdr:$ak$r")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C5C8');
        $ws->getStyle('A' . ($hdr + 1) . ":A$r")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
    }
    if (!empty($rg['catatan'])) {
        $r += 2;
        $ws->setCellValueExplicit("A$r", (string)$rg['catatan'], DataType::TYPE_STRING);
        $ws->mergeCells("A$r:$ak$r");
        $ws->getStyle("A$r")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $ws->getStyle("A$r")->getFont()->setItalic(true)->setSize(8);
        $ws->getRowDimension($r)->setRowHeight(36);
    }
    $ws->getColumnDimension('A')->setWidth(62);
    for ($i = 2; $i <= $n; $i++) $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(18);
    $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)
        ->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
}

function export_xlsx(array $spec, ?array $kop, string $nama): void
{
    $kol  = array_values($spec['kolom']);
    $n    = count($kol);
    $kolAkhir = Coordinate::stringFromColumnIndex($n);
    $baris = $spec['rows'];

    $ss = new Spreadsheet();
    $ss->getProperties()->setCreator('Monitoring Kredit')->setTitle((string)$spec['judul']);
    $ss->getDefaultStyle()->getFont()->setName('Arial')->setSize(9);
    $ws = $ss->getActiveSheet();
    $ws->setTitle('Laporan');

    // ---- kop + logo (baris 1-3, baris 4 jarak) ----
    $r = 0;
    if ($kop) {
        $logo  = export_logo($kop);
        $mulai = ($logo && $n >= 4) ? 3 : 1;                 // teks kop mulai dari kolom C bila ada logo (kolom A-B untuk logo)
        $cMulai = Coordinate::stringFromColumnIndex($mulai);
        $teks  = array_slice(array_merge([(string)$kop['nama']], array_map('strval', (array)($kop['baris'] ?? []))), 0, 3);
        for ($i = 1; $i <= 3; $i++) $ws->getRowDimension($i)->setRowHeight(21);
        foreach ($teks as $i => $t) {
            $rr = $i + 1;
            $ws->setCellValueExplicit("$cMulai$rr", $t, DataType::TYPE_STRING);
            $ws->mergeCells("$cMulai$rr:$kolAkhir$rr");
            $ws->getStyle("$cMulai$rr")->getFont()->setBold($i === 0)->setSize($i === 0 ? 14 : 9);
            $ws->getStyle("$cMulai$rr")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);
        }
        if ($logo) {
            $tinggi = 60.0;
            $lebar  = $tinggi * $logo['w'] / $logo['h'];
            if ($lebar > 190) $tinggi = 190 * $logo['h'] / $logo['w'];       // logo lebar dibatasi agar tidak menimpa teks kop
            $d = new Drawing();
            $d->setName('Logo')->setDescription('Logo perusahaan')->setPath($logo['path']);
            $d->setHeight((int)round($tinggi))->setCoordinates('A1')->setOffsetX(4)->setOffsetY(3);
            $d->setWorksheet($ws);
        }
        $ws->getStyle("A3:{$kolAkhir}3")->getBorders()->getBottom()->setBorderStyle(Border::BORDER_MEDIUM)->getColor()->setRGB('0F6B63');
        $r = 4;
    }

    // ---- judul + keterangan ----
    $r++;
    $ws->setCellValueExplicit("A$r", (string)$spec['judul'], DataType::TYPE_STRING);
    $ws->mergeCells("A$r:$kolAkhir$r");
    $ws->getStyle("A$r")->getFont()->setBold(true)->setSize(13);
    foreach (($spec['meta'] ?? []) as $m) {
        $r++;
        $ws->setCellValueExplicit("A$r", (string)$m, DataType::TYPE_STRING);
        $ws->mergeCells("A$r:$kolAkhir$r");
        $ws->getStyle("A$r")->getFont()->setItalic(true)->getColor()->setRGB('4A5961');
    }
    $r += 2;

    // ---- judul kolom ----
    $hdr = $r;
    foreach ($kol as $i => $c) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $ws->setCellValueExplicit($col . $hdr, (string)$c['h'], DataType::TYPE_STRING);
        $ws->getColumnDimension($col)->setWidth((float)($c['w'] ?? 14));
    }
    $sh = $ws->getStyle("A$hdr:$kolAkhir$hdr");
    $sh->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
    $sh->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('0F6B63');
    $sh->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER)->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);

    // ---- isi ----
    $no = 0;
    foreach ($baris as $row) {
        $r++;
        $no++;
        foreach ($kol as $i => $c) {
            $t = (string)($c['t'] ?? 'text');
            $v = $t === 'no' ? $no : ($row[$c['k'] ?? ''] ?? null);
            export_isi($ws, Coordinate::stringFromColumnIndex($i + 1) . $r, $v, $t);
        }
    }
    if (!$baris) {
        $r++;
        $ws->setCellValueExplicit("A$r", 'Tidak ada data untuk filter ini.', DataType::TYPE_STRING);
        $ws->mergeCells("A$r:$kolAkhir$r");
    }
    $awal  = $hdr + 1;
    $akhir = $r;

    // format per kolom (satu kali per kolom, bukan per sel)
    foreach ($kol as $i => $c) {
        $col = Coordinate::stringFromColumnIndex($i + 1);
        $st  = $ws->getStyle("$col$awal:$col$akhir");
        switch ((string)($c['t'] ?? 'text')) {
            case 'rp':
                $st->getNumberFormat()->setFormatCode('#,##0');
                $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                break;
            case 'int':
            case 'no':
                $st->getNumberFormat()->setFormatCode('0');
                $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                break;
            case 'date':
                $st->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                break;
            case 'datetime':
                $st->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
                $st->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
                break;
            default:
                $st->getAlignment()->setWrapText(true);
        }
    }
    $ws->getStyle("A$awal:$kolAkhir$akhir")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

    // ---- baris JUMLAH ----
    if (!empty($spec['total']) && $baris) {
        $r++;
        $first = null;
        foreach ($kol as $i => $c) {
            $k = $c['k'] ?? '';
            if ($k !== '' && array_key_exists($k, $spec['total'])) {
                if ($first === null) $first = $i;
                export_isi($ws, Coordinate::stringFromColumnIndex($i + 1) . $r, $spec['total'][$k], (string)$c['t']);
                $ws->getStyle(Coordinate::stringFromColumnIndex($i + 1) . $r)->getNumberFormat()->setFormatCode('#,##0');
            }
        }
        $labelCol = Coordinate::stringFromColumnIndex(max(1, (int)$first));
        $ws->setCellValueExplicit("$labelCol$r", 'JUMLAH', DataType::TYPE_STRING);
        $ws->getStyle("$labelCol$r")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        $sj = $ws->getStyle("A$r:$kolAkhir$r");
        $sj->getFont()->setBold(true);
        $sj->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E7EFEE');
        $akhir = $r;
    }

    $ws->getStyle("A$hdr:$kolAkhir$akhir")->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('B9C5C8');

    // ---- catatan ----
    $r = $akhir + 1;
    foreach (($spec['catatan'] ?? []) as $cat) {
        $r++;
        $ws->setCellValueExplicit("A$r", (string)$cat, DataType::TYPE_STRING);
        $ws->mergeCells("A$r:$kolAkhir$r");
        $ws->getStyle("A$r")->getFont()->setItalic(true)->setSize(8);
    }

    // ---- tampilan dan cetak ----
    $ws->freezePane('A' . ($hdr + 1));
    if ($baris) $ws->setAutoFilter("A$hdr:$kolAkhir" . ($awal + count($baris) - 1));
    $ps = $ws->getPageSetup();
    $ps->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4);
    $ps->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    $ps->setRowsToRepeatAtTopByStartAndEnd($hdr, $hdr);
    $ws->getPageMargins()->setTop(0.5)->setBottom(0.6)->setLeft(0.4)->setRight(0.4);
    $ws->getHeaderFooter()->setOddFooter('&L&8Monitoring Kredit&C&8Halaman &P dari &N&R&8&D');

    if (!empty($spec['ringkasan'])) export_xlsx_ringkasan($ss, (array)$spec['ringkasan']);
    $ss->setActiveSheetIndex(0);

    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    (new Xlsx($ss))->save('php://output');
}

// ---------------------------------------------------------------------------------------------
// PDF (mPDF langsung: kop, judul kolom berulang di tiap halaman, nomor halaman)
// ---------------------------------------------------------------------------------------------

/** Nilai sel untuk tampilan PDF. */
function export_fmt_pdf($v, string $t, int $no): string
{
    if ($t === 'no') return (string)$no;
    if ($v === null || $v === '') return '';
    switch ($t) {
        case 'int':  return is_numeric($v) ? (string)(int)$v : h_e($v);
        case 'rp':   return is_numeric($v) ? number_format((float)$v, 0, ',', '.') : h_e($v);
        case 'date': $ts = strtotime((string)$v); return $ts === false ? h_e($v) : date('d/m/Y', $ts);
        case 'datetime': $ts = strtotime((string)$v); return $ts === false ? h_e($v) : date('d/m/Y H:i', $ts);
        default:     return h_e($v);
    }
}

/** Satu tabel data untuk PDF (judul kolom, isi, baris JUMLAH). $kol sudah tanpa kolom khusus Excel. */
function export_pdf_tabel(array $kol, array $baris, ?array $total, string $kosong = 'Tidak ada data untuk filter ini.'): string
{
    $jumlahW = 0.0;
    foreach ($kol as $c) $jumlahW += (float)($c['w'] ?? 14);

    $b = '<table class="data"><thead><tr>';
    foreach ($kol as $c) {
        $w = round(100 * (float)($c['w'] ?? 14) / max(1.0, $jumlahW), 2);
        $b .= '<th style="width:' . $w . '%">' . h_e($c['h']) . '</th>';
    }
    $b .= '</tr></thead><tbody>';

    $no = 0;
    foreach ($baris as $row) {
        $no++;
        $b .= '<tr>';
        foreach ($kol as $c) {
            $t   = (string)($c['t'] ?? 'text');
            $cls = ($t === 'rp') ? ' class="num"' : (in_array($t, ['int', 'no', 'date', 'datetime'], true) ? ' class="ctr"' : '');
            $b  .= '<td' . $cls . '>' . export_fmt_pdf($t === 'no' ? null : ($row[$c['k'] ?? ''] ?? null), $t, $no) . '</td>';
        }
        $b .= '</tr>';
    }
    if (!$baris) $b .= '<tr><td colspan="' . count($kol) . '" class="ctr">' . h_e($kosong) . '</td></tr>';

    if (!empty($total) && $baris) {
        $first = null;
        foreach ($kol as $i => $c) {
            if (($c['k'] ?? '') !== '' && array_key_exists($c['k'], $total)) { $first = $i; break; }
        }
        if ($first !== null) {
            $b .= '<tr class="jml"><td colspan="' . max(1, $first) . '" class="num">JUMLAH</td>';
            for ($i = max(1, $first); $i < count($kol); $i++) {
                $k = $kol[$i]['k'] ?? '';
                $b .= '<td class="num">' . ($k !== '' && array_key_exists($k, $total) ? number_format((float)$total[$k], 0, ',', '.') : '') . '</td>';
            }
            $b .= '</tr>';
        }
    }
    return $b . '</tbody></table>';
}

/** Halaman ringkasan di PDF (halaman baru, dari $spec['ringkasan']; bentuknya sama dengan untuk Excel). */
function export_pdf_ringkasan(array $rg): string
{
    $kol = array_values((array)($rg['kolom'] ?? []));
    $b  = '<pagebreak /><h1>' . h_e($rg['judul'] ?? 'Ringkasan') . '</h1><div class="meta">' . h_e($rg['sub'] ?? '') . '</div>';
    $b .= '<table class="data"><thead><tr>';
    foreach ($kol as $i => $h) {
        $b .= '<th style="width:' . ($i === 0 ? '46' : round(54 / max(1, count($kol) - 1), 2)) . '%">' . h_e($h) . '</th>';
    }
    $b .= '</tr></thead><tbody>';
    foreach ((array)($rg['baris'] ?? []) as $row) {
        $b .= '<tr>';
        foreach (array_values((array)$row) as $i => $v) {
            if ($i === 0) {
                $t = (string)$v;
                $b .= '<td>' . str_repeat('&nbsp;', strlen($t) - strlen(ltrim($t))) . h_e(ltrim($t)) . '</td>';
            } elseif ($v === null) {
                $b .= '<td class="num">–</td>';
            } else {
                $b .= '<td class="num">' . number_format((float)$v, 0, ',', '.') . '</td>';
            }
        }
        $b .= '</tr>';
    }
    $b .= '</tbody></table>';
    if (!empty($rg['catatan'])) $b .= '<div class="cat">' . h_e($rg['catatan']) . '</div>';
    return $b;
}

function export_pdf(array $spec, ?array $kop, string $nama): void
{
    @ini_set('display_errors', '0');          // pesan PHP ke log, bukan ke browser
    ob_start(); 
    // kolom khusus Excel tidak ikut ke PDF
    $tanpaXlsx = function (array $kolom) { return array_values(array_filter($kolom, function ($c) { return empty($c['xlsx']); })); };
    // 'bagian' (opsional): beberapa tabel berurutan dalam satu PDF, tiap bagian = ['judul', 'kolom', 'rows', 'total']
    $bagian = (!empty($spec['bagian']) && is_array($spec['bagian'])) ? $spec['bagian'] : null;

    $css = 'body{font-family:dejavusanscondensed;font-size:8pt;color:#14232B}
        table.kop{width:100%;border-collapse:collapse}
        td.kl{width:24mm;vertical-align:middle}
        td.kt{text-align:center;vertical-align:middle}
        .k1{font-size:14pt;font-weight:bold}
        .k2{font-size:8pt;color:#4A5961}
        .kgaris{border-top:1.2mm solid #0F6B63;margin:2mm 0 4mm}
        h1{font-size:12pt;text-align:center;margin:0 0 1mm}
        .meta{font-size:7.5pt;color:#4A5961;text-align:center}
        table.data{width:100%;border-collapse:collapse;margin-top:4mm}
        table.data th{background-color:#0F6B63;color:#FFFFFF;font-weight:bold;border:0.2mm solid #B9C5C8;padding:1.2mm}
        table.data td{border:0.2mm solid #B9C5C8;padding:1mm 1.2mm;vertical-align:top}
        .num{text-align:right} .ctr{text-align:center}
        tr.jml td{font-weight:bold;background-color:#E7EFEE}
        h2{font-size:10pt;color:#0F6B63;margin:6mm 0 0}
        .cat{font-size:7pt;font-style:italic;color:#4A5961;margin-top:2mm}';

    $b = '';
    if ($kop) {
        $logo = export_logo($kop);
        $b .= '<table class="kop"><tr>';
        $b .= '<td class="kl">' . ($logo ? '<img src="' . $logo['uri'] . '" style="height:16mm">' : '') . '</td>';
        $b .= '<td class="kt"><div class="k1">' . h_e($kop['nama']) . '</div>';
        foreach (array_slice((array)($kop['baris'] ?? []), 0, 3) as $t) $b .= '<div class="k2">' . h_e($t) . '</div>';
        $b .= '</td><td class="kl"></td></tr></table><div class="kgaris"></div>';
    }
    $b .= '<h1>' . h_e($spec['judul']) . '</h1>';
    foreach (($spec['meta'] ?? []) as $m) $b .= '<div class="meta">' . h_e($m) . '</div>';

    if ($bagian) {
        foreach ($bagian as $bg) {
            $b .= '<h2>' . h_e($bg['judul'] ?? '') . '</h2>';
            $b .= export_pdf_tabel($tanpaXlsx((array)$bg['kolom']), (array)($bg['rows'] ?? []), $bg['total'] ?? null, (string)($bg['kosong'] ?? 'Tidak ada data.'));
        }
    } else {
        $b .= export_pdf_tabel($tanpaXlsx((array)$spec['kolom']), (array)$spec['rows'], $spec['total'] ?? null);
    }
    foreach (($spec['catatan'] ?? []) as $cat) $b .= '<div class="cat">' . h_e($cat) . '</div>';
    if (!empty($spec['ringkasan'])) $b .= export_pdf_ringkasan((array)$spec['ringkasan']);

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($tmp)) @mkdir($tmp, 0775, true);

    $pdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4-L',
        'margin_left' => 10, 'margin_right' => 10, 'margin_top' => 10, 'margin_bottom' => 14,
        'tempDir' => $tmp,
    ]);
    $pdf->packTableData = true;                       // hemat memori untuk tabel panjang
    $pdf->SetTitle((string)$spec['judul']);
    $pdf->SetAuthor('Monitoring Kredit');
    $pdf->SetFooter('Monitoring Kredit|Halaman {PAGENO} dari {nbpg}|{DATE d/m/Y H:i}');
    $pdf->WriteHTML($css, 1);                         // 1 = CSS
    $pdf->WriteHTML($b, 2);                           // 2 = isi

    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: no-store, max-age=0');
    $pdf->Output($nama, 'I');                         // 'I' = tampil di browser (untuk dicetak)
}

// ---------------------------------------------------------------------------------------------
// PINTU MASUK
// ---------------------------------------------------------------------------------------------

/** Bangun berkas dan kirim ke browser. $format: 'xlsx' (diunduh) atau 'pdf' (dibuka di tab untuk dicetak). */
function export_kirim(array $spec, string $format): void
{
    export_siap();
    @set_time_limit(180);
    @ini_set('memory_limit', '512M');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // unduhan panjang tidak mengunci sesi

    $pdf  = $format === 'pdf';
    $kop  = $spec['kop'] ?? export_kop();
    $nama = preg_replace('/[^A-Za-z0-9_\-]/', '_', (string)($spec['berkas'] ?? 'laporan')) . '_' . date('Y-m-d') . '.' . ($pdf ? 'pdf' : 'xlsx');

    try {
        if ($pdf) export_pdf($spec, $kop, $nama); else export_xlsx($spec, $kop, $nama);
    } catch (Throwable $e) {
        error_log('export_kirim: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
        if (!headers_sent()) {
            header_remove('Content-Disposition');   // halaman galat harus tampil, bukan terunduh
            export_galat(500, 'Berkas gagal dibuat. Coba persempit filter, atau hubungi admin.');
        }
    }
    exit;
}