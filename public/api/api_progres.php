<?php
/**
 * Proxy "progres mingguan" untuk halaman progres.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET ?awal=&akhir=&ao=&dasar=&hal=&per=&status=&nama=&urut=
 *       -> data dari jenis_req 'progres': ringkasan per minggu + daftar debitur per halaman (daftar.rows), atau {empty:true,pesan}
 *       dasar: keduanya | kewajiban | tunggakan (kosong = backend memilih); urut: hari | tgl
 *       status: belum | sebagian | sudah | tanpa | hilang | tlunas | tturun | ttetap | tnaik; nama: cari nama / nomor rekening (min 2 huruf); per: 10 | 20 | 50
 *   Tanpa awal dan akhir    -> backend memakai tanggal 1 bulan posisi terakhir sampai posisi terakhir.
 *
 * Letak: folder api/.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_progres');             // hak akses menu Progres mingguan

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function tgl_ok(string $t): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $t);
    return $d && $d->format('Y-m-d') === $t;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

$awal  = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));
$ao    = trim((string)($_GET['ao'] ?? ''));

if (($awal === '') !== ($akhir === ''))                  keluar(['error' => 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.'], 422);
if ($awal !== '' && (!tgl_ok($awal) || !tgl_ok($akhir))) keluar(['error' => 'Tanggal tidak valid.'], 422);
if ($awal !== '' && $akhir < $awal)                      keluar(['error' => 'Tanggal akhir tidak boleh sebelum tanggal awal.'], 422);
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) keluar(['error' => 'Kode AO tidak valid.'], 422);

$hal    = max(1, min(10000, (int)($_GET['hal'] ?? 1)));
$per    = in_array($pp = (int)($_GET['per'] ?? 20), [10, 20, 50], true) ? $pp : 20;
$status = trim((string)($_GET['status'] ?? ''));
$nama   = trim((string)($_GET['nama'] ?? ''));
$urut   = trim((string)($_GET['urut'] ?? ''));
$dasar  = trim((string)($_GET['dasar'] ?? ''));
if ($status !== '' && !in_array($status, ['belum', 'sebagian', 'sudah', 'tanpa', 'hilang', 'tlunas', 'tturun', 'ttetap', 'tnaik'], true)) keluar(['error' => 'Status tidak valid.'], 422);
if ($dasar !== '' && !in_array($dasar, ['keduanya', 'kewajiban', 'tunggakan'], true)) keluar(['error' => 'Dasar kelompok tidak valid.'], 422);
if ($nama !== '' && mb_strlen($nama) < 2) keluar(['error' => 'Nama atau nomor rekening minimal 2 karakter.'], 422);
if (mb_strlen($nama) > 40) $nama = mb_substr($nama, 0, 40);
if ($urut !== '' && !in_array($urut, ['hari', 'tgl'], true)) keluar(['error' => 'Urutan tidak valid.'], 422);

try {
    $p = [];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao'] = $ao;
    if ($dasar !== '') $p['dasar'] = $dasar;
    $p['hal'] = $hal;
    $p['per'] = $per;
    if ($status !== '') $p['status'] = $status;
    if ($nama !== '')   $p['nama']   = $nama;
    if ($urut !== '')   $p['urut']   = $urut;

    // jenis baru belum dikenal api_path(), jadi path monitoring diberikan eksplisit
    $r = api_call('progres', ['params' => $p], api_cfg()['path_monitoring'], 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);

    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) keluar(['error' => (string)$d['error']], 422);     // salah input menurut backend (pesannya aman ditampilkan)
    if (!empty($d['empty'])) keluar(['empty' => true, 'pesan' => (string)($d['pesan'] ?? 'Belum ada data.')]);

    keluar($d);
} catch (Throwable $e) {
    error_log('api_progres: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}