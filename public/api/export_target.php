<?php
/**
 * Excel "Pencapaian target AO": tabel target vs aktual (OS, realisasi, NOA baru) per AO + dua grafik batang bawaan Excel.
 * Data dari API Monitoring (jenis 'kpi_ao'). Hak menu: 'web_target_ao'. Akun AO hanya mendapat miliknya (dikunci di backend). Letak: folder api/.
 *   GET export_target.php
 */
require __DIR__ . '/../lib.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';   // autoload PhpSpreadsheet dan export_galat()
akses_atau_403('web_target_ao');
date_default_timezone_set('Asia/Jakarta');

if (!class_exists('PhpOffice\PhpSpreadsheet\Spreadsheet')) {
    foreach ([__DIR__ . '/../vendor/autoload.php', __DIR__ . '/../../vendor/autoload.php'] as $al) {
        if (is_file($al)) { require_once $al; break; }
    }
}

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

@ini_set('memory_limit', '256M');
@set_time_limit(90);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('export_target FATAL: ' . $e['message'] . ' @' . basename($e['file']) . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
        echo 'Excel gagal dibuat (fatal): ' . substr($e['message'], 0, 200) . ' @' . basename($e['file']) . ':' . $e['line'];
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');

try {
    $u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
    $uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
    if ($uid <= 0) export_galat(500, 'Sesi login tidak memuat id pengguna. Hubungi admin.');

    $r = api_call('kpi_ao', ['params' => ['uid' => (string)$uid]], api_cfg()['path_monitoring'], 90);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));
    $semua = (array)($d['rows'] ?? []);
    if (!$semua) export_galat(404, 'Belum ada data.');

    $nama = function (array $x): string {
        foreach (['nama_ao', 'nama', 'nama_user', 'kode_ao'] as $k) { if (!empty($x[$k])) return (string)$x[$k]; }
        return '-';
    };
    $nz = function ($v) { return $v === null ? 0 : (float)$v; };
    $ao = []; $kantor = [];
    foreach ($semua as $x) { if (!empty($x['is_kantor'])) $kantor[] = $x; else $ao[] = $x; }
    $urut = array_merge($ao, $kantor);                       // AO dulu, baris Kantor di bawahnya
    $fmt  = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m/Y', $x); };
    $BLN  = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $per  = (string)($d['periode'] ?? '');
    $perTeks = preg_match('/^(\d{4})-(\d{2})/', $per, $m) ? $BLN[(int)$m[2] - 1] . ' ' . $m[1] : $per;

    $HIJAU = '0E6B63'; $FONT = 'Arial';
    $garis = ['borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => 'D0D0D0']]]];
    $kepala = [
        'font' => ['name' => $FONT, 'size' => 10, 'bold' => true, 'color' => ['rgb' => 'FFFFFF']],
        'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => $HIJAU]],
        'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    ];

    $ss = new Spreadsheet();
    $ss->getDefaultStyle()->getFont()->setName($FONT)->setSize(10);
    $sh = $ss->getActiveSheet();
    $sh->setTitle('Pencapaian');
    $sh->setCellValue('A1', 'Pencapaian Target AO – ' . $perTeks);
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->setCellValue('A2', 'Posisi data ' . $fmt($d['tgl_posisi'] ?? '') . '   |   Dicetak ' . date('d/m/Y H:i') . ' oleh ' . (string)($u['nama'] ?? '-'));

    // ---- judul kolom (dua baris)
    $sh->setCellValue('A4', 'AO');                       $sh->mergeCells('A4:A5');
    $sh->setCellValue('B4', 'OS (baki debet)');          $sh->mergeCells('B4:E4');
    $sh->setCellValue('F4', 'Realisasi kredit baru');    $sh->mergeCells('F4:H4');
    $sh->setCellValue('I4', 'NOA baru');                 $sh->mergeCells('I4:K4');
    $sh->setCellValue('L4', 'Penagihan (pembayaran)');   $sh->mergeCells('L4:N4');
    foreach (['B' => 'Target (Rp)', 'C' => 'Aktual (Rp)', 'D' => 'Capai', 'E' => 'Tumbuh bulan ini (Rp)',
              'F' => 'Target (Rp)', 'G' => 'Aktual (Rp)', 'H' => 'Capai', 'I' => 'Target', 'J' => 'Aktual', 'K' => 'Capai', 'L' => 'Target (Rp)', 'M' => 'Aktual (Rp)', 'N' => 'Capai'] as $col => $t) {
        $sh->setCellValue($col . '5', $t);
    }
    $sh->getStyle('A4:N5')->applyFromArray($kepala);
    $sh->getRowDimension(5)->setRowHeight(30);

    // ---- isi
    $baris0 = 6; $i = $baris0;
    foreach ($urut as $x) {
        $sh->setCellValue("A$i", $nama($x));
        $sh->setCellValue("B$i", $nz($x['target_os'] ?? null));
        $sh->setCellValue("C$i", $nz($x['aktual_os'] ?? null));
        $sh->setCellValue("D$i", "=IF(B$i>0,C$i/B$i,\"–\")");
        $sh->setCellValue("E$i", $x['pertumbuhan_os'] === null ? '–' : (float)$x['pertumbuhan_os']);
        $sh->setCellValue("F$i", $nz($x['target_realisasi'] ?? null));
        $sh->setCellValue("G$i", $nz($x['aktual_realisasi'] ?? null));
        $sh->setCellValue("H$i", "=IF(F$i>0,G$i/F$i,\"–\")");
        $sh->setCellValue("I$i", (int)$nz($x['target_noa'] ?? null));
        $sh->setCellValue("J$i", (int)$nz($x['noa_realisasi'] ?? null));
        $sh->setCellValue("K$i", "=IF(I$i>0,J$i/I$i,\"–\")");
        $sh->setCellValue("L$i", $nz($x['target_penagihan'] ?? null));
        $sh->setCellValue("M$i", $nz($x['aktual_penagihan'] ?? null));
        $sh->setCellValue("N$i", "=IF(L$i>0,M$i/L$i,\"–\")");
        $i++;
    }
    $akhirData = $i - 1;
    $sh->setCellValue("A$i", 'TOTAL');
    foreach (['B', 'C', 'E', 'F', 'G', 'I', 'J', 'L', 'M'] as $col) $sh->setCellValue("$col$i", "=SUM($col$baris0:$col$akhirData)");
    $sh->setCellValue("D$i", "=IF(B$i>0,C$i/B$i,\"–\")");
    $sh->setCellValue("H$i", "=IF(F$i>0,G$i/F$i,\"–\")");
    $sh->setCellValue("K$i", "=IF(I$i>0,J$i/I$i,\"–\")");
    $sh->setCellValue("N$i", "=IF(L$i>0,M$i/L$i,\"–\")");
    $sh->getStyle("A$i:N$i")->getFont()->setBold(true);
    $sh->getStyle("A$i:N$i")->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EEEEEE');
    $total = $i;
    $sh->getStyle("A4:N$total")->applyFromArray($garis);
    foreach (['B', 'C', 'E', 'F', 'G', 'I', 'J', 'L', 'M'] as $col) $sh->getStyle("$col$baris0:$col$total")->getNumberFormat()->setFormatCode('#,##0;[Red]-#,##0');
    foreach (['D', 'H', 'K', 'N'] as $col) $sh->getStyle("$col$baris0:$col$total")->getNumberFormat()->setFormatCode('0.0%');
    $sh->getStyle("B$baris0:N$total")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);

    // warna capaian: hijau >= 100%, kuning 70-99%, merah < 70% (teks "–" tidak diwarnai)
    $mk = function (string $rumus, string $argb) {
        $c = new Conditional();
        $c->setConditionType(Conditional::CONDITION_EXPRESSION);
        $c->addCondition($rumus);
        $c->getStyle()->getFill()->setFillType(Fill::FILL_SOLID)->getEndColor()->setARGB($argb);
        return $c;
    };
    foreach (['D', 'H', 'K', 'N'] as $col) {
        $sh->getStyle("$col$baris0:$col$total")->setConditionalStyles([
            $mk("AND(ISNUMBER($col$baris0),$col$baris0>=1)", 'FFE3F3E7'),
            $mk("AND(ISNUMBER($col$baris0),$col$baris0>=0.7,$col$baris0<1)", 'FFFFF3CD'),
            $mk("AND(ISNUMBER($col$baris0),$col$baris0<0.7)", 'FFFDE2E2'),
        ]);
    }

    // ---- catatan
    $cat = [
        'Catatan:',
        '• Capai = aktual dibagi target. Warna: hijau 100% ke atas, kuning 70–99%, merah di bawah 70%. Tanda – = target belum diisi.',
        '• OS aktual = baki debet posisi ' . $fmt($d['tgl_posisi'] ?? '') . '. Tumbuh bulan ini = OS sekarang dikurangi OS awal bulan.',
        '• Realisasi dan NOA baru = kredit yang dicairkan pada ' . $perTeks . '. Target diisi di menu Target AO.',
        '• Penagihan = total pembayaran pokok dan bunga bulan ini, termasuk angsuran debitur lancar. Pelunasan tidak dihitung.',
    ];
    $i = $total + 2;
    foreach ($cat as $n => $t) {
        $sh->setCellValue("A$i", $t);
        $sh->mergeCells("A$i:N$i");
        $sh->getStyle("A$i")->getFont()->setSize(9)->setBold($n === 0);
        $i++;
    }

    foreach (['A' => 28, 'B' => 18, 'C' => 18, 'D' => 10, 'E' => 18, 'F' => 18, 'G' => 18, 'H' => 10, 'I' => 10, 'J' => 10, 'K' => 10, 'L' => 18, 'M' => 18, 'N' => 10] as $col => $w) $sh->getColumnDimension($col)->setWidth($w);
    $sh->freezePane('B6');
    $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);

    // ---- grafik bawaan Excel (hanya baris AO, tanpa baris Kantor). Gagal membuat grafik tidak menggagalkan tabel.
    $nAo = count($ao);
    $adaGrafik = false;
    if ($nAo > 0) {
        try {
            $akhirAo = $baris0 + $nAo - 1;
            $buatGrafik = function (string $id, string $judul, string $kolTarget, string $kolAktual, string $dari, string $sampai) use ($baris0, $akhirAo, $nAo) {
                $cats  = [new DataSeriesValues('String', "'Pencapaian'!\$A\$$baris0:\$A\$$akhirAo", null, $nAo)];
                $nama  = [new DataSeriesValues('String', "'Pencapaian'!\$$kolTarget\$5", null, 1), new DataSeriesValues('String', "'Pencapaian'!\$$kolAktual\$5", null, 1)];
                $vals  = [new DataSeriesValues('Number', "'Pencapaian'!\$$kolTarget\$$baris0:\$$kolTarget\$$akhirAo", null, $nAo),
                          new DataSeriesValues('Number', "'Pencapaian'!\$$kolAktual\$$baris0:\$$kolAktual\$$akhirAo", null, $nAo)];
                $ds = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_CLUSTERED, range(0, 1), $nama, $cats, $vals);
                $ds->setPlotDirection(DataSeries::DIRECTION_COL);
                $ch = new Chart($id, new Title($judul), new Legend(Legend::POSITION_BOTTOM, null, false), new PlotArea(null, [$ds]));
                $ch->setTopLeftPosition($dari);
                $ch->setBottomRightPosition($sampai);
                return $ch;
            };
            $r0 = $i + 1;
            $sh->addChart($buatGrafik('gOS',   'OS: target vs aktual per AO',          'B', 'C', 'A' . $r0, 'E' . ($r0 + 20)));
            $sh->addChart($buatGrafik('gReal', 'Realisasi: target vs aktual per AO',   'F', 'G', 'F' . $r0, 'K' . ($r0 + 20)));
            $sh->addChart($buatGrafik('gTagih', 'Penagihan: target vs aktual per AO',  'L', 'M', 'A' . ($r0 + 22), 'E' . ($r0 + 42)));
            $adaGrafik = true;
        } catch (Throwable $e) {
            error_log('export_target grafik gagal: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
            $sh->setCellValue('A' . ($i + 1), 'Grafik tidak dapat dibuat di server ini; gunakan halaman Pencapaian target AO untuk melihat grafik.');
        }
    }

    $sh->getHeaderFooter()->setOddFooter('&L&8Pencapaian target AO&R&8Halaman &P dari &N');
    $ss->setActiveSheetIndex(0);

    $file = 'pencapaian_target_ao_' . preg_replace('/[^0-9\-]/', '', $per) . '.xlsx';
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    header('Cache-Control: no-store');
    $w = new Xlsx($ss);
    $w->setPreCalculateFormulas(false);
    if ($adaGrafik) $w->setIncludeCharts(true);
    $w->save('php://output');
    exit;
} catch (Throwable $e) {
    $alasan = get_class($e) . ': ' . substr($e->getMessage(), 0, 200) . ' @' . basename($e->getFile()) . ':' . $e->getLine();
    error_log('export_target: ' . $alasan);
    export_galat(500, 'Excel gagal dibuat. ' . $alasan);
}