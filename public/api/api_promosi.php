<?php
/**
 * Proxy "Promosi/Survey" untuk promosi.php dan laporan_promosi.php. TIDAK menyentuh database: semuanya lewat API Promosi.php (terenkripsi).
 *
 *   POST ?aksi=insert                          multipart: jenis_kegiatan, tanggal, jam, produk, prospek, keterangan, lokasi_gps, alamat_kegiatan, foto, _csrf   (hak 'web_promosi'). Produk, lokasi GPS, dan foto WAJIB.
 *   GET  ?aksi=read&tgl1=&tgl2=&jenis=         rekap statistik                                                                                  (hak 'web_promosi' atau 'web_lap_promosi')
 *   GET  ?aksi=preview&tgl1=&tgl2=&jenis=      daftar kegiatan                                                                                   (idem)
 *   GET  ?aksi=laporan&tgl1=&tgl2=&user=&jenis=  laporan + rekap                                                                                 (hak 'web_lap_promosi')
 *   GET  ?aksi=daftar_user                     daftar user untuk filter                                                                          (hak 'web_lap_promosi')
 *
 * KEAMANAN: API memercayai id_user dari request, jadi id pengguna DI SINI selalu diambil dari SESI login. Untuk insert, read, dan preview,
 * id_user = akun yang login; hanya pemegang hak 'web_lap_promosi' yang boleh melihat milik user lain (?user=).
 * Foto: dibaca ulang lewat GD dan disimpan sebagai JPEG berukuran maksimal 1600 px di uploads/promosi/ (tanpa metadata); yang dikirim ke API hanya path-nya.
 * Letak: folder api/.
 */
require __DIR__ . '/../lib.php';           // wajib login (dan pemeriksaan token CSRF untuk POST)
require_once __DIR__ . '/api_client.php';

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}
function ada_hak(string $menu): bool { return function_exists('boleh') && boleh($menu); }
function tgl_ok(string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; }
function jenis_ok(string $j): bool { return $j === '' || (bool)preg_match('/^[A-Za-z0-9 _\-]{1,30}$/', $j); }

/** Simpan foto unggahan sebagai JPEG baru (maks 1600 px, tanpa metadata). Mengembalikan path relatif, atau melempar RuntimeException berpesan aman. */
function simpan_foto(array $f): string
{
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $p = [UPLOAD_ERR_INI_SIZE => 'Foto melebihi batas upload server (upload_max_filesize di php.ini).', UPLOAD_ERR_FORM_SIZE => 'Foto terlalu besar.', UPLOAD_ERR_PARTIAL => 'Foto hanya terkirim sebagian. Coba lagi.'];
        throw new RuntimeException($p[$f['error']] ?? 'Foto gagal diunggah.');
    }
    if ($f['size'] > 12 * 1024 * 1024) throw new RuntimeException('Foto terlalu besar (maksimal 12 MB).');
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) throw new RuntimeException('Ekstensi PHP GD belum aktif (extension=gd di php.ini).');
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $f['tmp_name']) : '';
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) throw new RuntimeException('Foto harus berformat JPEG, PNG, atau WebP.');

    $img = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
    if (!$img) throw new RuntimeException('Foto tidak bisa dibaca.');
    if ($mime === 'image/jpeg' && function_exists('exif_read_data') && function_exists('imagerotate')) {          // putar sesuai arah foto dari kamera
        $ex = @exif_read_data($f['tmp_name']);
        $o  = (int)($ex['Orientation'] ?? 1);
        $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $w = imagesx($img); $h = imagesy($img);
    $s = min(1, 1600 / max($w, $h));
    if ($s < 1) {
        $baru = imagescale($img, (int)round($w * $s), (int)round($h * $s));
        if ($baru) { imagedestroy($img); $img = $baru; }
    }
    $rel = 'uploads/promosi/' . date('Y/m');
    $dir = dirname(__DIR__) . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) throw new RuntimeException('Folder foto tidak bisa dibuat (cek izin tulis folder uploads/).');
    $ht = dirname(__DIR__) . '/uploads/promosi/.htaccess';                                                           // folder foto: tanpa daftar isi, tanpa eksekusi PHP
    if (!is_file($ht)) @file_put_contents($ht, "Options -Indexes\n<FilesMatch \"\\.(php|phtml|phar|php[0-9])\$\">\n    Require all denied\n</FilesMatch>\n");
    $nama = bin2hex(random_bytes(8)) . '.jpg';
    if (!imagejpeg($img, $dir . '/' . $nama, 82)) { imagedestroy($img); throw new RuntimeException('Foto gagal disimpan.'); }
    imagedestroy($img);
    return $rel . '/' . $nama;
}

