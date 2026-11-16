<?php
/**
 * Cetak laporan promosi / survey ke Excel / PDF. Letak: folder api/ (sejajar export.php).
 *   GET export_promosi.php?tgl1=YYYY-MM-DD&tgl2=YYYY-MM-DD&user=&jenis=&format=xlsx|pdf[&unduh=1]
 * Hak akses: menu 'web_lap_promosi'. Pembuat berkas: export_lib.php (export_kirim). Isi kegiatan TIDAK dicatat di log.
 */
require __DIR__ . '/../lib.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');
if (function_exists('boleh') && !boleh('web_lap_promosi')) export_galat(403, 'Kamu tidak punya akses ke laporan ini.');

$format = (string)($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) export_galat(400, 'Format harus xlsx atau pdf.');
$pdf = $format === 'pdf';

$tgl1  = trim((string)($_GET['tgl1'] ?? ''));
$tgl2  = trim((string)($_GET['tgl2'] ?? ''));
$user  = trim((string)($_GET['user'] ?? ''));
$jenis = trim((string)($_GET['jenis'] ?? ''));
$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if (($tgl1 !== '' && !$tglOk($tgl1)) || ($tgl2 !== '' && !$tglOk($tgl2))) export_galat(422, 'Tanggal harus berformat YYYY-MM-DD.');
if ($tgl1 !== '' && $tgl2 !== '' && $tgl1 > $tgl2)                         export_galat(422, 'Tanggal awal tidak boleh sesudah tanggal akhir.');
if ($user !== '' && (!ctype_digit($user) || (int)$user > 32767))          export_galat(422, 'User tidak valid.');
if ($jenis !== '' && !preg_match('/^[A-Za-z0-9 _\-]{1,30}$/', $jenis))    export_galat(422, 'Jenis kegiatan tidak valid.');

$path = (string)(api_cfg()['path_promosi'] ?? '');
if ($path === '') export_galat(500, "'path_promosi' belum diisi di config.api.php");

function pr_tgl($t, bool $jam = false): string { $x = strtotime((string)$t); return $x === false ? '' : date($jam ? 'd/m/Y H:i' : 'd/m/Y', $x); }
function pr_n($v): string { return number_format((float)$v, 0, ',', '.'); }

try {
    $isi = ['id_user' => $user];
    if ($tgl1 !== '')  $isi['tgl1'] = $tgl1;
    if ($tgl2 !== '')  $isi['tgl2'] = $tgl2;
    if ($jenis !== '') $isi['jns_giat'] = $jenis;
    $r = api_call('laporan', $isi, $path, 120);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));

    $rows = []; $sum = 0.0;
    foreach ((array)($r['rows'] ?? []) as $x) {
        $rows[] = [
            'waktu' => pr_tgl($x['waktu_kegiatan'] ?? '', true), 'nmuser' => (string)($x['nmuser'] ?? ''), 'jenis' => (string)($x['jenis_kegiatan'] ?? ''),
            'produk' => (string)($x['produk'] ?? ''), 'prospek' => (float)($x['jumlah_prospek'] ?? 0), 'alamat' => (string)($x['alamat_kegiatan'] ?? ''),
            'keterangan' => (string)($x['keterangan'] ?? ''), 'dicatat' => pr_tgl($x['created_at'] ?? '', true),
        ];
        $sum += (float)($x['jumlah_prospek'] ?? 0);
    }
    if ($pdf && count($rows) > 1500) export_galat(413, 'Data terlalu banyak untuk PDF (' . pr_n(count($rows)) . ' baris, batas 1.500). Persempit rentang tanggal atau pilih satu pemakai, atau unduh Excel.');
    $rk = (array)(($r['rows_rekap'] ?? [])[0] ?? []);

    $u = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
    export_kirim([
        'berkas'  => 'laporan_promosi_survey',
        'judul'   => 'Laporan Promosi dan Survey',
        'meta'    => [
            'Rentang: ' . ($tgl1 !== '' ? pr_tgl($tgl1) : '1 bulan ini') . ' s/d ' . ($tgl2 !== '' ? pr_tgl($tgl2) : 'hari ini') . '   |   Pemakai: ' . ($user === '' ? 'semua' : ((string)($rows[0]['nmuser'] ?? '') !== '' ? $rows[0]['nmuser'] : 'ID ' . $user))
                . '   |   Jenis: ' . ($jenis === '' ? 'semua' : $jenis),
            'Total kegiatan: ' . pr_n($rk['total'] ?? count($rows)) . '   |   Total prospek: ' . pr_n($rk['total_prospek'] ?? $sum) . '   |   Jenis kegiatan: ' . pr_n($rk['total_jenis'] ?? 0) . '   |   Rata-rata prospek: ' . pr_n($rk['rata_prospek'] ?? 0),
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)($u['nama'] ?? $u['nmuser'] ?? '-'),
        ],
        'kolom'   => [
            ['h' => 'No',              't' => 'no',     'w' => 4],
            ['h' => 'Petugas',         'k' => 'nmuser', 'w' => 14],
            ['h' => 'Jenis Kegiatan',  'k' => 'jenis',  'w' => 10],
            ['h' => 'Waktu Kegiatan',  'k' => 'waktu',  'w' => 13],
            ['h' => 'Produk',          'k' => 'produk', 'w' => 18],
            ['h' => 'Jumlah Prospek',  'k' => 'prospek','t' => 'int', 'w' => 9],
            ['h' => 'Alamat Kegiatan', 'k' => 'alamat', 'w' => 22],
            ['h' => 'Keterangan',      'k' => 'keterangan', 'w' => 30],
            ['h' => 'Dibuat',          'k' => 'dicatat','w' => 13],
        ],
        'rows'    => $rows,
        'total'   => ['prospek' => $sum],
        'catatan' => [],
    ], $format);
} catch (Throwable $e) {
    error_log('export_promosi: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    export_galat(502, 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
}