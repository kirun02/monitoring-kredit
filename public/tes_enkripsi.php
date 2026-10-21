<?php
// Uji mandiri lapisan enkripsi (tanpa jaringan/database):  php tes_enkripsi.php
require __DIR__ . '/MonCrypto.php';

$ok = 0; $gagal = 0;
function cek($nama, $kondisi) { global $ok, $gagal; echo ($kondisi ? '[ OK ] ' : '[GAGAL] ') . $nama . PHP_EOL; $kondisi ? $ok++ : $gagal++; }

const FLAG = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
$kunci  = base64_encode(random_bytes(32));
$klien  = new MonCrypto($kunci);          // sisi hosting
$server = new MonCrypto(['key' => $kunci]); // sisi API (gaya CodeIgniter)

// --- alur normal ---------------------------------------------------------------
$ts    = date(DATE_ISO8601);
$jenis = 'login';
$isi   = ['username' => 'budi', 'password' => 'rahasia/ü é "kutip"', 'params' => ['tgl' => '2026-10-19']];

$body = json_encode(['jenis_req' => $jenis, 'enc' => $klien->encrypt(json_encode($isi, FLAG), MonCrypto::aad('req', $ts, $jenis))], FLAG);
cek('body luar tidak memuat password/nama dalam teks biasa', strpos($body, 'rahasia') === false && strpos($body, 'budi') === false);

// Api_core::validateToken menghitung hash dari json_decode -> json_encode; harus identik dengan yang ditandatangani klien
$diterima = json_decode($body, true);
cek('body luar identik setelah decode+encode ulang (hash tanda tangan cocok)', json_encode($diterima, FLAG) === $body);

$buka = json_decode($server->decrypt($diterima['enc'], MonCrypto::aad('req', $ts, $diterima['jenis_req'])), true);
cek('server dapat membuka request', $buka === $isi);

$resp  = ['status' => 'success', 'data' => [['nama' => 'Ä Ö', 'alamat' => 'Jl. Mawar/12']]];
$enc   = $server->encrypt(json_encode($resp, FLAG), MonCrypto::aad('res', $ts, $jenis));
cek('respons terenkripsi tidak memuat data asli', strpos($enc, 'Mawar') === false);
cek('klien dapat membuka respons', json_decode($klien->decrypt($enc, MonCrypto::aad('res', $ts, $jenis)), true) === $resp);

// --- serangan -------------------------------------------------------------------
cek('respons lama diputar ulang untuk request lain (timestamp beda) ditolak',
    $klien->decrypt($enc, MonCrypto::aad('res', date(DATE_ISO8601, time() + 60), $jenis)) === false);
cek('respons endpoint lain ditukar (jenis_req beda) ditolak',
    $klien->decrypt($enc, MonCrypto::aad('res', $ts, 'daftar_user')) === false);
cek('respons dipakai sebagai request (arah beda) ditolak',
    $klien->decrypt($enc, MonCrypto::aad('req', $ts, $jenis)) === false);

$p = explode('.', $enc);
$rusak = $p[0] . '.' . $p[1] . '.' . $p[2] . '.' . substr($p[3], 0, -2) . (substr($p[3], -2) === 'AA' ? 'BB' : 'AA');
cek('ciphertext diubah 1 byte ditolak', $klien->decrypt($rusak, MonCrypto::aad('res', $ts, $jenis)) === false);
cek('token terpotong ditolak', $klien->decrypt(substr($enc, 0, -10), MonCrypto::aad('res', $ts, $jenis)) === false);
cek('sampah acak ditolak', $klien->decrypt('abc', 'x') === false && $klien->decrypt('', 'x') === false);

$lain = new MonCrypto(base64_encode(random_bytes(32)));
cek('kunci yang salah ditolak', $lain->decrypt($enc, MonCrypto::aad('res', $ts, $jenis)) === false);
cek('dua enkripsi dari teks yang sama berbeda (IV acak)', $klien->encrypt('x', 'a') !== $klien->encrypt('x', 'a'));

// --- kunci tidak valid -----------------------------------------------------------
foreach (['', 'bukan-base64!!', base64_encode('pendek')] as $k) {
    try { new MonCrypto($k); cek('kunci tidak valid ditolak: ' . var_export($k, true), false); }
    catch (RuntimeException $e) { cek('kunci tidak valid ditolak: ' . var_export($k, true), true); }
}

echo PHP_EOL . "$ok lolos, $gagal gagal" . PHP_EOL;
exit($gagal ? 1 : 0);
