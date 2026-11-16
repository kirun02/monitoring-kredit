<?php
/**
 * Proxy "Telepon" untuk telepon.php dan laporan_telepon.php. TIDAK menyentuh database: semuanya lewat API Telepon.php (terenkripsi).
 *
 *   POST ?aksi=insert                        rekening, nama, alamat, keterangan, tgl_komit, _csrf   (hak 'web_telepon')
 *   GET  ?aksi=read&tgl1=&tgl2=              jumlah telepon                                           (hak 'web_telepon' atau 'web_laporan_telepon')
 *   GET  ?aksi=preview&tgl1=&tgl2=           daftar telepon                                           (idem)
 *   GET  ?aksi=laporan&tgl1=&tgl2=&user=     laporan lengkap (digabung data kredit)                   (hak 'web_laporan_telepon')
 *   GET  ?aksi=daftar_user                   daftar user aktif untuk filter                           (hak 'web_laporan_telepon')
 *
 * KEAMANAN: API memercayai id_user dari request, jadi id pengguna DI SINI selalu diambil dari SESI login. Untuk insert, read, dan preview,
 * id_user = akun yang login; hanya pemegang hak 'web_laporan_telepon' yang boleh melihat milik user lain (?user=). Letak: folder api/.
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

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) keluar(['error' => 'Sesi login tidak memuat id pengguna. Hubungi admin.'], 500);

$aksi = (string)($_GET['aksi'] ?? '');
$path = (string)(api_cfg()['path_telepon'] ?? '');
if ($path === '') keluar(['error' => "'path_telepon' belum diisi di config.api.php"], 500);

// ---------- hak akses ----------
$punyaLaporan = ada_hak('web_lap_telepon');
if ($aksi === 'insert')                                 { if (!ada_hak('web_telepon')) keluar(['error' => 'Akses ditolak.'], 403); }
elseif (in_array($aksi, ['read', 'preview'], true))     { if (!ada_hak('web_telepon') && !$punyaLaporan) keluar(['error' => 'Akses ditolak.'], 403); }
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
        $t = function (string $k, int $maks) {
            return trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')), 0, $maks));
        };
        $rek  = trim((string)($_POST['rekening'] ?? ''));
        $ket  = trim(mb_substr((string)($_POST['keterangan'] ?? ''), 0, 2000));
        $komit = trim((string)($_POST['tgl_komit'] ?? ''));
        if (!preg_match('/^[0-9A-Za-z]{1,15}$/', $rek)) keluar(['error' => 'No. rekening harus 1 sampai 15 huruf/angka.'], 422);
        if ($ket === '')                                keluar(['error' => 'Keterangan wajib diisi.'], 422);
        if ($komit !== '' && !tgl_ok($komit))           keluar(['error' => 'Tanggal janji bayar tidak valid.'], 422);
        $r = $panggil('insert', ['rekening' => $rek, 'id_user' => (string)$uid, 'nama' => $t('nama', 50), 'alamat' => $t('alamat', 150),
                                 'keterangan' => $ket, 'tgl_komit' => $komit]);
        keluar(['ok' => true, 'message' => (string)($r['message'] ?? 'Data berhasil disimpan.')]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

    if ($aksi === 'daftar_user') {
        $r = $panggil('daftar_user', []);
        keluar(['data' => (array)($r['data'] ?? [])]);
    }

    // read / preview / laporan: rentang tanggal + user
    $tgl1 = trim((string)($_GET['tgl1'] ?? ''));
    $tgl2 = trim((string)($_GET['tgl2'] ?? ''));
    if (($tgl1 !== '' && !tgl_ok($tgl1)) || ($tgl2 !== '' && !tgl_ok($tgl2))) keluar(['error' => 'Tanggal harus berformat YYYY-MM-DD.'], 422);
    if ($tgl1 !== '' && $tgl2 !== '' && $tgl1 > $tgl2)                         keluar(['error' => 'Tanggal awal tidak boleh sesudah tanggal akhir.'], 422);

    if ($aksi === 'laporan') {
        $pilih = trim((string)($_GET['user'] ?? ''));                      // kosong = semua user
        if ($pilih !== '' && (!ctype_digit($pilih) || (int)$pilih > 32767)) keluar(['error' => 'User tidak valid.'], 422);
    } else {
        $pilih = (string)$uid;                                             // read/preview: milik sendiri
        if ($punyaLaporan && trim((string)($_GET['user'] ?? '')) !== '') {
            $pilih = trim((string)$_GET['user']);
            if (!ctype_digit($pilih) || (int)$pilih > 32767) keluar(['error' => 'User tidak valid.'], 422);
        }
    }
    $isi = ['id_user' => $pilih];
    if ($tgl1 !== '') $isi['tgl1'] = $tgl1;
    if ($tgl2 !== '') $isi['tgl2'] = $tgl2;
    $r = $panggil($aksi, $isi);
    keluar($aksi === 'read' ? ['total' => (int)($r['total'] ?? 0)] : ['rows' => (array)($r['rows'] ?? [])]);
} catch (Throwable $e) {
    error_log('api_telepon: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));   // tanpa isi catatan nasabah
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
