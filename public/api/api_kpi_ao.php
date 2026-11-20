<?php
/**
 * Proxy "Pencapaian target AO" untuk laporan_target.php. TIDAK menyentuh database: semuanya lewat API Monitoring (jenis 'kpi_ao', terenkripsi).
 *   GET ?ao=      -> {rows, tgl_posisi, periode}  atau {empty:true, pesan}
 *       ao: HANYA dipakai akun yang BUKAN AO (admin/pimpinan); kosong = semua AO. Akun AO dikunci ke AO miliknya di API, jadi ?ao= diabaikan.
 * Hak menu: 'web_target_ao'. id pengguna dikirim dari SESI login (bukan dari alamat halaman). Letak: folder api/.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_target_ao');

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

$ao = trim((string)($_GET['ao'] ?? ''));
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) keluar(['error' => 'Kode AO tidak valid.'], 422);

try {
    $p = ['uid' => (string)$uid];
    if ($ao !== '') $p['ao'] = $ao;
    $r = api_call('kpi_ao', ['params' => $p], api_cfg()['path_monitoring'], 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) keluar(['error' => (string)$d['error']], 422);
    if (!empty($d['empty'])) keluar(['empty' => true, 'pesan' => (string)($d['pesan'] ?? 'Belum ada data.')]);
    keluar($d);
} catch (Throwable $e) {
    error_log('api_kpi_ao: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
