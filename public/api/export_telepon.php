<?php
/**
 * Cetak laporan telepon ke Excel / PDF. Letak: folder api/ (sejajar export.php).
 *   GET export_telepon.php?tgl1=YYYY-MM-DD&tgl2=YYYY-MM-DD&user=&format=xlsx|pdf[&unduh=1]
 * Hak akses: menu 'web_laporan_telepon'. Pembuat berkas: export_lib.php (export_kirim). Isi catatan nasabah TIDAK dicatat di log.
 */
require __DIR__ . '/../lib.php';
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');
//if (function_exists('boleh') && !boleh('web_laporan_telepon')) export_galat(403, 'Kamu tidak punya akses ke laporan ini.');
if (!boleh('web_lap_telepon')) export_galat(403, 'Kamu tidak punya akses ke laporan ini.');

$format = (string)($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) export_galat(400, 'Format harus xlsx atau pdf.');
$pdf = $format === 'pdf';

$tgl1 = trim((string)($_GET['tgl1'] ?? ''));
$tgl2 = trim((string)($_GET['tgl2'] ?? ''));
$user = trim((string)($_GET['user'] ?? ''));
$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if (($tgl1 !== '' && !$tglOk($tgl1)) || ($tgl2 !== '' && !$tglOk($tgl2))) export_galat(422, 'Tanggal harus berformat YYYY-MM-DD.');
if ($tgl1 !== '' && $tgl2 !== '' && $tgl1 > $tgl2)                         export_galat(422, 'Tanggal awal tidak boleh sesudah tanggal akhir.');
if ($user !== '' && (!ctype_digit($user) || (int)$user > 32767))          export_galat(422, 'User tidak valid.');

$path = (string)(api_cfg()['path_telepon'] ?? '');
if ($path === '') export_galat(500, "'path_telepon' belum diisi di config.api.php");

function tp_tgl($t): string { $x = strtotime((string)$t); return ($t === null || $t === '' || $t === '-' || $x === false) ? '' : date('d/m/Y', $x); }

try {
    $isi = ['id_user' => $user];
    if ($tgl1 !== '') $isi['tgl1'] = $tgl1;
    if ($tgl2 !== '') $isi['tgl2'] = $tgl2;
    $r = api_call('laporan', $isi, $path, 120);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));

    $rows = [];
    foreach ((array)($r['rows'] ?? []) as $x) {
        $rows[] = [
            'tanggal' => tp_tgl($x['tanggal'] ?? ''), 'rekening' => (string)($x['rekening'] ?? ''), 'nama' => trim((string)($x['nama'] ?? '')),
            'alamat' => trim((string)($x['alamat'] ?? '')), 'tglPinjam' => tp_tgl($x['tglPinjam'] ?? ''), 'tglTempo' => tp_tgl($x['tglTempo'] ?? ''),
            'plafon' => (float)($x['plafon'] ?? 0), 'baki' => (float)($x['baki'] ?? 0), 'kolek' => $x['kolek'] ?? null,
            'tungPokok' => (float)($x['tungPokok'] ?? 0), 'tungBung' => (float)($x['tungBung'] ?? 0),
            'byr_pokok' => (float)($x['byr_pokok'] ?? 0), 'byr_bunga' => (float)($x['byr_bunga'] ?? 0),
            'nmuser' => (string)($x['nmuser'] ?? ''), 'keterangan' => (string)($x['keterangan'] ?? ''), 'tglKomit' => tp_tgl($x['tglKomit'] ?? ''),
        ];
    }
    if ($pdf && count($rows) > 1500) export_galat(413, 'Data terlalu banyak untuk PDF (' . number_format(count($rows), 0, ',', '.') . ' baris, batas 1.500). Persempit rentang tanggal atau pilih satu user, atau unduh Excel.');

    $kolom = [
        ['h' => 'No',            't' => 'no',       'w' => 4],
        ['h' => 'Tanggal',       'k' => 'tanggal',  'w' => 9],
        ['h' => 'No. rekening',  'k' => 'rekening', 'w' => 12],
        ['h' => 'Nama nasabah',  'k' => 'nama',     'w' => 18],
        ['h' => 'Alamat',        'k' => 'alamat',   'w' => 20, 'xlsx' => true],
        ['h' => 'Tgl pinjam',    'k' => 'tglPinjam','w' => 10, 'xlsx' => true],
        ['h' => 'Tgl tempo',     'k' => 'tglTempo', 'w' => 10, 'xlsx' => true],
        ['h' => 'Plafon (Rp)',   'k' => 'plafon',   't' => 'rp', 'w' => 13, 'xlsx' => true],
        ['h' => 'Kolek',         'k' => 'kolek',    't' => 'int', 'w' => 5],
        ['h' => 'Baki debet (Rp)', 'k' => 'baki',   't' => 'rp', 'w' => 12],
        ['h' => 'Tunggakan pokok (Rp)', 'k' => 'tungPokok', 't' => 'rp', 'w' => 12],
        ['h' => 'Tunggakan bunga (Rp)', 'k' => 'tungBung',  't' => 'rp', 'w' => 12],
        ['h' => 'Bayar bln ini pokok (Rp)', 'k' => 'byr_pokok', 't' => 'rp', 'w' => 12],
        ['h' => 'Bayar bln ini bunga (Rp)', 'k' => 'byr_bunga', 't' => 'rp', 'w' => 12],
        ['h' => 'Petugas',       'k' => 'nmuser',   'w' => 11],
        ['h' => 'Keterangan',    'k' => 'keterangan', 'w' => 28],
        ['h' => 'Janji bayar',   'k' => 'tglKomit', 'w' => 10],
    ];
    $u = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
    export_kirim([
        'berkas'  => 'laporan_telepon',
        'judul'   => 'Laporan Telepon Nasabah',
        'meta'    => [
            'Rentang: ' . ($tgl1 !== '' ? tp_tgl($tgl1) : '1 bulan ini') . ' s/d ' . ($tgl2 !== '' ? tp_tgl($tgl2) : 'hari ini') . '   |   User: ' . ($user === '' ? 'semua' : 'ID ' . $user)
                . '   |   Jumlah telepon: ' . number_format(count($rows), 0, ',', '.'),
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)($u['nama'] ?? '-'),
        ],
        'kolom'   => $kolom,
        'rows'    => $rows,
        'total'   => null,
        'catatan' => ['Data kredit (kolek, baki debet, tunggakan, pembayaran) diambil dari nominatif terbaru, bukan posisi pada tanggal telepon. Nasabah yang tidak ada di nominatif tetap tampil dengan nama dan alamat yang dicatat saat telepon.'],
    ], $format);
} catch (Throwable $e) {
    error_log('export_telepon: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    export_galat(502, 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
}
