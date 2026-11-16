<?php
/**
 * Upload agunan dari Excel (.xlsx). Letak: folder api/.
 *
 *   POST api/api_agunan_upload.php   (multipart: berkas, mode=periksa|simpan, abaikan_kosong=1|0, _csrf)
 *     -> membaca Excel di hosting, mengirim barisnya (terenkripsi) ke API Agunan.php, mengembalikan ringkasan JSON.
 *     mode periksa = uji coba (API membatalkan transaksi, tidak ada yang tersimpan); mode simpan = tersimpan.
 *
 * Hak akses: menu 'web_upload_agunan'. Akun AO ditolak oleh API (hanya admin/pimpinan). Isi file TIDAK dicatat di log.
 * Pembaca Excel dan pengirim ke API: ../agunan_lib.php.
 */
require __DIR__ . '/../lib.php';           // wajib login (dan pemeriksaan token CSRF untuk POST)
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../agunan_lib.php';
akses_atau_403('web_upload_agunan');

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') keluar(['error' => 'Method tidak diizinkan.'], 405);

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) keluar(['error' => 'Sesi login tidak memuat id pengguna. Hubungi admin.'], 500);

$f = $_FILES['berkas'] ?? null;
if (!$f || !is_array($f)) keluar(['error' => 'Pilih file Excel (.xlsx) dulu.'], 422);
if ($f['error'] !== UPLOAD_ERR_OK) {
    $pesan = [UPLOAD_ERR_INI_SIZE => 'File melebihi batas upload server (upload_max_filesize di php.ini).', UPLOAD_ERR_FORM_SIZE => 'File terlalu besar.',
              UPLOAD_ERR_PARTIAL => 'File hanya terkirim sebagian. Coba lagi.', UPLOAD_ERR_NO_FILE => 'Pilih file Excel (.xlsx) dulu.'];
    keluar(['error' => $pesan[$f['error']] ?? 'File gagal diunggah.'], 422);
}
if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') keluar(['error' => 'File harus berformat .xlsx.'], 422);
$kepala = (string)@file_get_contents($f['tmp_name'], false, null, 0, 4);
if ($kepala !== "PK\x03\x04") keluar(['error' => 'File bukan .xlsx yang valid (bukan berkas Excel modern).'], 422);

$simpan  = (string)($_POST['mode'] ?? 'periksa') === 'simpan';
$abaikan = (string)($_POST['abaikan_kosong'] ?? '') === '1';

@set_time_limit(900);
@ini_set('memory_limit', '512M');
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // proses panjang tidak mengunci sesi

try {
    $m = agn_muat($f['tmp_name'], $abaikan);
    $h = agn_kirim($m['rows'], $uid, !$simpan, $abaikan);
    keluar([
        'mode' => $simpan ? 'simpan' : 'periksa', 'berkas' => (string)$f['name'],
        'baris_file' => $m['baris_file'], 'duplikat_file' => $m['duplikat'], 'kolom_tidak_ada' => $m['kolom_tidak_ada'],
    ] + $h);
} catch (RuntimeException $e) {
    keluar(['error' => $e->getMessage()], 422);                       // pesan buatan sendiri: aman ditampilkan
} catch (Throwable $e) {
    error_log('api_agunan_upload: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));   // tanpa isi file
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
