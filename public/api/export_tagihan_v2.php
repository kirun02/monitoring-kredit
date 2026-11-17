<?php
/**
 * Excel "Versi 2" untuk Tagihan AO: lembar "Ringkasan" (tabel uraian butir 1-4) dan satu lembar rincian per butir
 * (Rincian 1 Kewajiban, Rincian 2 Tunggakan, Rincian 3 NPL, Rincian 4 Pelunasan; satu baris per debitur).
 * Berdiri sendiri: export_tagihan.php yang lama TIDAK diubah. Letak: folder api/.
 *
 *   GET export_tagihan_v2.php?awal=YYYY-MM-DD&akhir=YYYY-MM-DD&ao=&urut=tgl|ao|hari
 *
 * Data diambil lewat API backend (jenis 'tagihan', terenkripsi), id pengguna dari SESI login (bukan dari alamat halaman),
 * jadi akun AO tetap terkunci ke kode_ao miliknya di backend.
 * Butuh monitoring_helper.php versi baru (mengembalikan kunci 'cetak').
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';   // autoload PhpSpreadsheet dan export_galat()
akses_atau_403('web_tagihan');
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
use PhpOffice\PhpSpreadsheet\Style\Conditional;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

@ini_set('memory_limit', '512M');
@set_time_limit(150);
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        error_log('export_tagihan_v2 FATAL: ' . $e['message'] . ' @' . basename($e['file']) . ':' . $e['line']);
        if (!headers_sent()) { http_response_code(500); header('Content-Type: text/plain; charset=utf-8'); }
        echo 'Excel v2 gagal dibuat (fatal): ' . substr($e['message'], 0, 200) . ' @' . basename($e['file']) . ':' . $e['line'];
    }
});

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');

// ---------------------------------------------------------------- validasi (sama dengan api_tagihan.php)
$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) export_galat(500, 'Sesi login tidak memuat id pengguna. Hubungi admin.');

$awal  = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));
$ao    = trim((string)($_GET['ao'] ?? ''));
$urut  = trim((string)($_GET['urut'] ?? ''));
$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if (($awal === '') !== ($akhir === ''))                           export_galat(422, 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.');
if ($awal !== '' && (!$tglOk($awal) || !$tglOk($akhir)))           export_galat(422, 'Tanggal tidak valid.');
if ($awal !== '' && $akhir < $awal)                                export_galat(422, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
if ($awal !== '' && substr($awal, 0, 7) !== substr($akhir, 0, 7))  export_galat(422, 'Tanggal awal dan akhir harus dalam bulan yang sama.');
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao))   export_galat(422, 'Kode AO tidak valid.');
if ($urut !== '' && !in_array($urut, ['tgl', 'ao', 'hari'], true)) export_galat(422, 'Urutan tidak valid.');

try {
    $p = ['uid' => (string)$uid];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao'] = $ao;
    if ($urut !== '') $p['urut'] = $urut;
    $p['semua'] = '1';                                       // semua debitur dalam rentang, termasuk yang sudah bayar penuh

    $r = api_call('tagihan', ['params' => $p], api_cfg()['path_monitoring'], 120);
    if (($r['status'] ?? '') !== 'success') export_galat(500, 'API data gagal: ' . (string)($r['message'] ?? 'tanpa pesan') . ' (cek error_log server API, cari "Monitoring/tagihan gagal")');
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));
    if (!isset($d['cetak']) || !is_array($d['cetak'])) export_galat(500, 'Server data belum diperbarui (monitoring_helper.php belum memuat ringkasan cetak).');

    // ---------------------------------------------------------------- bahan
    $c      = (array)$d['cetak'];
    $R      = (array)$c['rentang'];
    $S      = (array)$c['sebelumnya'];
    $rk     = (array)$d['ringkas'];
    $rows   = (array)($d['rows'] ?? []);
    $tgOk   = !empty($d['tung_tersedia']);
    $tgAwal = !empty($c['tung_awal_tersedia']);
    $batas  = (int)($d['batas_npl'] ?? 90);
    $dari   = (int)$d['hari_dari'];
    $sampai = (int)$d['hari_sampai'];
    $rentangTxt = $dari . '–' . $sampai;
    $fmt    = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m/Y', $x); };
    $namaAo = (string)($d['ao']['nama'] ?? 'Semua AO');

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
    $sh->setCellValue('A1', 'Ringkasan Tagihan AO');
    $sh->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sh->setCellValue('A2', 'AO: ' . $namaAo . '   |   Tgl pinjam ' . $rentangTxt . ' (periode ' . $d['periode'] . ')');
    $sh->setCellValue('A3', 'Baseline ' . $fmt($d['posisi_baseline']) . '   |   Pembayaran sampai posisi ' . $fmt($d['posisi_akhir']) . '   |   Dicetak ' . date('d/m/Y H:i'));

    $H = 5;                                                  // baris judul tabel
    foreach (['A' => 'Uraian', 'B' => 'Debitur', 'C' => 'Pokok (Rp)', 'D' => 'Bunga (Rp)', 'E' => 'Total', 'F' => 'Daftar debitur di lembar'] as $col => $t) $sh->setCellValue($col . $H, $t);
    $sh->getStyle("A$H:F$H")->applyFromArray($kepala);

    // NPL dihitung dari baris rincian (debitur yang masih ditagih; hari tunggak di posisi terakhir)
    $npl = ['pot' => ['n' => 0, 'p' => 0.0, 'b' => 0.0], 'sdh' => ['n' => 0, 'p' => 0.0, 'b' => 0.0]];
    foreach ($rows as $x) {
        if (!empty($x['lunas'])) continue;
        $h = (int)($x['hari'] ?? 0);
        $k = $h > $batas ? 'sdh' : (($h >= $batas - 29) ? 'pot' : null);
        if ($k === null) continue;
        $npl[$k]['n']++;
        $npl[$k]['p'] += (float)($x['tung_sisa_pkk'] ?? 0);
        $npl[$k]['b'] += (float)($x['tung_sisa_bg'] ?? 0);
    }

    $tt = $tgOk ? null : '–';                                // tunggakan belum termuat di posisi terakhir: tampilkan "–"
    $ta = $tgAwal ? null : '–';
    // [label, debitur, pokok, bunga, tebal?, indent?]  — null = baris kosong pemisah
    $baris = [
        ['1. Total kewajiban bulan ini (tgl pinjam ' . $rentangTxt . ')', (int)$R['deb'],      (float)$R['kw_pkk'],  (float)$R['kw_bg'],  true,  false, 'Rincian 1 Kewajiban'],
        ['Total Pembayaran (tgl pinjam ' . $rentangTxt . ')',             (int)$R['deb_byr'],  (float)$R['byr_pkk'], (float)$R['byr_bg'], false, false],
        ['Sisa Kewajiban tgl ' . $rentangTxt,                             (int)$R['deb_sisa'], (float)$R['sisa_pkk'], (float)$R['sisa_bg'], false, false],
        null,
        ['2. Tunggakan bulan sebelumnya',                                 (int)$S['deb'],      $tgAwal ? (float)$S['tung_pkk'] : $ta, $tgAwal ? (float)$S['tung_bg'] : $ta, true, false, 'Rincian 2 Tunggakan'],
        ['Total Pembayaran',                                              (int)$S['deb_byr'],  (float)$S['byr_pkk'], (float)$S['byr_bg'], false, false],
        ['Sisa Kewajiban',                                                (int)$S['deb_sisa'], $tgOk ? (float)$S['sisa_pkk'] : $tt, $tgOk ? (float)$S['sisa_bg'] : $tt, false, false],
        null,
        ['3. Berpotensi masuk NPL (hari tunggak ' . ($batas - 29) . '–' . $batas . ')', $npl['pot']['n'], $tgOk ? $npl['pot']['p'] : $tt, $tgOk ? $npl['pot']['b'] : $tt, true, false, 'Rincian 3 NPL'],
        ['Sudah NPL (lebih dari ' . $batas . ' hari)',                    $npl['sdh']['n'],    $tgOk ? $npl['sdh']['p'] : $tt, $tgOk ? $npl['sdh']['b'] : $tt, false, true],
        null,
        ['4. Pelunasan dalam rentang waktu',                              (int)$rk['lunas_keluar'], (float)$rk['lunas_baki'], '–', true, false, 'Rincian 4 Pelunasan'],
    ];
    $i = $H + 1;
    foreach ($baris as $b) {
        if ($b === null) { $i++; continue; }
        [$lbl, $deb, $pk, $bg, $tebal, $inden] = $b;
        $lbr = (string)($b[6] ?? '');
        $sh->setCellValue("A$i", $lbl);
        $sh->setCellValue("B$i", $deb);
        $sh->setCellValue("C$i", $pk);
        $sh->setCellValue("D$i", $bg);
        if (is_numeric($pk) && is_numeric($bg)) $sh->setCellValue("E$i", "=C$i+D$i");
        elseif (is_numeric($pk))                $sh->setCellValue("E$i", "=C$i");        // pelunasan: hanya baki debet
        else                                    $sh->setCellValue("E$i", '–');
        $sh->getStyle("A$i:F$i")->applyFromArray($garis);
        $sh->getStyle("B$i:E$i")->getNumberFormat()->setFormatCode('#,##0');
        $sh->getStyle("B$i:E$i")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
        if ($tebal) $sh->getStyle("A$i:E$i")->getFont()->setBold(true);
        if ($inden) $sh->getStyle("A$i")->getAlignment()->setIndent(1);
        if ($lbr !== '') {                                       // tautan ke lembar rincian butir ini
            $sh->setCellValue("F$i", $lbr);
            $sh->getCell("F$i")->getHyperlink()->setUrl("sheet://'" . $lbr . "'!A1");
            $sh->getStyle("F$i")->getFont()->setColor(new \PhpOffice\PhpSpreadsheet\Style\Color('FF0563C1'))->setUnderline('single');
        }
        $i++;
    }
    $akhirTabel = $i - 1;

    $cat = [
        'Catatan:',
        '• Kewajiban bulan ini = debitur dengan tanggal pinjam ' . $rentangTxt . ' yang punya kewajiban pokok/bunga bulan ini (dari baseline ' . $fmt($d['posisi_baseline']) . '), termasuk yang sudah membayar penuh. Debitur yang lunas masuk butir 4, bukan butir 1.',
        '• Sisa kewajiban = jumlah kekurangan tiap debitur (pembayaran lebih pada satu debitur tidak mengurangi debitur lain).',
        '• Tunggakan bulan sebelumnya = semua debitur (tanggal pinjam berapa pun) yang punya tunggakan (pokok atau bunga) di posisi awal bulan; pembayarannya = pembayaran sejak ' . ($d['posisi_pembanding'] ? 'posisi ' . $fmt($d['posisi_pembanding']) : 'awal bulan') . ' sampai posisi terakhir; sisa = sisa tunggakan di posisi terakhir.',
        '• NPL dihitung dari sisa tunggakan debitur yang masih ditagih, menurut hari tunggak di posisi ' . $fmt($d['posisi_akhir']) . '.',
        '• Pelunasan = rekening yang baki debetnya nol atau sudah tidak ada di nominatif terakhir; kolom Pokok berisi baki debet baseline.',
        '• Daftar debitur tiap butir ada di lembar Rincian 1 sampai 4 (klik tulisan di kolom paling kanan); jumlah tiap lembar sama dengan angka butirnya di sini.',
        '• Butir 3 bukan tambahan tagihan: debitur yang sama bisa muncul juga di butir 1 atau 2, hanya dilihat dari sisi hari tunggaknya. Butir 4 (lunas) tidak dihitung lagi di butir 1–3.',
    ];
    if (!$tgOk)  $cat[] = '• Data tunggakan belum ada di posisi terakhir, jadi sisa tunggakan dan nilai NPL ditampilkan "–".';
    if (!$tgAwal) $cat[] = '• Total tunggakan belum termuat di baseline, jadi nilai tunggakan awal ditampilkan "–".';
    foreach ((array)($d['peringatan'] ?? []) as $w) $cat[] = '• ' . (string)$w;
    $i = $akhirTabel + 2;
    foreach ($cat as $n => $t) {
        $sh->setCellValue("A$i", $t);
        $sh->mergeCells("A$i:F$i");
        $sh->getStyle("A$i")->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sh->getStyle("A$i")->getFont()->setSize(9)->setBold($n === 0);
        if ($n > 0) $sh->getRowDimension($i)->setRowHeight(max(14, 12.5 * (int)ceil(mb_strlen($t) / 120)));
        $i++;
    }
    foreach (['A' => 58, 'B' => 11, 'C' => 18, 'D' => 18, 'E' => 18, 'F' => 26] as $col => $w) $sh->getColumnDimension($col)->setWidth($w);
    $sh->getRowDimension($H)->setRowHeight(22);
    $sh->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE)->setPaperSize(PageSetup::PAPERSIZE_A4)->setFitToWidth(1)->setFitToHeight(0);
    $sh->setShowGridLines(false);

    // ================================================================ LEMBAR 2-5: RINCIAN PER BUTIR RINGKASAN
    // Tiap butir 1-4 di Ringkasan punya lembar sendiri, jadi pembaca tinggal mencocokkan nomor butir dengan nama lembar.
    // Baris JUMLAH di tiap lembar = angka butir itu di Ringkasan.
    $adaKj = $d['kunjungan_tersedia'] !== false;
    $adaTp = $d['telepon_tersedia'] !== false;
    $akt = function ($v, bool $ada, string $belum) use ($fmt): string {
        if (!$ada) return '–';
        if (!$v)   return $belum;
        $t = (int)($v['n'] ?? 0) . '×, terakhir ' . $fmt($v['terakhir'] ?? '');
        if (!empty($v['ket']))   $t .= "\n" . $v['ket'];
        if (!empty($v['komit'])) $t .= "\nJanji bayar " . $fmt($v['komit']) . (!empty($v['komit_lewat']) ? ' (lewat)' : '');
        return $t;
    };
    $nz = function ($v) { return $v === null ? null : (float)$v; };
    $subJudul = 'AO: ' . $namaAo . '   |   Tgl pinjam ' . $rentangTxt . ' (periode ' . $d['periode'] . ')   |   Pembayaran sampai posisi ' . $fmt($d['posisi_akhir']) . '   |   Dicetak ' . date('d/m/Y H:i');

    $kolDasar = [
        ['t' => 'No',               'w' => 5,  'j' => 'tengah'],
        ['t' => 'Nama debitur',     'w' => 26, 'j' => 'teks'],
        ['t' => 'No. rekening',     'w' => 17, 'j' => 'str'],
        ['t' => 'AO',               'w' => 16, 'j' => 'teks'],
        ['t' => 'Alamat',           'w' => 34, 'j' => 'teks', 'wrap' => true],
        ['t' => "Tgl pinjam\n(hari)", 'w' => 9, 'j' => 'tengah'],
    ];
    $kolAkt = [
        ['t' => 'Telepon',   'w' => 34, 'j' => 'teks', 'wrap' => true],
        ['t' => 'Kunjungan', 'w' => 34, 'j' => 'teks', 'wrap' => true],
    ];
    $dasar = function (array $x, int $no): array {
        return [$no, (string)$x['nama'], (string)$x['norek'], (string)$x['ao'], (string)$x['alamat'], (int)$x['tgl_jt']];
    };
    $aktivitas = function (array $x) use ($akt, $adaTp, $adaKj): array {
        return [$akt($x['telepon'] ?? null, $adaTp, 'Belum ditelepon'), $akt($x['kunjungan'] ?? null, $adaKj, 'Belum dikunjungi')];
    };
    $kolek = function (array $x) { return $x['kolek'] === null ? '' : (int)$x['kolek']; };
    $xnung = function (array $x) { return $x['xnunggak'] === null ? '' : (int)$x['xnunggak']; };

    // pembuat lembar umum. kolom: t=judul, w=lebar, j=jenis (teks|str|tengah|angka|tgl), sum=true (jumlah), rumus=[kolA,kolB] (A+B)
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
                    [$a, $b] = $c['rumus'];
                    if (($baris[$a] ?? null) !== null && $baris[$a] !== '' || ($baris[$b] ?? null) !== null && $baris[$b] !== '') {
                        $ws->setCellValue($col . $i, '=' . Coordinate::stringFromColumnIndex($a + 1) . $i . '+' . Coordinate::stringFromColumnIndex($b + 1) . $i);
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
        $ws->getHeaderFooter()->setOddFooter('&L&8Tagihan AO v2&R&8Halaman &P dari &N');
        return ['ws' => $ws, 'mulai' => $mulai, 'akhir' => $akhirData, 'jml' => $j];
    };

    // ---------------------------------------------------------------- Rincian 1: kewajiban bulan ini (tgl pinjam dalam rentang)
    // Kewajiban = kw_bln_pkk / kw_bln_bg; Pembayaran = byr_blnpkk / byr_blnbg (posisi terakhir)
    $kol1 = array_merge($kolDasar, [
        ['t' => 'Kolek',                         'w' => 7,  'j' => 'tengah'],   // 6
        ['t' => "Nunggak\n(x)",                  'w' => 9,  'j' => 'tengah'],   // 7
        ['t' => "Kewajiban bulan ini\nPokok",    'w' => 15, 'j' => 'angka', 'sum' => true],   // 8
        ['t' => "Kewajiban bulan ini\nBunga",    'w' => 15, 'j' => 'angka', 'sum' => true],   // 9
        ['t' => "Kewajiban bulan ini\nTotal",    'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [8, 9]],   // 10
        ['t' => "Sudah dibayar\nPokok",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 11
        ['t' => "Sudah dibayar\nBunga",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 12
        ['t' => "Sudah dibayar\nTotal",          'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [11, 12]],  // 13
        ['t' => "Sisa kewajiban\n(kurang bayar)", 'w' => 15, 'j' => 'angka', 'sum' => true],  // 14
        ['t' => "Tanggal bayar\nterakhir",       'w' => 12, 'j' => 'tgl'],      // 15
    ], $kolAkt);
    $dt1 = []; $no = 0;
    foreach ($rows as $x) {
        if (!empty($x['lunas']) || ($x['grup'] ?? '') === 'sebelumnya') continue;
        $kwP = (float)$x['kw_pkk']; $kwB = (float)$x['kw_bg'];
        if ($kwP + $kwB <= 0) continue;                          // sama dengan syarat butir 1 di Ringkasan
        $byP = (float)$x['byr_pkk']; $byB = (float)$x['byr_bg'];
        $sP = max(0.0, $kwP - $byP); $sB = max(0.0, $kwB - $byB);
        if ($sP + $sB <= 0.5) { $sP = 0.0; $sB = 0.0; }
        $no++;
        $dt1[] = array_merge($dasar($x, $no), [$kolek($x), $xnung($x), $kwP, $kwB, null, $byP, $byB, null, $sP + $sB, (string)($x['tgl_byr'] ?? '')], $aktivitas($x));
    }
    $buatLembar('Rincian 1 Kewajiban', 'Rincian 1 – Kewajiban bulan ini (tgl pinjam ' . $rentangTxt . ')', $kol1, $dt1, [
        'Keterangan:',
        '• Lembar ini = butir 1 di Ringkasan. Isinya debitur dengan tanggal pinjam ' . $rentangTxt . ' yang punya kewajiban pokok/bunga bulan ini, termasuk yang sudah membayar penuh.',
        '• Kewajiban bulan ini = pokok dan bunga yang harus dibayar bulan ini menurut data posisi ' . $fmt($d['posisi_baseline']) . '. Sudah dibayar = pokok dan bunga yang sudah masuk bulan ini sampai posisi ' . $fmt($d['posisi_akhir']) . '.',
        '• Sisa kewajiban = kewajiban dikurangi yang sudah dibayar, dihitung per debitur (kelebihan bayar satu debitur tidak mengurangi debitur lain). Nol berarti sudah beres bulan ini.',
        '• Debitur yang sudah lunas tidak ada di lembar ini, lihat Rincian 4 Pelunasan.',
    ]);

    // ---------------------------------------------------------------- Rincian 2: tunggakan bulan sebelumnya (tgl pinjam sebelum rentang)
    // Tunggakan = ttung_pkk / ttung_bg; Pembayaran = byr_blnpkk / byr_blnbg
    $kol2 = array_merge($kolDasar, [
        ['t' => 'Kolek',                          'w' => 7,  'j' => 'tengah'],   // 6
        ['t' => "Nunggak\n(x)",                   'w' => 9,  'j' => 'tengah'],   // 7
        ['t' => "Hari\ntunggak",                  'w' => 8,  'j' => 'tengah'],   // 8
        ['t' => "Tunggakan \nPokok",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 9
        ['t' => "Tunggakan \nBunga",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 10
        ['t' => "Tunggakan \nTotal",          'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [9, 10]],   // 11
        ['t' => "Dibayar dalam rentang\nPokok",   'w' => 15, 'j' => 'angka', 'sum' => true],   // 12
        ['t' => "Dibayar dalam rentang\nBunga",   'w' => 15, 'j' => 'angka', 'sum' => true],   // 13
        ['t' => "Dibayar dalam rentang\nTotal",   'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [12, 13]],  // 14
        ['t' => "Sisa tunggakan\nPokok",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 15
        ['t' => "Sisa tunggakan\nBunga",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 16
        ['t' => "Sisa tunggakan\nTotal",          'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [15, 16]],  // 17
        ['t' => "Tanggal bayar\nterakhir",        'w' => 12, 'j' => 'tgl'],      // 18
    ], $kolAkt);
    $dt2 = []; $no = 0;
    foreach ($rows as $x) {
        if (!empty($x['lunas'])) continue;
        $taP = $nz($x['tung_awal_pkk'] ?? null); $taB = $nz($x['tung_awal_bg'] ?? null);
        if ($tgAwal ? (($taP ?? 0.0) + ($taB ?? 0.0)) <= 0 : (int)$x['hari'] <= 0) continue;   // sama dengan syarat butir 2 di Ringkasan: punya tunggakan di baseline, tgl pinjam berapa pun
        $sP = $nz($x['tung_sisa_pkk'] ?? null); $sB = $nz($x['tung_sisa_bg'] ?? null);
        if ($sP !== null && $sB !== null && $sP + $sB <= 0.5) { $sP = 0.0; $sB = 0.0; }
        $no++;
        $dt2[] = array_merge($dasar($x, $no), [$kolek($x), $xnung($x), (int)$x['hari'], $taP, $taB, null,
            (float)($x['byr_rentang_pkk'] ?? 0), (float)($x['byr_rentang_bg'] ?? 0), null, $sP, $sB, null, (string)($x['tgl_byr'] ?? '')], $aktivitas($x));
    }
    $cat2 = [
        'Keterangan:',
        '• Lembar ini = butir 2 di Ringkasan. Isinya SEMUA debitur yang punya tunggakan (pokok atau bunga) di awal bulan dan belum lunas, berapa pun tanggal pinjamnya.',
        '• Tunggakan awal = tunggakan pokok dan bunga menurut data posisi ' . $fmt($d['posisi_baseline']) . ' (awal bulan). Sisa tunggakan = tunggakan pokok dan bunga yang masih ada di posisi ' . $fmt($d['posisi_akhir']) . '.',
        '• Dibayar dalam rentang = pembayaran bulan ini sampai posisi ' . $fmt($d['posisi_akhir']) . ' dikurangi pembayaran bulan ini sampai posisi ' . ($d['posisi_pembanding'] ? $fmt($d['posisi_pembanding']) : 'awal bulan') . ' (pembayaran sebelum rentang sudah masuk laporan minggu sebelumnya, jadi Tunggakan awal – Dibayar belum tentu sama dengan Sisa).',
        '• Debitur dengan tanggal pinjam dalam rentang ' . $rentangTxt . ' bisa tampil di Rincian 1 dan di lembar ini (kewajiban bulan ini dan tunggakan lama adalah dua tagihan berbeda). Data pembayaran bulan ini hanya satu angka per debitur, jadi pembayaran di dua lembar itu jangan dijumlahkan.',
    ];
    if (!$tgOk)  $cat2[] = '• Total tunggakan belum termuat di posisi terakhir, jadi kolom Sisa tunggakan kosong.';
    if (!$tgAwal) $cat2[] = '• Total tunggakan belum termuat di baseline, jadi kolom Tunggakan awal kosong dan daftar dinilai dari hari tunggak.';
    $buatLembar('Rincian 2 Tunggakan', 'Rincian 2 – Tunggakan bulan sebelumnya', $kol2, $dt2, $cat2);

    // ---------------------------------------------------------------- Rincian 3: NPL (berpotensi dan sudah)
    // Sisa tunggakan = ttung_pkk / ttung_bg posisi terakhir
    $kol3 = array_merge($kolDasar, [
        ['t' => 'Kategori',                       'w' => 16, 'j' => 'teks'],     // 6
        ['t' => "Hari\ntunggak",                  'w' => 8,  'j' => 'tengah'],   // 7
        ['t' => 'Kolek',                          'w' => 7,  'j' => 'tengah'],   // 8
        ['t' => "Nunggak\n(x)",                   'w' => 9,  'j' => 'tengah'],   // 9
        ['t' => "Sisa tunggakan\nPokok",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 10
        ['t' => "Sisa tunggakan\nBunga",          'w' => 15, 'j' => 'angka', 'sum' => true],   // 11
        ['t' => "Sisa tunggakan\nTotal",          'w' => 15, 'j' => 'angka', 'sum' => true, 'rumus' => [10, 11]],  // 12
    ], $kolAkt);
    $r3 = [];
    foreach ($rows as $x) {
        if (!empty($x['lunas'])) continue;
        $h = (int)($x['hari'] ?? 0);
        $kat = $h > $batas ? 'Sudah NPL' : (($h >= $batas - 29) ? 'Berpotensi NPL' : null);   // sama dengan butir 3 di Ringkasan
        if ($kat === null) continue;
        $x['_kat'] = $kat;
        $r3[] = $x;
    }
    usort($r3, function ($a, $b) { return ((int)$b['hari'] <=> (int)$a['hari']) ?: strcmp((string)$a['nama'], (string)$b['nama']); });
    $dt3 = []; $w3 = []; $no = 0;
    foreach ($r3 as $x) {
        $no++;
        $w3[$no - 1] = $x['_kat'] === 'Sudah NPL' ? 'FDECEA' : 'FFF8E1';
        $dt3[] = array_merge($dasar($x, $no), [$x['_kat'], (int)$x['hari'], $kolek($x), $xnung($x), $nz($x['tung_sisa_pkk'] ?? null), $nz($x['tung_sisa_bg'] ?? null), null], $aktivitas($x));
    }
    $L3 = $buatLembar('Rincian 3 NPL', 'Rincian 3 – Berpotensi masuk NPL dan sudah NPL', $kol3, $dt3, [
        'Keterangan:',
        '• Lembar ini = butir 3 di Ringkasan. Berpotensi NPL = hari tunggak ' . ($batas - 29) . '–' . $batas . ' (kuning); Sudah NPL = lebih dari ' . $batas . ' hari (merah muda). Hari tunggak menurut posisi ' . $fmt($d['posisi_akhir']) . '.',
        '• Lembar ini BUKAN tambahan tagihan: debitur yang sama juga bisa tampil di Rincian 1 atau Rincian 2. Di sini hanya dilihat dari sisi hari tunggaknya.',
        '• Sisa tunggakan = tunggakan pokok dan bunga yang masih ada di posisi ' . $fmt($d['posisi_akhir']) . '.',
        $tgOk ? '' : '• Total tunggakan belum termuat di posisi terakhir, jadi kolom Sisa tunggakan kosong.',
    ], $w3, 2);
    // dua baris pemisah per kategori (angkanya sama dengan baris butir 3 di Ringkasan; tidak ikut filter)
    $ws3 = $L3['ws']; $a3 = $L3['mulai']; $z3 = max($L3['akhir'], $L3['mulai']);
    $rr = $L3['jml'] + 1;
    foreach (['Berpotensi NPL', 'Sudah NPL'] as $kat) {
        $ws3->setCellValue("B$rr", '="' . $kat . ': "&COUNTIF(G' . $a3 . ':G' . $z3 . ',"' . $kat . '")&" debitur"');
        foreach (['K', 'L', 'M'] as $col) {
            $ws3->setCellValue("$col$rr", '=SUMIF($G$' . $a3 . ':$G$' . $z3 . ',"' . $kat . '",' . $col . $a3 . ':' . $col . $z3 . ')');
            $ws3->getStyle("$col$rr")->getNumberFormat()->setFormatCode('#,##0');
        }
        $ws3->getStyle("A$rr:O$rr")->getFont()->setBold(true);
        $ws3->getStyle("A$rr:O$rr")->applyFromArray($garis);
        $rr++;
    }

    // ---------------------------------------------------------------- Rincian 4: pelunasan
    $kol4 = array_merge($kolDasar, [
        ['t' => "Baki debet\nsaat baseline",      'w' => 17, 'j' => 'angka', 'sum' => true],   // 6
        ['t' => 'Keterangan',                     'w' => 32, 'j' => 'teks'],                    // 7
        ['t' => "Tanggal bayar\nterakhir",        'w' => 12, 'j' => 'tgl'],                     // 8
    ]);
    $dt4 = []; $no = 0;
    foreach ($rows as $x) {
        if (empty($x['lunas'])) continue;
        $no++;
        $ket = $x['baki_akhir'] === null ? 'Sudah tidak ada di nominatif terakhir' : 'Baki debet nol di nominatif terakhir';
        $dt4[] = array_merge($dasar($x, $no), [(float)$x['baki'], $ket, (string)($x['tgl_byr'] ?? '')]);
    }
    $buatLembar('Rincian 4 Pelunasan', 'Rincian 4 – Pelunasan dalam rentang waktu', $kol4, $dt4, [
        'Keterangan:',
        '• Lembar ini = butir 4 di Ringkasan. Isinya rekening yang sudah lunas: baki debetnya nol, atau sudah tidak ada lagi di nominatif posisi ' . $fmt($d['posisi_akhir']) . '.',
        '• Baki debet saat baseline = baki debet di nominatif posisi ' . $fmt($d['posisi_baseline']) . ' (angka ini yang tampil di kolom Pokok pada Ringkasan butir 4).',
        '• Debitur yang lunas tidak dihitung lagi di butir 1, 2, dan 3.',
    ]);

    $sh->getHeaderFooter()->setOddFooter('&L&8Tagihan AO v2&R&8Halaman &P dari &N');

    $ss->setActiveSheetIndex(0);

    // ---------------------------------------------------------------- kirim
    $nama = 'tagihan_ao_v2_' . ($ao !== '' ? preg_replace('/[^A-Za-z0-9_.\-]/', '', $ao) . '_' : '') . $d['sel']['awal'] . '_' . $d['sel']['akhir'] . '.xlsx';
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
    error_log('export_tagihan_v2: ' . $alasan);
    export_galat(500, 'Excel v2 gagal dibuat. ' . $alasan);
}