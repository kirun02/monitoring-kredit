<?php
/**
 * Klien API lokal untuk HOSTING (server-ke-server):
 *   1) ambil accessToken (tanda tangan RS256 dari private.pem)
 *   2) panggil endpoint dengan tanda tangan HMAC-SHA512 (skema Api_core::validateToken)
 *   3) isi request dan respons DIENKRIPSI (AES-256-GCM, MonCrypto)
 *
 * Pemakaian:
 *   $data = api_call('login', ['username' => $u, 'password' => $p]);          // endpoint Login
 *   $data = api_call('dashboard', ['params' => ['banding' => 'kemarin']]);    // endpoint monitoring
 */
require_once __DIR__ . '/../MonCrypto.php';

const API_JSON = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;   // HARUS sama dengan yang dipakai Api_core

function api_cfg()
{
    static $c = null;
    if ($c === null) $c = require __DIR__ . '/../config.api.php';
    return $c;
}

function api_http($path, array $headers, $body, $timeout = null)
{
    $c  = api_cfg();
    $ch = curl_init(rtrim($c['base_url'], '/') . $path);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_TIMEOUT        => $timeout ?: ($c['timeout'] ?? 30),
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $res  = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($res === false) throw new RuntimeException('API tidak dapat dihubungi: ' . $err);
    return [$code, json_decode((string)$res, true)];
}

/** accessToken (Bearer HS512), disimpan di sesi sampai hampir kedaluwarsa. */
function api_token($refresh = false)
{
    if (!$refresh && !empty($_SESSION['api_tok']) && $_SESSION['api_tok']['exp'] > time() + 20) {
        return $_SESSION['api_tok']['t'];
    }
    $c   = api_cfg();
    $ts  = date(DATE_ISO8601);
    $pem = openssl_pkey_get_private('file://' . $c['private_key']);
    if (!$pem) throw new RuntimeException('private key tidak dapat dibaca');
    openssl_sign("{$c['api_key']}|{$c['secret_key']}|{$ts}", $sig, $pem, OPENSSL_ALGO_SHA256);

    list($code, $j) = api_http($c['path_token'], [
        'Content-Type: application/json',
        'x-timestamp: ' . $ts,
        'x-api-key: ' . $c['api_key'],
        'x-secret-key: ' . $c['secret_key'],
        'x-signature: ' . bin2hex($sig),
    ], json_encode(['grantType' => 'client_credentials']));

    if ($code !== 200 || empty($j['accessToken'])) {
        throw new RuntimeException('Gagal mengambil token API (HTTP ' . $code . ')');
    }
    $_SESSION['api_tok'] = ['t' => $j['accessToken'], 'exp' => time() + (int)($j['expiresIn'] ?? 300)];
    return $j['accessToken'];
}

/** Pilih endpoint menurut jenis_req. Login ke path_data; data monitoring ke path_monitoring. */
function api_path($jenis)
{
    $c = api_cfg();
    if (in_array($jenis, ['dashboard', 'debitur', 'pergerakan', 'prioritas'], true)) {
        if (empty($c['path_monitoring'])) {
            throw new RuntimeException('path_monitoring belum diatur di config.api.php');
        }
        return $c['path_monitoring'];
    }

    if (strpos($jenis, 'upload_') === 0) {
        if (empty($c['path_upload'])) throw new RuntimeException('path_upload belum diatur di config.api.php');
        return $c['path_upload'];
    }

    return $c['path_data'];
}

/**
 * Panggil API. $isi = isi sebenarnya (username/password atau ['params' => [...]]); selalu dikirim terenkripsi.
 * Mengembalikan isi respons yang sudah didekripsi (array dengan 'status' dan 'data'/'message').
 */
