<?php
// HOSTING: terima foto kunjungan, periksa, enkode ulang (menghapus EXIF/lokasi tersembunyi), simpan di luar web root.
// Membalas {"path":"kunjungan/202610/<24 hex>.jpg"}; jalur itu dikirim ke API sebagai kolom 'foto' dan
// ditampilkan lewat foto.php (wajib login). Wajib login dan token CSRF (lib.php).
require __DIR__ . '/lib.php';
require_once __DIR__ . '/api_client.php';     // api_cfg()

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function selesai(int $kode, array $isi): void {
    http_response_code($kode);
    echo json_encode($isi, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') selesai(405, ['error' => 'Metode tidak diizinkan']);

$f = $_FILES['image'] ?? null;
if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
    selesai(400, ['error' => 'Foto tidak diterima. Ukuran maksimal 8 MB.']);
}
if ($f['size'] > 8 * 1024 * 1024) selesai(400, ['error' => 'Ukuran foto maksimal 8 MB.']);

if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
    error_log('upload_foto: ekstensi GD belum aktif (extension=gd di php.ini)');
    selesai(500, ['error' => 'Layanan foto belum tersedia di server.']);
}

// jenis dan ukuran piksel (batas piksel mencegah gambar raksasa menghabiskan memori)
$info = @getimagesize($f['tmp_name']);
$boleh = [IMAGETYPE_JPEG, IMAGETYPE_PNG];
if (defined('IMAGETYPE_WEBP')) $boleh[] = IMAGETYPE_WEBP;
if (!$info || !in_array($info[2], $boleh, true)) {
    selesai(400, ['error' => 'File harus berupa gambar JPG, PNG, atau WebP.']);
}
if ($info[0] * $info[1] > 25000000) selesai(400, ['error' => 'Resolusi gambar terlalu besar.']);

$im = @imagecreatefromstring((string)file_get_contents($f['tmp_name']));
if (!$im) selesai(400, ['error' => 'Gambar tidak bisa dibaca.']);

// sisi terpanjang maksimal 1600 px; latar putih untuk PNG transparan; hasil JPEG baru (tanpa metadata asli)
$w = imagesx($im); $h = imagesy($im);
$r = min(1, 1600 / max($w, $h));
$nw = max(1, (int)round($w * $r)); $nh = max(1, (int)round($h * $r));
$dst = imagecreatetruecolor($nw, $nh);
imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
imagedestroy($im);

// folder penyimpanan: DI LUAR web root (lihat 'foto_dir' di config.api.php)
$basis = rtrim((string)(api_cfg()['foto_dir'] ?? (dirname(__DIR__, 3) . '/storage/monitoring-kredit')), '/\\');
$sub   = 'kunjungan/' . date('Ym');
$dir   = $basis . '/' . $sub;
if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
    error_log('upload_foto: folder tidak bisa dibuat: ' . $dir);
    selesai(500, ['error' => 'Folder penyimpanan foto tidak bisa dibuat di server.']);
}

$nama = bin2hex(random_bytes(12)) . '.jpg';
if (!imagejpeg($dst, $dir . '/' . $nama, 82)) {
    error_log('upload_foto: gagal menulis ' . $dir . '/' . $nama);
    selesai(500, ['error' => 'Foto tidak bisa disimpan di server.']);
}
imagedestroy($dst);

selesai(200, ['path' => $sub . '/' . $nama]);
