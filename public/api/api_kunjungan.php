<?php
/**
 * Proxy kunjungan untuk halaman kunjungan.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET  ?jenis=preview            -> kunjungan hari ini milik pemakai yang login         {rows:[...]}
 *   GET  ?jenis=cari&nama=&page=   -> cari debitur menurut nama / rekening (server-side)       {rows:[...], total, total_page, page}
 *   POST jenis=insert (multipart)  -> simpan kunjungan + foto                             {message} / {error}
 *
 * id_user SELALU diambil dari sesi login, tidak pernah dari browser (kalau tidak, siapa pun bisa mencatat atas nama AO lain).
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

function tgl_valid($v)
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

$u      = auth_user() ?? [];
$idUser = (int)($u['id'] ?? ($u['id_user'] ?? 0));
if ($idUser < 1) keluar(['error' => 'Akun tidak valid. Silakan masuk ulang.'], 403);

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));
$cfg   = api_cfg();
$fotoRel = '';

try {
    // ---------- kunjungan hari ini ----------
    if (!$post && $jenis === 'preview') {
        $hariIni = date('Y-m-d');
        $tgl1 = (string)($_GET['tgl1'] ?? $hariIni);
        $tgl2 = (string)($_GET['tgl2'] ?? $hariIni);
        if (!tgl_valid($tgl1) || !tgl_valid($tgl2) || $tgl1 > $tgl2 || $tgl1 < date('Y-m-d', strtotime('-31 day'))) {
            $tgl1 = $tgl2 = $hariIni;
        }
        $r = api_call('preview', ['tgl1' => $tgl1, 'tgl2' => $tgl2, 'id_user' => (string)$idUser], $cfg['path_kunjungan']);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Daftar tidak dapat dimuat.')], 502);
        $rows = [];
        foreach (($r['rows'] ?? []) as $x) {
            $g = (string)($x['gambar'] ?? '');
            $x['foto'] = preg_match(FOTO_POLA_JALUR, $g) ? 'foto.php?f=' . rawurlencode($g) : '';
            unset($x['gambar']);
            $rows[] = $x;
        }
        keluar(['rows' => $rows]);
    }

    // ---------- cari debitur (dialog Cari Debitur) ----------
    // Pencarian di server, per halaman, menurut nama atau nomor rekening (tanpa batasan AO).
    //   ?jenis=cari&nama=KATA&page=N   -> {rows:[...], total, total_page, page}
    // Kata kunci berupa huruf+angka tanpa spasi yang memuat angka dianggap nomor rekening (cocok persis).
    if (!$post && $jenis === 'cari') {
        $q    = trim((string)($_GET['nama'] ?? ''));
        $hal  = max(1, min(10000, (int)($_GET['page'] ?? 1)));
        $per  = 10;
        if (mb_strlen($q) < 2) keluar(['error' => 'Ketik minimal 2 karakter.'], 422);

        $norek = (bool)preg_match('/^[0-9A-Za-z]{1,15}$/', $q) && (bool)preg_match('/\d/', $q);
        $r = $norek
            ? api_call('norek', ['rekening' => $q], $cfg['path_nasabah'])
            : api_call('kredit', ['nama' => $q, 'page' => $hal, 'total_record' => $per], $cfg['path_nasabah']);

        $st = (string)($r['status'] ?? '');
        if ($st === 'gagal') keluar(['rows' => [], 'total' => 0, 'total_page' => 1, 'page' => 1]);   // tidak ada yang cocok
        if ($st !== 'success') keluar(['error' => (string)($r['message'] ?? 'Pencarian debitur gagal.')], 502);

        $rows = [];
        foreach (($r['debitur'] ?? []) as $x) {
            $rows[] = [
                'norek'  => (string)($x['rekening'] ?? ''), 'cif' => (string)($x['cif'] ?? ''), 'nama' => (string)($x['nama'] ?? ''),
                'alamat' => (string)($x['alamat'] ?? ''),   'baki' => (string)($x['baki'] ?? ''), 'plafon' => '',
                'kolek'  => (int)($x['kolek'] ?? 0),        'hari' => (int)($x['hari'] ?? 0),     'tempo' => (string)($x['tempo'] ?? ''),
            ];
        }
        keluar([
            'rows'       => $rows,
            'total'      => $norek ? count($rows) : (int)($r['total'] ?? count($rows)),
            'total_page' => $norek ? 1 : max(1, (int)($r['total_page'] ?? 1)),
            'page'       => $norek ? 1 : $hal,
        ]);
    }


    // ---------- daftar AO (hanya admin/pimpinan) ----------
    if (!$post && $jenis === 'daftar_user') {
        if (($u['staff'] ?? '') === 'ao') keluar(['data' => []]);
        $r = api_call('daftar_user', [], $cfg['path_kunjungan']);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Daftar AO tidak dapat dimuat.')], 502);
        keluar(['data' => $r['data'] ?? []]);
    }

    // ---------- laporan kunjungan ----------
    if (!$post && $jenis === 'laporan') {
        $tgl1 = (string)($_GET['tgl1'] ?? date('Y-m-01'));
        $tgl2 = (string)($_GET['tgl2'] ?? date('Y-m-d'));
        if (!tgl_valid($tgl1) || !tgl_valid($tgl2) || $tgl1 > $tgl2) keluar(['error' => 'Rentang tanggal tidak valid.'], 422);
        if ((strtotime($tgl2) - strtotime($tgl1)) / 86400 > 366) keluar(['error' => 'Rentang maksimal 366 hari.'], 422);

        if (($u['staff'] ?? '') === 'ao') {
            $idFilter = (string)$idUser;                 // AO: dipaksa dari sesi
        } else {
            $idFilter = (string)($_GET['id_user'] ?? '');
            if ($idFilter !== '' && (!ctype_digit($idFilter) || (int)$idFilter < 1 || (int)$idFilter > 32767)) {
                keluar(['error' => 'Pilihan AO tidak valid.'], 422);
            }
        }

        $r = api_call('laporan', ['tgl1' => $tgl1, 'tgl2' => $tgl2, 'id_user' => $idFilter], $cfg['path_kunjungan']);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Laporan tidak dapat dimuat.')], 502);
        keluar(['rows' => $r['rows'] ?? []]);            // 'gambar' diteruskan apa adanya, halaman yang memfilter polanya
    }

    // ---------- simpan kunjungan ----------
    if ($post && $jenis === 'insert') {
        $rek  = trim((string)($_POST['rekening'] ?? ''));
        $nama = trim((string)($_POST['nama'] ?? ''));
        $ket  = trim((string)($_POST['keterangan'] ?? ''));
        $komit = trim((string)($_POST['tgl_komit'] ?? ''));

        if (!preg_match('/^[0-9A-Za-z]{1,15}$/', $rek)) keluar(['error' => 'Nomor rekening harus berupa huruf atau angka, maksimal 15 karakter.'], 422);
        if ($nama === '')                               keluar(['error' => 'Nama debitur wajib diisi.'], 422);
        if ($ket === '')                                keluar(['error' => 'Keterangan kunjungan wajib diisi.'], 422);
        if ($komit !== '' && (!tgl_valid($komit) || $komit < date('Y-m-d'))) keluar(['error' => 'Tanggal komitmen harus tanggal hari ini atau sesudahnya.'], 422);
        if (!isset($_FILES['foto']) || ($_FILES['foto']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) keluar(['error' => 'Foto kunjungan wajib diisi.'], 422);

        try {
            $fotoRel = foto_simpan($_FILES['foto'], $idUser);
        } catch (RuntimeException $e) {
            keluar(['error' => $e->getMessage()], 422);
        }

        $r = api_call('insert', [
            'rekening'   => $rek,
            'nama'       => $nama,
            'alamat'     => trim((string)($_POST['alamat'] ?? '')),
            'foto'       => $fotoRel,
            'keterangan' => $ket,
            'id_user'    => $idUser,                       // dari sesi, bukan dari form
            'tgl_komit'  => $komit,
            'lokasi'     => trim((string)($_POST['lokasi'] ?? '')),
        ], $cfg['path_kunjungan']);

        if (($r['status'] ?? '') !== 'success') {
            foto_hapus($fotoRel);
            keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan data.')], 422);
        }
        keluar(['message' => (string)($r['message'] ?? 'Data berhasil disimpan.')]);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    try { foto_hapus($fotoRel); } catch (Throwable $e2) {}
    error_log('api_kunjungan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}