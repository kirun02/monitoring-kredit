<?php
/**
 * Proxy absensi untuk halaman absensi.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   POST jenis=input (multipart)  -> simpan absensi + foto     {message} / {error}
 *
 * id_user SELALU diambil dari sesi login, tidak pernah dari browser (kalau tidak, siapa pun bisa absen atas nama orang lain).
 * Backend yang memetakan id_user (app_user.id) ke karyawan.id lewat karyawan.id_app_user.
 */
require __DIR__ . '/../lib.php';              // wajib login + CSRF untuk POST
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../foto_lib.php';
date_default_timezone_set('Asia/Jakarta');

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$u      = auth_user() ?? [];
$idUser = (int)($u['id'] ?? ($u['id_user'] ?? 0));
if ($idUser < 1) keluar(['error' => 'Akun tidak valid. Silakan masuk ulang.'], 403);

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : '');
$cfg   = api_cfg();
$fotoRel = '';

try {
    // ---------- simpan absensi ----------
    if ($post && $jenis === 'input') {
        $type   = trim((string)($_POST['type_absen'] ?? ''));
        $lokasi = trim((string)($_POST['lokasi'] ?? ''));

        if (!in_array($type, ['masuk', 'pulang'], true)) keluar(['error' => 'Pilih absen masuk atau pulang.'], 422);
        if (!preg_match('/^-?\d{1,3}\.\d+,\s*-?\d{1,3}\.\d+$/', $lokasi)) keluar(['error' => 'Lokasi wajib diambil sebelum absen.'], 422);
        if (!isset($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) keluar(['error' => 'Foto absen wajib diisi.'], 422);

        try {
            $fotoRel = foto_simpan($_FILES['foto'], $idUser);
        } catch (RuntimeException $e) {
            keluar(['error' => $e->getMessage()], 422);
        }

        $r = api_call('input', [
            'id_user'    => $idUser,                       // dari sesi, bukan dari form
            'type_absen' => $type,
            'foto'       => $fotoRel,
            'lokasi'     => $lokasi,
        ], $cfg['path_absensi']);

        if (($r['status'] ?? '') !== 'success') {
            foto_hapus($fotoRel);
            keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan absen.')], 422);
        }
        keluar(['message' => (string)($r['message'] ?? 'Absen berhasil disimpan.')]);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    try { foto_hapus($fotoRel); } catch (Throwable $e2) {}
    error_log('api_absensi: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}