<?php
/**
 * Proxy karyawan untuk halaman karyawan.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET  ?jenis=daftar             -> {rows:[karyawan...], akun:[akun login + dipakai]}
 *   POST jenis=input  (multipart)  -> tambah karyawan (+ foto)
 *   POST jenis=update (multipart)  -> ubah karyawan (foto lama dipertahankan bila tidak diganti)
 *   POST jenis=hapus               -> hapus karyawan (+ foto)
 *
 * Hanya pengguna dengan hak akses menu Data Karyawan. Path foto yang dikirim ke API SELALU berasal dari foto_simpan() atau dari data API, tidak pernah dari browser.
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

/** Ambil daftar karyawan dan daftar akun dari API. */
function muat_data($path)
{
    $k = api_call('daftar', [], $path);
    $a = api_call('akun', [], $path);
    if (($k['status'] ?? '') !== 'success' || ($a['status'] ?? '') !== 'success') {
        throw new RuntimeException('data karyawan tidak dapat dimuat');
    }
    return [$k['rows'] ?? [], $a['rows'] ?? []];
}

akses_atau_403('web_karyawan');   // hak akses menu Data Karyawan

$cfg  = api_cfg();
$path = (string)($cfg['path_karyawan'] ?? '');
if ($path === '') keluar(['error' => 'path_karyawan belum diatur di config.api.php.'], 500);

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));
$fotoBaru = '';

try {
    // ---------- daftar ----------
    if (!$post && $jenis === 'daftar') {
        list($kar, $akun) = muat_data($path);
        $dipakai = [];
        $rows = [];
        foreach ($kar as $x) {
            $g = (string)($x['foto'] ?? '');
            $dipakai[(int)$x['id_app_user']] = (int)$x['id'];
            $rows[] = [
                'id'       => (int)$x['id'],
                'nama'     => (string)($x['nama'] ?? ''),
                'jabatan'  => (string)($x['bagian'] ?? ''),
                'notlp'    => (string)($x['no_hp'] ?? ''),
                'id_user'  => (int)$x['id_app_user'],
                'username' => (string)($x['username'] ?? ''),
                'foto_url' => preg_match(FOTO_POLA_JALUR, $g) ? 'foto.php?f=' . rawurlencode($g) : '',
            ];
        }
        $listAkun = [];
        foreach ($akun as $a) {
            $id = (int)$a['id'];
            $listAkun[] = ['id' => $id, 'username' => (string)($a['username'] ?? ''), 'dipakai_oleh' => $dipakai[$id] ?? 0];
        }
        keluar(['rows' => $rows, 'akun' => $listAkun]);
    }

    // ---------- input / update / hapus ----------
    if ($post && in_array($jenis, ['input', 'update', 'hapus'], true)) {
        list($kar, $akun) = muat_data($path);
        $idKar = (int)($_POST['id_karyawan'] ?? 0);
        $lama  = null;
        if ($jenis !== 'input') {
            foreach ($kar as $x) if ((int)$x['id'] === $idKar) $lama = $x;
            if (!$lama) keluar(['error' => 'Data karyawan tidak ditemukan.'], 404);
        }

        if ($jenis === 'hapus') {
            $r = api_call('hapus', ['id_karyawan' => $idKar], $path);
            if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Gagal menghapus data.')], 422);
            if (!empty($lama['foto'])) foto_hapus((string)$lama['foto']);
            keluar(['message' => 'Karyawan dihapus.']);
        }

        $nama   = trim((string)($_POST['nama'] ?? ''));
        $jab    = trim((string)($_POST['jabatan'] ?? ''));
        $hp     = trim((string)($_POST['notlp'] ?? ''));
        $idAkun = (int)($_POST['id_user'] ?? 0);

        if ($nama === '' || mb_strlen($nama) > 100) keluar(['error' => 'Nama pegawai wajib diisi (maksimal 100 karakter).'], 422);
        if (mb_strlen($jab) > 30)                   keluar(['error' => 'Jabatan maksimal 30 karakter.'], 422);
        if ($hp !== '' && !preg_match('/^[0-9+\-\s()]{5,20}$/', $hp)) keluar(['error' => 'Nomor telepon tidak valid.'], 422);

        $akunAda = false;
        foreach ($akun as $a) if ((int)$a['id'] === $idAkun) $akunAda = true;
        if (!$akunAda) keluar(['error' => 'Pilih akun aplikasi yang valid.'], 422);
        foreach ($kar as $x) {
            if ((int)$x['id_app_user'] === $idAkun && (int)$x['id'] !== $idKar) keluar(['error' => 'Akun ini sudah dipakai karyawan lain.'], 422);
        }

        $fotoPakai = (string)($lama['foto'] ?? '');
        if (isset($_FILES['foto']) && ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            try {
                $fotoBaru  = foto_simpan($_FILES['foto'], $idAkun);
                $fotoPakai = $fotoBaru;
            } catch (RuntimeException $e) {
                keluar(['error' => $e->getMessage()], 422);
            }
        }

        $isi = ['nama' => $nama, 'jabatan' => $jab, 'id_user' => $idAkun, 'notlp' => $hp, 'foto' => $fotoPakai];
        if ($jenis === 'update') $isi['id_karyawan'] = $idKar;

        $r = api_call($jenis, $isi, $path);
        if (($r['status'] ?? '') !== 'success') {
            if ($fotoBaru !== '') foto_hapus($fotoBaru);
            keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan data.')], 422);
        }
        if ($jenis === 'update' && $fotoBaru !== '' && !empty($lama['foto'])) foto_hapus((string)$lama['foto']);   // foto lama diganti
        keluar(['message' => $jenis === 'input' ? 'Karyawan ditambahkan.' : 'Perubahan disimpan.']);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    try { if ($fotoBaru !== '') foto_hapus($fotoBaru); } catch (Throwable $e2) {}
    error_log('api_karyawan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}