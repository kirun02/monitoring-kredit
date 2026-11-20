<?php
/**
 * Proxy "Target AO" untuk target.php. TIDAK menyentuh database: semuanya lewat API Target_ao.php (terenkripsi).
 *   GET  ?aksi=baca&periode=YYYY-MM      daftar AO + target (kosong = periode data terakhir)
 *   POST ?aksi=simpan                    ao, periode, target_os, target_realisasi, target_noa, target_kunjungan, target_promosi, target_pasca, _csrf
 *   POST ?aksi=salin                     periode, _csrf   (salin target bulan lalu)
 * Hak menu: 'web_target'. Hak UBAH diputuskan backend dari app_user.role. id_user selalu dari SESI login.
 * Letak: folder api/.
 */
require __DIR__ . '/../lib.php';
require_once __DIR__ . '/api_client.php';

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if (!(function_exists('boleh') && boleh('web_target_ao'))) keluar(['error' => 'Akses ditolak.'], 403);

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) keluar(['error' => 'Sesi login tidak memuat id pengguna. Hubungi admin.'], 500);

$path = (string)(api_cfg()['path_target'] ?? '');
if ($path === '') keluar(['error' => "'path_target' belum diisi di config.api.php"], 500);

$aksi = (string)($_GET['aksi'] ?? '');
$panggil = function (string $jenis, array $isi) use ($path, $uid) {
    $isi['id_user'] = (string)$uid;
    $r = api_call($jenis, $isi, $path, 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Layanan tidak dapat memproses permintaan.')], 422);
    return $r;
};
$per_ok = function (string $p) { return (bool)preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p); };

try {
    if ($aksi === 'baca') {
        if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);
        $per = trim((string)($_GET['periode'] ?? ''));
        if ($per !== '' && !$per_ok($per)) keluar(['error' => 'Periode tidak valid.'], 422);
        $r = $panggil('baca', $per === '' ? [] : ['periode' => $per]);
        keluar(['periode' => (string)($r['periode'] ?? ''), 'boleh_ubah' => (bool)($r['boleh_ubah'] ?? false), 'rows' => (array)($r['rows'] ?? [])]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') keluar(['error' => 'Method tidak diizinkan.'], 405);
    $per = trim((string)($_POST['periode'] ?? ''));
    if (!$per_ok($per)) keluar(['error' => 'Periode tidak valid.'], 422);

    if ($aksi === 'simpan') {
        $ao = trim((string)($_POST['ao'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) keluar(['error' => 'Kode AO tidak valid.'], 422);
        $isi = ['ao' => $ao, 'periode' => $per];
        foreach (['target_os', 'target_realisasi', 'target_noa', 'target_penagihan'] as $k) {
            if (!isset($_POST[$k])) continue;
            $s = trim((string)$_POST[$k]);
            if ($s !== '' && !ctype_digit($s)) keluar(['error' => 'Isi ' . $k . ' dengan angka bulat tanpa titik/koma.'], 422);
            $isi[$k] = $s === '' ? '0' : $s;
        }
        $r = $panggil('simpan', $isi);
        keluar(['ok' => true, 'message' => (string)($r['message'] ?? 'Target tersimpan.')]);
    }

    if ($aksi === 'salin') {
        $r = $panggil('salin', ['periode' => $per]);
        keluar(['ok' => true, 'message' => (string)($r['message'] ?? 'Selesai.'), 'jumlah' => (int)($r['jumlah'] ?? 0)]);
    }
    keluar(['error' => 'Aksi tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('api_target: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}