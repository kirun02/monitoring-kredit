<?php
/**
 * Excel "Versi 2" untuk menu Debitur: lembar "Ringkasan" (aging hari tunggak posisi berjalan dibanding posisi akhir bulan kemarin,
 * ditambah dua rasio) dan dua lembar rincian (Rincian 1-30 hari, Rincian 31-60 hari; satu baris per debitur).
 * Berdiri sendiri: export.php yang lama TIDAK diubah. Letak: folder api/.
 *
 *   GET export_debitur_v2.php?tgl=YYYY-MM-DD&ao=
 *
 *   rasio 1 = debitur hari tunggak 1-30 bulan berjalan / debitur kolek 1 akhir bulan lalu
 *   rasio 2 = debitur hari tunggak 31-60 bulan berjalan / debitur hari tunggak 1-30 akhir bulan lalu
 *   (dari JUMLAH debitur dan baki debet tiap kelompok, bukan dari perpindahan debitur yang sama)
 *
 * Data diambil lewat API backend (jenis 'debitur_aging', terenkripsi). Butuh monitoring_helper.php dan Monitoring.php versi baru.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';   // autoload PhpSpreadsheet dan export_galat()
akses_atau_403('web_debitur');
date_default_timezone_set('Asia/Jakarta');

if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {       // cadangan bila export_lib tidak memuat autoload
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $al) {
        if (is_file($al)) { require_once $al; break; }
    }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as XlDate;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

@ini_set('memory_limit', '512M');
@set_time_limit(150);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('export_debitur_v2 FATAL: ' . $e['message'] . ' @' . basename($e['file']) . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
        echo 'Excel v2 gagal dibuat (fatal): ' . substr($e['message'], 0, 200) . ' @' . basename($e['file']) . ':' . $e['line'];
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');

// ---------------------------------------------------------------- validasi
$tgl = trim((string)($_GET['tgl'] ?? ''));
$ao  = trim((string)($_GET['ao'] ?? ''));
$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if ($tgl !== '' && !$tglOk($tgl))                                export_galat(422, 'Tanggal posisi tidak valid.');
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) export_galat(422, 'Kode AO tidak valid.');

try {
    $p = [];
    if ($tgl !== '') $p['tgl'] = $tgl;
    if ($ao !== '')  $p['ao']  = $ao;

    $r = api_call('debitur_aging', ['params' => $p], api_cfg()['path_monitoring'], 120);
    if (($r['status'] ?? '') !== 'success') export_galat(500, 'API data gagal: ' . (string)($r['message'] ?? 'tanpa pesan') . ' (cek error_log server API, cari "Monitoring/debitur_aging gagal")');
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));
    if (!isset($d['rasio'], $d['cur'])) export_galat(500, 'Server data belum diperbarui (monitoring_helper.php / Monitoring.php belum memuat debitur_aging).');

    // ---------------------------------------------------------------- bahan
    $c      = (array)$d['cur'];
    $v      = (isset($d['prv']) && is_array($d['prv'])) ? (array)$d['prv'] : null;
    $rasio  = (array)$d['rasio'];
    $rows   = (array)($d['rows'] ?? []);
    $namaAo = (string)($d['ao']['nama'] ?? 'Semua AO');
    $fmt    = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m/Y', $x); };
    $tCur   = $fmt($c['tgl']);
    $tPrv   = $v ? $fmt($v['tgl']) : null;
    $tTarget = $fmt($d['akhir_lalu_target'] ?? '');

    $HIJAU = '0E6B63';
    $FONT  = 'Arial';
    $garis = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]]];
    $kepala = [
        'font' => ['name' => $FONT, 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $HIJAU]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ];

    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName($FONT)->setSize(10);

    // ================================================================ LEMBAR 1: RINGKASAN
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Ringkasan');
    $sh->setCellValue('A1', 'Ringkasan Debitur – Aging Hari Tunggak');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->setCellValue('A2', 'AO: ' . $namaAo . '   |   Posisi bulan ini ' . $tCur . '   |   Dibandingkan dengan posisi akhir bulan lalu ' . ($tPrv ?? '(belum ada)'));
    $sh->setCellValue('A3', 'Dicetak ' . date('d/m/Y H:i'));

    // ---- tabel 1: aging
    $sh->setCellValue('A5', 'Kelompok hari tunggak');
    $sh->mergeCells('A5:A6');
    $sh->setCellValue('B5', 'BULAN INI (posisi ' . $tCur . ')');          $sh->mergeCells('B5:C5');
    $sh->setCellValue('D5', 'AKHIR BULAN LALU (posisi ' . ($tPrv ?? 'belum ada') . ')'); $sh->mergeCells('D5:E5');
    $sh->setCellValue('F5', 'SELISIH (bulan ini − akhir bulan lalu)');          $sh->mergeCells('F5:G5');
    foreach (['B', 'D', 'F'] as $col) {
        $sh->setCellValue($col . '6', 'Debitur');
        $sh->setCellValue(chr(ord($col) + 1) . '6', 'Baki debet (Rp)');
    }
    $sh->getStyle('A5:G6')->applyFromArray($kepala);
    $sh->getRowDimension(5)->setRowHeight(24);
    $sh->getRowDimension(6)->setRowHeight(20);

    $i = 7;
    $vAging = $v ? (array)$v['aging'] : [];
    foreach ((array)$c['aging'] as $k => $a) {
        $pa = $vAging[$k] ?? null;
        $sh->setCellValue("A$i", (string)$a['label']);
        $sh->setCellValue("B$i", (int)$a['n']);
        $sh->setCellValue("C$i", (float)$a['nom']);
        if ($pa) {
            $sh->setCellValue("D$i", (int)$pa['n']);
            $sh->setCellValue("E$i", (float)$pa['nom']);
            $sh->setCellValue("F$i", "=B$i-D$i");
            $sh->setCellValue("G$i", "=C$i-E$i");
        } else {
            foreach (['D', 'E', 'F', 'G'] as $col) $sh->setCellValue("$col$i", '–');
        }
        if ($k === 1 || $k === 2) $sh->getStyle("A$i:G$i")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFF8E1');
        $i++;
    }
    $sh->setCellValue("A$i", 'TOTAL');
    $sh->setCellValue("B$i", '=SUM(B7:B' . ($i - 1) . ')');
    $sh->setCellValue("C$i", '=SUM(C7:C' . ($i - 1) . ')');
    if ($v) {
        $sh->setCellValue("D$i", '=SUM(D7:D' . ($i - 1) . ')');
        $sh->setCellValue("E$i", '=SUM(E7:E' . ($i - 1) . ')');
        $sh->setCellValue("F$i", "=B$i-D$i");
        $sh->setCellValue("G$i", "=C$i-E$i");
    } else {
        foreach (['D', 'E', 'F', 'G'] as $col) $sh->setCellValue("$col$i", '–');
    }
    $sh->getStyle("A$i:G$i")->getFont()->setBold(true);
    $sh->getStyle("A$i:G$i")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    $sh->getStyle("A7:G$i")->applyFromArray($garis);
    $sh->getStyle("B7:G$i")->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0');
    $sh->getStyle("B7:G$i")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $akhirTabel1 = $i;

    // ---- tabel 2: rasio
    $i = $akhirTabel1 + 2;
    $sh->setCellValue("A$i", 'Perbandingan dengan akhir bulan lalu (persentase = bulan ini ÷ akhir bulan lalu)');
    $sh->getStyle("A$i")->getFont()->setBold(true)->setSize(12);
    $i++;
    $hr = $i;
    foreach (['A' => 'Uraian', 'B' => "Debitur\nbulan ini", 'C' => "Debitur\nakhir bulan lalu", 'D' => "Persentase\ndebitur",
              'E' => "Baki debet\nbulan ini (Rp)", 'F' => "Baki debet\nakhir bulan lalu (Rp)", 'G' => "Persentase\nbaki debet", 'H' => 'Lihat daftar debitur'] as $col => $t) {
        $sh->setCellValue($col . $hr, $t);
    }
    $sh->getStyle("A$hr:H$hr")->applyFromArray($kepala);
    $sh->getRowDimension($hr)->setRowHeight(32);
    $i = $hr + 1;
    $tautan = ['Rincian 1-30 hari', 'Rincian 31-60 hari'];
    $uraian = ['1. Debitur menunggak 1–30 hari bulan ini, dibanding debitur kolek 1 akhir bulan lalu',
               '2. Debitur menunggak 31–60 hari bulan ini, dibanding debitur menunggak 1–30 hari akhir bulan lalu'];
    foreach ($rasio as $n => $x) {
        $sh->setCellValue("A$i", $uraian[$n] ?? (string)$x['judul']);
        $sh->setCellValue("B$i", (int)$x['pembilang_n']);
        $sh->setCellValue("E$i", (float)$x['pembilang_nom']);
        if ($x['penyebut_n'] !== null) {
            $sh->setCellValue("C$i", (int)$x['penyebut_n']);
            $sh->setCellValue("F$i", (float)$x['penyebut_nom']);
            $sh->setCellValue("D$i", "=IF(C$i=0,\"–\",B$i/C$i)");
            $sh->setCellValue("G$i", "=IF(F$i=0,\"–\",E$i/F$i)");
        } else {
            foreach (['C', 'D', 'F', 'G'] as $col) $sh->setCellValue("$col$i", '–');
        }
        $sh->setCellValue("H$i", $tautan[$n]);
        $sh->getCell("H$i")->getHyperlink()->setUrl("sheet://'" . $tautan[$n] . "'!A1");
        $sh->getStyle("H$i")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1'))->setUnderline('single');
        $sh->getStyle("A$i")->getAlignment()->setWrapText(true);
        $i++;
    }
    $akhirTabel2 = $i - 1;
    $sh->getStyle("A$hr:H$akhirTabel2")->applyFromArray($garis);
    $sh->getStyle("B" . ($hr + 1) . ":C$akhirTabel2")->getNumberFormat()->setFormatCode('#,##0');
    $sh->getStyle("E" . ($hr + 1) . ":F$akhirTabel2")->getNumberFormat()->setFormatCode('#,##0');
    $sh->getStyle("D" . ($hr + 1) . ":D$akhirTabel2")->getNumberFormat()->setFormatCode('0.0%');
    $sh->getStyle("G" . ($hr + 1) . ":G$akhirTabel2")->getNumberFormat()->setFormatCode('0.0%');
    $sh->getStyle("B" . ($hr + 1) . ":G$akhirTabel2")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    $sh->getStyle("D" . ($hr + 1) . ":D$akhirTabel2")->getFont()->setBold(true);
    $sh->getStyle("G" . ($hr + 1) . ":G$akhirTabel2")->getFont()->setBold(true);

    // ---- catatan
    $cat = [
        'Catatan:',
        '• Bulan ini = posisi ' . $tCur . '. Akhir bulan lalu = posisi harian terakhir pada atau sebelum ' . $tTarget . ($tPrv ? ', yaitu posisi ' . $tPrv . '.' : ' (belum ada di sistem).'),
        '• Kelompok memakai kolom hari tunggak (Lancar = 0 hari). Kolek 1 = kolek 1 pada posisi akhir bulan lalu.',
        '• Persentase dihitung dari JUMLAH debitur (dan baki debet) tiap kelompok, bukan dari perpindahan debitur yang sama. Perbandingan 1 = debitur yang menunggak 1–30 hari bulan ini dibagi debitur kolek 1 akhir bulan lalu. Perbandingan 2 = debitur yang menunggak 31–60 hari bulan ini dibagi debitur yang menunggak 1–30 hari akhir bulan lalu; di atas 100% berarti debitur 31–60 hari bulan ini lebih banyak daripada debitur 1–30 hari akhir bulan lalu.',
        '• Daftar debitur tiap perbandingan ada di lembar Rincian (klik tulisan di kolom paling kanan). Jumlah baris tiap lembar sama dengan angka "Debitur bulan ini".',
        '• Baris tabel aging berwarna kuning = kelompok yang dipakai di perbandingan. Selisih positif = bertambah dibanding akhir bulan lalu.',
    ];
    if ($ao !== '') $cat[] = '• Semua angka hanya untuk AO ' . $namaAo . '.';
    if (!$v)        $cat[] = '• Posisi akhir bulan lalu belum ada di sistem, jadi perbandingan dan persentase ditampilkan "–". Upload nominatif harian akhir bulan lalu agar perbandingan muncul.';
    elseif (!empty($d['akhir_lalu_target']) && $v['tgl'] !== $d['akhir_lalu_target']) $cat[] = '• Posisi tanggal ' . $tTarget . ' tidak ada, jadi dipakai posisi terdekat sebelumnya yaitu ' . $tPrv . '.';
    if (!empty($d['terpotong'])) $cat[] = '• Daftar rincian dibatasi ' . number_format((int)$d['batas'], 0, ',', '.') . ' baris pertama; angka Ringkasan tetap lengkap.';
    $i = $akhirTabel2 + 2;
    foreach ($cat as $n => $t) {
        $sh->setCellValue("A$i", $t);
        $sh->mergeCells("A$i:H$i");
        $sh->getStyle("A$i")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sh->getStyle("A$i")->getFont()->setSize(9)->setBold($n === 0);
        if ($n > 0) $sh->getRowDimension($i)->setRowHeight(max(14, 12.5 * (int)ceil(mb_strlen($t) / 130)));
        $i++;
    }
    foreach (['A' => 58, 'B' => 17, 'C' => 19, 'D' => 17, 'E' => 19, 'F' => 19, 'G' => 17, 'H' => 24] as $col => $w) $sh->getColumnDimension($col)->setWidth($w);
    $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    $sh->setShowGridLines(false);

    // ================================================================ LEMBAR 2-3: RINCIAN
    $subJudul = 'AO: ' . $namaAo . '   |   Posisi bulan ini ' . $tCur . '   |   Dibandingkan dengan posisi akhir bulan lalu ' . ($tPrv ?? '(belum ada)') . '   |   Dicetak ' . date('d/m/Y H:i');

    // pembuat lembar umum. kolom: t=judul, w=lebar, j=jenis (teks|str|tengah|angka|tgl), sum=true (jumlah), rumus=[kolA,kolB] (A+B) atau [kolA,kolB,'-'] (MAX(0, A-B))
    $buatLembar = function (string $nama, string $judul, array $kolom, array $data, array $catatan, array $warna = [], int $ekstra = 0) use ($ss, $kepala, $garis, $subJudul) {
        $ws = $ss->createSheet();
        $ws->setTitle($nama);
        $ws->setCellValue('A1', $judul);
        $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $ws->setCellValue('A2', $subJudul);
        $n    = count($kolom);
        $last = Coordinate::stringFromColumnIndex($n);
        $HR   = 4;
        foreach ($kolom as $k => $c) {
            $col = Coordinate::stringFromColumnIndex($k + 1);
            $ws->setCellValue($col . $HR, $c['t']);
            $ws->getColumnDimension($col)->setWidth($c['w']);
        }
        $ws->getStyle("A$HR:$last$HR")->applyFromArray($kepala);
        $ws->getRowDimension($HR)->setRowHeight(32);

        $i = $HR + 1; $mulai = $i;
        foreach ($data as $baris) {
            foreach ($kolom as $k => $c) {
                $col = Coordinate::stringFromColumnIndex($k + 1);
                if (isset($c['rumus'])) {
                    [$a, $b] = $c['rumus']; $op = $c['rumus'][2] ?? '+';
                    if (($baris[$a] ?? null) !== null && $baris[$a] !== '' || ($baris[$b] ?? null) !== null && $baris[$b] !== '') {
                        $fa = Coordinate::stringFromColumnIndex($a + 1) . $i; $fb = Coordinate::stringFromColumnIndex($b + 1) . $i;
                        $ws->setCellValue($col . $i, $op === '-' ? "=MAX(0,$fa-$fb)" : "=$fa+$fb");
                    }
                    continue;
                }
                $v = $baris[$k] ?? null;
                if ($v === null || $v === '') continue;
                if ($c['j'] === 'str') {
                    $ws->setCellValueExplicit($col . $i, (string)$v, DataType::TYPE_STRING);
                } elseif ($c['j'] === 'tgl') {
                    $ts = strtotime((string)$v . ' 12:00:00');
                    if ($ts !== false) {
                        $ws->setCellValue($col . $i, XlDate::PHPToExcel($ts));
                        $ws->getStyle($col . $i)->getNumberFormat()->setFormatCode('dd/mm/yyyy');
                    }
                } else {
                    $ws->setCellValue($col . $i, $v);
                }
            }
            $i++;
        }
        $akhirData = $i - 1;
        $hi = max($akhirData, $mulai);
        $j  = $akhirData + 1;                                    // baris JUMLAH (SUBTOTAL: ikut berubah bila difilter di Excel)

        $ws->getStyle("A$mulai:$last$hi")->applyFromArray($garis);
        $ws->getStyle("A$mulai:$last$hi")->getAlignment()->setVertical(Alignment::VERTICAL_TOP);
        foreach ($kolom as $k => $c) {
            $col = Coordinate::stringFromColumnIndex($k + 1);
            if ($c['j'] === 'angka') {
                $ws->getStyle("$col$mulai:$col$j")->getNumberFormat()->setFormatCode('#,##0');
                $ws->getStyle("$col$mulai:$col$j")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            } elseif ($c['j'] === 'tengah' || $c['j'] === 'tgl') {
                $ws->getStyle("$col$mulai:$col$hi")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            }
            if (!empty($c['wrap'])) $ws->getStyle("$col$mulai:$col$hi")->getAlignment()->setWrapText(true);
        }
        foreach ($warna as $ix => $rgb) {
            $r = $mulai + $ix;
            $ws->getStyle("A$r:$last$r")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB($rgb);
        }

        if ($akhirData >= $mulai) {
            $ws->setCellValue("B$j", '="JUMLAH ("&SUBTOTAL(103,B' . $mulai . ':B' . $akhirData . ')&" debitur)"');
            foreach ($kolom as $k => $c) {
                if (empty($c['sum'])) continue;
                $col = Coordinate::stringFromColumnIndex($k + 1);
                $ws->setCellValue("$col$j", "=SUBTOTAL(9,$col$mulai:$col$akhirData)");
            }
        } else {
            $ws->setCellValue("B$j", 'JUMLAH (0 debitur)');
        }
        $ws->getStyle("A$j:$last$j")->getFont()->setBold(true);
        $ws->getStyle("A$j:$last$j")->applyFromArray($garis);
        $ws->getStyle("A$j:$last$j")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');

        $k = $j + 2 + $ekstra;
        foreach ($catatan as $nn => $t) {
            $ws->setCellValue("A$k", $t);
            $ws->getStyle("A$k")->getFont()->setSize(9)->setBold($nn === 0);
            $k++;
        }
        $ws->freezePane('C' . ($HR + 1));
        $ws->setAutoFilter("A$HR:$last" . max($akhirData, $HR));
        $ws->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
        $ws->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($HR, $HR);
        $ws->getPageMargins()->setLeft(0.4)->setRight(0.4)->setTop(0.5)->setBottom(0.5);
        $ws->getHeaderFooter()->setOddFooter('&L&8Debitur v2&R&8Halaman &P dari &N');
        return ['ws' => $ws, 'mulai' => $mulai, 'akhir' => $akhirData, 'jml' => $j];
    };

    $kolR = [
        ['t' => 'No',                   'w' => 5,  'j' => 'tengah'],
        ['t' => 'Nama debitur',         'w' => 26, 'j' => 'teks'],
        ['t' => 'No. rekening',         'w' => 17, 'j' => 'str'],
        ['t' => 'AO',                   'w' => 16, 'j' => 'teks'],
        ['t' => 'Alamat',               'w' => 34, 'j' => 'teks', 'wrap' => true],
        ['t' => "Tanggal\npinjam",      'w' => 12, 'j' => 'tgl'],
        ['t' => 'Kolek',                'w' => 7,  'j' => 'tengah'],
        ['t' => "Hari\ntunggak",        'w' => 9,  'j' => 'tengah'],
        ['t' => "Baki debet\n(Rp)",     'w' => 16, 'j' => 'angka', 'sum' => true],
        ['t' => "Tunggakan\nPokok",     'w' => 15, 'j' => 'angka', 'sum' => true],
        ['t' => "Tunggakan\nBunga",     'w' => 15, 'j' => 'angka', 'sum' => true],
        ['t' => "Tunggakan\nTotal",     'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [9, 10]],
        ['t' => "Kolek akhir\nbulan lalu",        'w' => 11, 'j' => 'tengah'],
        ['t' => "Hari tunggak\nakhir bulan lalu", 'w' => 13, 'j' => 'tengah'],
        ['t' => 'Kelompok akhir bulan lalu',      'w' => 26, 'j' => 'teks'],
        ['t' => "Perubahan hari\ntunggak",        'w' => 13, 'j' => 'tengah'],
    ];
    $nz = function ($x) { return $x === null ? null : (float)$x; };
    $buatRincian = function (string $nama, string $judul, int $dari, int $sampai, int $nomorRasio) use ($rows, $kolR, $buatLembar, $nz, $tCur, $tPrv, $v) {
        $data = []; $warna = []; $no = 0;
        foreach ($rows as $x) {
            $h = (int)$x['hari'];
            if ($h < $dari || $h > $sampai) continue;
            $ada = !empty($x['p_ada']);
            $data[] = [
                ++$no, (string)$x['nama'], (string)$x['norek'], (string)$x['ao'], (string)$x['alamat'], (string)$x['tgl_pinjam'],
                (int)$x['kolek'], $h, (float)$x['baki'], $nz($x['tung_pkk']), $nz($x['tung_bg']), null,
                $ada ? (int)$x['p_kolek'] : '', $ada ? (int)$x['p_hari'] : '', (string)$x['p_kel'], $ada ? $h - (int)$x['p_hari'] : '',
            ];
            if (!$ada && $v) $warna[$no - 1] = 'FFF2CC';
        }
        $buatLembar($nama, $judul, $kolR, $data, [
            'Keterangan:',
            '• Lembar ini = daftar debitur untuk perbandingan ' . $nomorRasio . ' di Ringkasan: debitur yang hari tunggaknya ' . $dari . '–' . $sampai . ' pada posisi ' . $tCur . '. Baris JUMLAH sama dengan angka "Debitur bulan ini" di Ringkasan.',
            '• Kolek, hari tunggak, dan kelompok akhir bulan lalu = keadaan debitur yang sama (dicocokkan lewat no. rekening) pada posisi ' . ($tPrv ?? 'akhir bulan lalu (belum ada)') . '. Perubahan hari tunggak = hari tunggak sekarang dikurangi hari tunggak akhir bulan lalu.',
            '• Baris kuning = tidak ada di posisi akhir bulan lalu (kredit baru atau rekening belum tercatat).',
            '• Tunggakan = nilai asli total tunggakan (pokok dan bunga) pada posisi ' . $tCur . ', tanpa dikurangi apa pun.',
        ], $warna);
        return $no;
    };
    $buatRincian('Rincian 1-30 hari',  'Rincian – Debitur menunggak 1–30 hari bulan ini',  1, 30, 1);
    $buatRincian('Rincian 31-60 hari', 'Rincian – Debitur menunggak 31–60 hari bulan ini', 31, 60, 2);

    $sh->getHeaderFooter()->setOddFooter('&L&8Debitur v2&R&8Halaman &P dari &N');
    $ss->setActiveSheetIndex(0);

    // ---------------------------------------------------------------- kirim
    $nama = 'debitur_v2_' . ($ao !== '' ? preg_replace('/[^A-Za-z0-9_.\-]/', '', $ao) . '_' : '') . $c['tgl'] . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nama . '"');
    header('Cache-Control: no-store');
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);           // rumus dihitung Excel saat dibuka, bukan di server
    $w->save('php://output');
    exit;
} catch (Throwable $e) {
    $alasan = get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('export_debitur_v2: ' . $alasan);
    export_galat(500, 'Excel v2 gagal dibuat. ' . $alasan);
}