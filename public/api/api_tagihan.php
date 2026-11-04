<?php
/**
 * Proxy "tagihan AO" untuk kartu dashboard dan halaman tagihan.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET ?ringkas=1               -> ringkasan untuk kartu dashboard; {bukan_ao:true} bila akun bukan AO
 *   GET ?awal=YYYY-MM-DD&akhir=YYYY-MM-DD&ao=&urut=    -> ringkasan + daftar debitur
 *       awal, akhir: rentang TANGGAL PINJAM (hari dalam bulan), satu bulan yang sama; kosong = tanggal 1 sampai tanggal posisi terbaru
 *       urut: tgl | ao | hari
 *       ao: HANYA dipakai akun yang BUKAN AO (admin/pimpinan); kosong = semua AO. Akun AO dikunci ke kode_ao miliknya di API, jadi ?ao= diabaikan.
 *
 * Yang dikirim ke API adalah id pengguna dari SESI login (bukan dari alamat halaman), supaya AO tidak bisa melihat AO lain.
 * Letak: folder api/.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_tagihan');             // hak akses menu Tagihan AO

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) keluar(['error' => 'Sesi login tidak memuat id pengguna. Hubungi admin.'], 500);

$awal    = trim((string)($_GET['awal'] ?? ''));
$akhir   = trim((string)($_GET['akhir'] ?? ''));
$ao      = trim((string)($_GET['ao'] ?? ''));
$urut    = trim((string)($_GET['urut'] ?? ''));
$ringkas = (string)($_GET['ringkas'] ?? '') === '1';

$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if (($awal === '') !== ($akhir === ''))                              keluar(['error' => 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.'], 422);
if ($awal !== '' && (!$tglOk($awal) || !$tglOk($akhir)))              keluar(['error' => 'Tanggal tidak valid.'], 422);
if ($awal !== '' && $akhir < $awal)                                   keluar(['error' => 'Tanggal akhir tidak boleh sebelum tanggal awal.'], 422);
if ($awal !== '' && substr($awal, 0, 7) !== substr($akhir, 0, 7))     keluar(['error' => 'Tanggal awal dan akhir harus dalam bulan yang sama.'], 422);
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao))      keluar(['error' => 'Kode AO tidak valid.'], 422);
if ($urut !== '' && !in_array($urut, ['tgl', 'ao', 'hari'], true))    keluar(['error' => 'Urutan tidak valid.'], 422);

try {
    $p = ['uid' => (string)$uid];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao']   = $ao;
    if ($urut !== '') $p['urut'] = $urut;
    if ($ringkas)     $p['ringkas'] = '1';

    $r = api_call('tagihan', ['params' => $p], api_cfg()['path_monitoring'], 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);

    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) keluar(['error' => (string)$d['error']], 422);
    if (!empty($d['empty'])) keluar(['empty' => true, 'pesan' => (string)($d['pesan'] ?? 'Belum ada data.')]);
    keluar($d);
} catch (Throwable $e) {
    error_log('api_tagihan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}