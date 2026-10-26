<?php
/**
 * Proxy pengguna untuk halaman pengguna.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET  ?jenis=daftar             -> {rows:[pengguna...], pegawai:[...]}
 *   POST jenis=input  (multipart)  -> tambah pengguna
 *   POST jenis=update (multipart)  -> ubah pengguna (password kosong = tidak diganti)
 *
 * Hanya admin. Hash password tidak pernah sampai ke browser, dan password tidak pernah ditulis ke log.
 */
require __DIR__ . '/../lib.php';           // wajib login + CSRF untuk POST
require_once __DIR__ . '/api_client.php';

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$u = auth_user() ?? [];
if (($u['staff'] ?? '') !== 'admin') keluar(['error' => 'Hanya admin yang boleh mengelola pengguna.'], 403);
$idSaya = (int)($u['id'] ?? 0);

$cfg  = api_cfg();
$path = (string)($cfg['path_pengguna'] ?? '');
if ($path === '') keluar(['error' => 'path_pengguna belum diatur di config.api.php.'], 500);

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));

try {
    // ---------- daftar ----------
    if (!$post && $jenis === 'daftar') {
        $r = api_call('daftar', [], $path);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Daftar pengguna tidak dapat dimuat.')], 502);

        $rows = [];
        foreach (($r['rows'] ?? []) as $x) {
            $rows[] = [
                'id'            => (int)$x['id'],
                'username'      => (string)($x['username'] ?? ''),
                'nama'          => (string)($x['nama'] ?? ''),
                'role'          => (string)($x['role'] ?? ''),
                'kode_ao'       => (string)($x['kode_ao'] ?? ''),
                'aktif'         => !empty($x['aktif']),
                'id_karyawan'   => (int)($x['id_karyawan'] ?? 0),
                'nama_karyawan' => (string)($x['nama_karyawan'] ?? ''),
            ];
        }
        keluar(['rows' => $rows, 'pegawai' => $r['pegawai'] ?? []]);
    }

    // ---------- input / update ----------
    if ($post && in_array($jenis, ['input', 'update'], true)) {
        $idTarget = (int)($_POST['id_pengguna'] ?? 0);
        $username = trim((string)($_POST['username'] ?? ''));
        $nama     = trim((string)($_POST['nama'] ?? ''));
        $role     = (string)($_POST['role'] ?? '');
        $pw       = (string)($_POST['password'] ?? '');
        $kodeAo   = trim((string)($_POST['kode_ao'] ?? ''));
        $aktif    = (string)($_POST['aktif'] ?? '') === '1';
        $idKar    = max(0, (int)($_POST['id_karyawan'] ?? 0));

        if (!preg_match('/^[A-Za-z0-9_.\-]{3,30}$/', $username)) keluar(['error' => 'Username 3-30 karakter: huruf, angka, titik, garis bawah, atau strip.'], 422);
        if ($nama === '' || mb_strlen($nama) > 100)             keluar(['error' => 'Nama wajib diisi (maksimal 100 karakter).'], 422);
        if (!in_array($role, ['admin', 'pimpinan', 'ao'], true)) keluar(['error' => 'Pilih role pengguna.'], 422);
        if ($jenis === 'input' && $pw === '')                   keluar(['error' => 'Password wajib diisi untuk pengguna baru.'], 422);
        if ($pw !== '' && (strlen($pw) < 8 || strlen($pw) > 72)) keluar(['error' => 'Password 8-72 karakter.'], 422);
        if (!preg_match('/^[0-9A-Za-z]{0,15}$/', $kodeAo))      keluar(['error' => 'Kode AO hanya huruf dan angka, maksimal 15 karakter.'], 422);
        if ($jenis === 'update' && $idTarget <= 0)              keluar(['error' => 'Pengguna tidak valid.'], 422);

        // admin tidak boleh mengunci dirinya sendiri
        if ($jenis === 'update' && $idTarget === $idSaya && (!$aktif || $role !== 'admin')) {
            keluar(['error' => 'Akun yang sedang kamu pakai tidak boleh dinonaktifkan atau diturunkan perannya.'], 422);
        }

        $isi = [
            'username'    => $username,
            'nama'        => $nama,
            'role'        => $role,
            'password'    => $pw,
            'kode_ao'     => $role === 'ao' ? $kodeAo : '',
            'aktif'       => $aktif,
            'id_karyawan' => $idKar,
        ];
        if ($jenis === 'update') $isi['id_pengguna'] = $idTarget;

        $r = api_call($jenis, $isi, $path);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan pengguna.')], 422);
        keluar(['message' => (string)($r['message'] ?? 'Data berhasil disimpan.')]);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('api_pengguna: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}