/*function api_call($jenis, array $isi = [], $path = null)
{
    $c      = api_cfg();
    $path   = $path ?: api_path($jenis);        // tadinya: $path ?: $c['path_data']
    $crypto = new MonCrypto($c['enc_key']);

    for ($try = 0; $try < 2; $try++) {
        $bearer = api_token($try > 0);
        $ts     = date(DATE_ISO8601);

        // body luar: hanya jenis_req + blob terenkripsi (inilah yang ditandatangani dan masuk ke log API)
        $body = json_encode([
            'jenis_req' => $jenis,
            'enc'       => $crypto->encrypt(json_encode($isi, API_JSON), MonCrypto::aad('req', $ts, $jenis)),
        ], API_JSON);

        $signing = 'POST|' . $path . '|' . $bearer . '|' . strtolower(hash('sha256', $body)) . '|' . $ts;
        $sig     = base64_encode(hash_hmac('SHA512', $signing, $c['hmac_key'], true));

        list($code, $j) = api_http($path, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $bearer,
            'x-timestamp: ' . $ts,
            'x-api-key: ' . $c['api_key'],
            'x-secret-key: ' . $c['secret_key'],
            'x-signature: ' . $sig,
        ], $body);

        if ($code === 401 && $try === 0) continue;                  // token habis: ambil baru, ulangi sekali

        if ($code !== 200)        throw new RuntimeException('API membalas HTTP ' . $code);   // jangan ikutkan isi balasan (bisa memuat detail tanda tangan)
        if (empty($j['enc']))     throw new RuntimeException('Respons API tidak terenkripsi, ditolak.');   // tidak ada jalur mundur ke teks biasa

        $plain = $crypto->decrypt($j['enc'], MonCrypto::aad('res', $ts, $jenis));
        if ($plain === false)     throw new RuntimeException('Respons API tidak dapat diverifikasi.');
        $out = json_decode($plain, true);
        if (!is_array($out))      throw new RuntimeException('Isi respons API tidak valid.');
        return $out;
    }
    throw new RuntimeException('API menolak token.');
}*/

/**
 * Panggil API ($timeout = detik, opsional; kosong = 'timeout' di config.api.php). $isi = isi sebenarnya (username/password atau ['params' => [...]]); selalu dikirim terenkripsi.
 * Mengembalikan isi respons yang sudah didekripsi (array dengan 'status' dan 'data'/'message').
 */
function api_call($jenis, array $isi = [], $path = null, $timeout = null)
{
    $c      = api_cfg();
    $path   = $path ?: api_path($jenis);        // tadinya: $path ?: $c['path_data']
    $crypto = new MonCrypto($c['enc_key']);

    for ($try = 0; $try < 2; $try++) {
        $bearer = api_token($try > 0);
        $ts     = date(DATE_ISO8601);

        // body luar: hanya jenis_req + blob terenkripsi (inilah yang ditandatangani dan masuk ke log API)
        $body = json_encode([
            'jenis_req' => $jenis,
            'enc'       => $crypto->encrypt(json_encode($isi, API_JSON), MonCrypto::aad('req', $ts, $jenis)),
        ], API_JSON);

        $signing = 'POST|' . $path . '|' . $bearer . '|' . strtolower(hash('sha256', $body)) . '|' . $ts;
        $sig     = base64_encode(hash_hmac('SHA512', $signing, $c['hmac_key'], true));

        list($code, $j) = api_http($path, [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $bearer,
            'x-timestamp: ' . $ts,
            'x-api-key: ' . $c['api_key'],
            'x-secret-key: ' . $c['secret_key'],
            'x-signature: ' . $sig,
        ], $body, $timeout);

        if ($code === 401 && $try === 0) continue;                  // token habis: ambil baru, ulangi sekali

        if ($code !== 200)        throw new RuntimeException('API membalas HTTP ' . $code);   // jangan ikutkan isi balasan (bisa memuat detail tanda tangan)
        if (empty($j['enc']))     throw new RuntimeException('Respons API tidak terenkripsi, ditolak.');   // tidak ada jalur mundur ke teks biasa

        $plain = $crypto->decrypt($j['enc'], MonCrypto::aad('res', $ts, $jenis));
        if ($plain === false)     throw new RuntimeException('Respons API tidak dapat diverifikasi.');
        $out = json_decode($plain, true);
        if (!is_array($out))      throw new RuntimeException('Isi respons API tidak valid.');
        return $out;
    }
    throw new RuntimeException('API menolak token.');
}


/**
 * Untuk halaman JSON di hosting (mis. api.php): teruskan filter dari $_GET ke API, kirim hasilnya ke browser.
 * $keys = nama parameter GET yang boleh diteruskan.
 */
function api_proxy($jenis, array $keys)
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $p = [];
    foreach ($keys as $k) {
        if (isset($_GET[$k]) && is_string($_GET[$k]) && $_GET[$k] !== '') $p[$k] = $_GET[$k];
    }
    try {
        // endpoint data monitoring ('path_monitoring' di config.api.php); login tetap memakai 'path_data'
        //$r = api_call($jenis, ['params' => $p], api_cfg()['path_monitoring']);
        $r = api_call($jenis, ['params' => $p]);
        if (($r['status'] ?? '') !== 'success') throw new RuntimeException('API: ' . ($r['message'] ?? 'gagal'));
        echo json_encode($r['data'] ?? [], JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(502);
        error_log('api_proxy: ' . $e->getMessage());
        echo json_encode(['error' => 'Layanan data tidak tersedia']);
    }
    exit;
}