<?php
/**
 * Proxy "ketepatan bayar" untuk halaman ketepatan.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET ?dari=YYYY-MM&sampai=YYYY-MM&ao=&toleransi=0..10&pola=&status=&xmin=&urut=&hal=&per=&nama=
 *       -> data dari jenis_req 'ketepatan': ringkasan per bulan + daftar debitur per halaman (daftar.rows), atau {empty:true,pesan}
 *       Tanpa dari/sampai -> backend memakai 6 bulan terakhir yang ada datanya.
 *       pola: tepat | selalu | campuran | satu | belum; status (bulan terakhir): tepat | telat_bayar | telat_belum | lost | belum_tempo | tanpa_kw | keluar
 *       xmin: minimal kali menunggak (0-99); urut: kosong (paling bermasalah) | tgl (hari tgl pinjam 1-31); nama: cari nama / no. rekening (min 2 huruf); per: 10 | 20 | 50
 *
 * Letak: folder api/.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_ketepatan');           // hak akses menu Ketepatan bayar

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

$dari   = trim((string)($_GET['dari'] ?? ''));
$sampai = trim((string)($_GET['sampai'] ?? ''));
$ao     = trim((string)($_GET['ao'] ?? ''));
$tol    = (int)($_GET['toleransi'] ?? 0);
$pola   = trim((string)($_GET['pola'] ?? ''));
$nama   = trim((string)($_GET['nama'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$xmin   = (int)($_GET['xmin'] ?? 0);
$urut   = trim((string)($_GET['urut'] ?? ''));
$hal    = max(1, min(10000, (int)($_GET['hal'] ?? 1)));
$per    = in_array($pp = (int)($_GET['per'] ?? 20), [10, 20, 50], true) ? $pp : 20;

$fmtP = function (string $t): bool { return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $t); };
if ($dari !== '' && !$fmtP($dari))                       keluar(['error' => 'Bulan awal tidak valid.'], 422);
if ($sampai !== '' && !$fmtP($sampai))                   keluar(['error' => 'Bulan akhir tidak valid.'], 422);
if ($dari !== '' && $sampai !== '' && $sampai < $dari)   keluar(['error' => 'Bulan akhir tidak boleh sebelum bulan awal.'], 422);
if ($tol < 0 || $tol > 10)                               keluar(['error' => 'Toleransi keterlambatan 0 sampai 10 hari.'], 422);
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) keluar(['error' => 'Kode AO tidak valid.'], 422);
if ($pola !== '' && !in_array($pola, ['tepat', 'selalu', 'campuran', 'satu', 'belum'], true)) keluar(['error' => 'Pola tidak valid.'], 422);
if ($status !== '' && !in_array($status, ['tepat', 'telat_bayar', 'telat_belum', 'lost', 'belum_tempo', 'tanpa_kw', 'keluar'], true)) keluar(['error' => 'Status tidak valid.'], 422);
if ($xmin < 0 || $xmin > 99)                             keluar(['error' => 'Minimal kali menunggak 0 sampai 99.'], 422);
if ($urut !== '' && !in_array($urut, ['tgl'], true))     keluar(['error' => 'Urutan tidak valid.'], 422);
if ($nama !== '' && mb_strlen($nama) < 2)                keluar(['error' => 'Nama atau nomor rekening minimal 2 karakter.'], 422);
if (mb_strlen($nama) > 40) $nama = mb_substr($nama, 0, 40);

try {
    $p = ['toleransi' => (string)$tol, 'hal' => $hal, 'per' => $per];
    if ($dari !== '')   $p['dari']   = $dari;
    if ($sampai !== '') $p['sampai'] = $sampai;
    if ($ao !== '')     $p['ao']     = $ao;
    if ($pola !== '')   $p['pola']   = $pola;
    if ($nama !== '')   $p['nama']   = $nama;
    if ($status !== '') $p['status'] = $status;
    if ($xmin > 0)      $p['xmin']   = (string)$xmin;
    if ($urut !== '')   $p['urut']   = $urut;

    // jenis baru belum dikenal api_path(), jadi path monitoring diberikan eksplisit
    $r = api_call('ketepatan', ['params' => $p], api_cfg()['path_monitoring'], 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);

    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) keluar(['error' => (string)$d['error']], 422);     // salah input menurut backend (pesannya aman ditampilkan)
    if (!empty($d['empty'])) keluar(['empty' => true, 'pesan' => (string)($d['pesan'] ?? 'Belum ada data.')]);

    keluar($d);
} catch (Throwable $e) {
    error_log('api_ketepatan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}