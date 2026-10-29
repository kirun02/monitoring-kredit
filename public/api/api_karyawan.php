<?php
/**
 * Proxy data karyawan + akun aplikasi untuk halaman karyawan.php. TIDAK menyentuh database: semuanya lewat API backend
 * (terenkripsi, endpoint 'path_karyawan'). Foto disimpan di hosting; backend hanya menyimpan nama filenya.
 *
 *   GET  ?jenis=daftar                 -> {rows:[{id, nama, jabatan, notlp, foto, foto_url, id_user, username, role, kode_ao, aktif}]}
 *   POST jenis=simpan  (+ foto)        -> tambah/ubah pegawai SEKALIGUS akunnya (id_karyawan 0 = baru)
 *   POST jenis=hapus   id_karyawan     -> hapus pegawai (akun tetap ada)
 */
require __DIR__ . '/../lib.php';           // wajib login + CSRF untuk POST
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_karyawan');

const FOTO_DIR  = __DIR__ . '/../uploads/karyawan';   // harus bisa ditulis oleh PHP
const FOTO_URL  = 'uploads/karyawan/';                // alamat relatif dari halaman di folder public
const FOTO_MAKS = 1048576;                            // 1 MB

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function path_kar() { return api_cfg()['path_karyawan']; }

function foto_url($f)
{
    $f = (string)$f;
    if ($f === '') return '';
    return strpos($f, '/') !== false ? $f : FOTO_URL . rawurlencode($f);
}

/** Hapus file foto, hanya yang berupa nama file polos di FOTO_DIR (foto lama di tempat lain tidak disentuh). */
function foto_hapus($f)
{
    $f = (string)$f;
    if ($f !== '' && $f === basename($f) && preg_match('~^[A-Za-z0-9_.\-]+$~', $f)) {
        @unlink(FOTO_DIR . '/' . $f);
    }
}

/** Simpan foto kiriman browser. Mengembalikan nama file, atau null bila tidak ada foto baru. Melempar InvalidArgumentException bila tidak sah. */
function foto_simpan()
{
    if (empty($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    $f = $_FILES['foto'];
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new InvalidArgumentException('Foto gagal diunggah.');
    if ($f['size'] > FOTO_MAKS) throw new InvalidArgumentException('Ukuran foto maksimal 1 MB.');

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if ($ext === null || @getimagesize($f['tmp_name']) === false) throw new InvalidArgumentException('Foto harus PNG, JPG, atau WEBP.');

    if (!is_dir(FOTO_DIR) && !@mkdir(FOTO_DIR, 0755, true)) throw new RuntimeException('folder foto tidak dapat dibuat');
    $nama = bin2hex(random_bytes(16)) . '.' . $ext;   // nama acak: tidak memakai nama dari pengguna
    if (!@move_uploaded_file($f['tmp_name'], FOTO_DIR . '/' . $nama)) throw new RuntimeException('foto tidak dapat disimpan');
    return $nama;
}

function daftar_karyawan()
{
    $r = api_call('daftar', [], path_kar());
    if (($r['status'] ?? '') !== 'success') throw new RuntimeException('API: ' . ($r['message'] ?? 'daftar gagal'));
    return $r['rows'] ?? [];
}

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));

try {
    // ---------- daftar ----------
    if (!$post && $jenis === 'daftar') {
        $rows = [];
        foreach (daftar_karyawan() as $x) {
            $rows[] = [
                'id'       => (int)$x['id'],
                'nama'     => (string)($x['nama'] ?? ''),
                'jabatan'  => (string)($x['bagian'] ?? ''),
                'notlp'    => (string)($x['no_hp'] ?? ''),
                'foto'     => (string)($x['foto'] ?? ''),
                'foto_url' => foto_url($x['foto'] ?? ''),
                'id_user'  => (int)($x['id_app_user'] ?? 0),
                'username' => (string)($x['username'] ?? ''),
                'role'     => (string)($x['role'] ?? ''),
                'kode_ao'  => (string)($x['kode_ao'] ?? ''),
                'aktif'    => !empty($x['aktif']),
            ];
        }
        keluar(['rows' => $rows]);
    }

    // ---------- simpan (pegawai + akun) ----------
    if ($post && $jenis === 'simpan') {
        $id   = (int)($_POST['id_karyawan'] ?? 0);
        $lama = '';
        if ($id > 0) {
            foreach (daftar_karyawan() as $x) {
                if ((int)$x['id'] === $id) $lama = (string)($x['foto'] ?? '');
            }
        }

        try {
            $baru = foto_simpan();
        } catch (InvalidArgumentException $e) {
            keluar(['error' => $e->getMessage()], 422);
        }

        $r = api_call('simpan', [
            'id_karyawan' => $id,
            'nama'        => (string)($_POST['nama'] ?? ''),
            'jabatan'     => (string)($_POST['jabatan'] ?? ''),
            'notlp'       => (string)($_POST['notlp'] ?? ''),
            'foto'        => $baru ?? $lama,                    // tanpa foto baru: foto lama dipertahankan
            'username'    => (string)($_POST['username'] ?? ''),
            'password'    => (string)($_POST['password'] ?? ''),
            'role'        => (string)($_POST['role'] ?? ''),
            'kode_ao'     => (string)($_POST['kode_ao'] ?? ''),
            'aktif'       => (string)($_POST['aktif'] ?? '0') === '1',
        ], path_kar());

        if (($r['status'] ?? '') !== 'success') {
            if ($baru) foto_hapus($baru);                        // simpan gagal: buang foto yang baru terunggah
            keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan.')], 422);
        }
        if ($baru && $lama !== '') foto_hapus($lama);            // foto lama diganti
        keluar(['message' => (string)($r['message'] ?? 'Data disimpan.')]);
    }

    // ---------- hapus ----------
    if ($post && $jenis === 'hapus') {
        $id = (int)($_POST['id_karyawan'] ?? 0);
        if ($id <= 0) keluar(['error' => 'Karyawan tidak valid.'], 422);

        $lama = '';
        foreach (daftar_karyawan() as $x) {
            if ((int)$x['id'] === $id) $lama = (string)($x['foto'] ?? '');
        }
        $r = api_call('hapus', ['id_karyawan' => $id], path_kar());
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Gagal menghapus.')], 422);
        foto_hapus($lama);
        keluar(['message' => (string)($r['message'] ?? 'Karyawan dihapus.') . ' Akun aplikasinya tidak ikut dihapus.']);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('api_karyawan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}