/** Hanya path foto buatan hosting ini atau URL http(s) yang boleh dipakai halaman; selain itu dikosongkan. */
function foto_aman(string $p): string
{
    if (preg_match('#^uploads/promosi/\d{4}/\d{2}/[0-9a-f]{16}\.jpg$#', $p)) return $p;
    if (preg_match('#^https?://#i', $p)) return $p;
    return '';
}

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) keluar(['error' => 'Sesi login tidak memuat id pengguna. Hubungi admin.'], 500);

$aksi = (string)($_GET['aksi'] ?? '');
$path = (string)(api_cfg()['path_promosi'] ?? '');
if ($path === '') keluar(['error' => "'path_promosi' belum diisi di config.api.php"], 500);

// ---------- hak akses ----------
$punyaLaporan = ada_hak('web_lap_promosi');
if ($aksi === 'insert')                                    { if (!ada_hak('web_promosi')) keluar(['error' => 'Akses ditolak.'], 403); }
elseif (in_array($aksi, ['read', 'preview'], true))        { if (!ada_hak('web_promosi') && !$punyaLaporan) keluar(['error' => 'Akses ditolak.'], 403); }
elseif (in_array($aksi, ['laporan', 'daftar_user'], true)) { if (!$punyaLaporan) keluar(['error' => 'Akses ditolak.'], 403); }
else keluar(['error' => 'Aksi tidak dikenal.'], 400);

$panggil = function (string $jenis, array $isi) use ($path) {
    $r = api_call($jenis, $isi, $path, 60);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Layanan tidak dapat memproses permintaan.')], 422);
    return $r;
};

try {
    if ($aksi === 'insert') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') keluar(['error' => 'Method tidak diizinkan.'], 405);
        $t = function (string $k, int $maks) { return trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')), 0, $maks)); };
        $jenis   = $t('jenis_kegiatan', 30);
        $tanggal = trim((string)($_POST['tanggal'] ?? ''));
        $jam     = trim((string)($_POST['jam'] ?? ''));
        $gps     = $t('lokasi_gps', 100);
        $prospek = preg_replace('/\D+/', '', (string)($_POST['prospek'] ?? ''));
        $ket     = trim(mb_substr((string)($_POST['keterangan'] ?? ''), 0, 5000));
        if ($jenis === '' || !jenis_ok($jenis))                                  keluar(['error' => 'Pilih jenis kegiatan.'], 422);
        if (!tgl_ok($tanggal))                                                   keluar(['error' => 'Tanggal kegiatan tidak valid.'], 422);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $jam))                    keluar(['error' => 'Jam kegiatan tidak valid (HH:MM).'], 422);
        $produk  = $t('produk', 250);
        if ($produk === '')                                                      keluar(['error' => 'Pilih produk yang dipromosikan.'], 422);
        if (!preg_match('/^-?\d{1,3}(\.\d+)?,\s*-?\d{1,3}(\.\d+)?$/', $gps))  keluar(['error' => 'Lokasi kegiatan wajib diambil (tombol Ambil Lokasi GPS).'], 422);
        if (strlen($prospek) > 9)                                                keluar(['error' => 'Jumlah prospek terlalu besar.'], 422);

        if (empty($_FILES['foto']) || !is_array($_FILES['foto']) || $_FILES['foto']['error'] === UPLOAD_ERR_NO_FILE) keluar(['error' => 'Foto kegiatan wajib diisi.'], 422);
        try { $foto = simpan_foto($_FILES['foto']); } catch (RuntimeException $e) { keluar(['error' => $e->getMessage()], 422); }

        $r = api_call('insert', ['jenis_kegiatan' => $jenis, 'tanggal' => $tanggal, 'jam' => $jam, 'produk' => $produk, 'prospek' => $prospek === '' ? '0' : $prospek,
                                 'keterangan' => $ket, 'lokasi_gps' => $gps, 'alamat_kegiatan' => $t('alamat_kegiatan', 250), 'foto' => $foto, 'id_user' => (string)$uid], $path, 60);
        if (($r['status'] ?? '') !== 'success') {                          // gagal disimpan: buang foto yang sudah terlanjur ditulis
            @unlink(dirname(__DIR__) . '/' . $foto);
            keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan data.')], 422);
        }
        keluar(['ok' => true, 'message' => (string)($r['message'] ?? 'Data berhasil disimpan.')]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

    if ($aksi === 'daftar_user') {
        $r = $panggil('daftar_user', []);
        keluar(['data' => (array)($r['data'] ?? [])]);
    }

    // read / preview / laporan: rentang tanggal + user + jenis kegiatan
    $tgl1 = trim((string)($_GET['tgl1'] ?? ''));
    $tgl2 = trim((string)($_GET['tgl2'] ?? ''));
    $jenis = trim((string)($_GET['jenis'] ?? ''));
    if (($tgl1 !== '' && !tgl_ok($tgl1)) || ($tgl2 !== '' && !tgl_ok($tgl2))) keluar(['error' => 'Tanggal harus berformat YYYY-MM-DD.'], 422);
    if ($tgl1 !== '' && $tgl2 !== '' && $tgl1 > $tgl2)                         keluar(['error' => 'Tanggal awal tidak boleh sesudah tanggal akhir.'], 422);
    if (!jenis_ok($jenis))                                                     keluar(['error' => 'Jenis kegiatan tidak valid.'], 422);

    if ($aksi === 'laporan') {
        $pilih = trim((string)($_GET['user'] ?? ''));                      // kosong = semua user
        if ($pilih !== '' && (!ctype_digit($pilih) || (int)$pilih > 32767)) keluar(['error' => 'User tidak valid.'], 422);
    } else {
        $pilih = (string)$uid;                                             // read/preview: milik sendiri
        if ($punyaLaporan && array_key_exists('user', $_GET)) {            // halaman laporan selalu mengirim ?user= (kosong = semua user)
            $pilih = trim((string)$_GET['user']);
            if ($pilih !== '' && (!ctype_digit($pilih) || (int)$pilih > 32767)) keluar(['error' => 'User tidak valid.'], 422);
        }
    }
    $isi = ['id_user' => $pilih];
    if ($tgl1 !== '') $isi['tgl1'] = $tgl1;
    if ($tgl2 !== '') $isi['tgl2'] = $tgl2;
    if ($jenis !== '') $isi[$aksi === 'laporan' ? 'jns_giat' : 'jenis_kegiatan'] = $jenis;
    $r = $panggil($aksi, $isi);

    if ($aksi === 'read') {
        keluar(['total' => (int)($r['total'] ?? 0), 'total_prospek' => (int)($r['total_prospek'] ?? 0), 'total_jenis' => (int)($r['total_jenis'] ?? 0), 'rata_prospek' => (int)($r['rata_prospek'] ?? 0)]);
    }
    $rows = (array)($r['rows'] ?? []);
    if ($aksi === 'preview') foreach ($rows as &$x) { $x['foto'] = foto_aman((string)($x['foto'] ?? '')); } unset($x);
    keluar($aksi === 'laporan' ? ['rows' => $rows, 'rekap' => (array)(($r['rows_rekap'] ?? [])[0] ?? [])] : ['rows' => $rows]);
} catch (Throwable $e) {
    error_log('api_promosi: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));   // tanpa isi kegiatan
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}