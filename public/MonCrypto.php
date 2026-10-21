<?php
/**
 * MonCrypto: enkripsi payload API dengan AES-256-GCM (terenkripsi + terautentikasi).
 *
 * File yang SAMA dipakai di dua tempat:
 *   - API lokal (CodeIgniter 3): taruh di application/libraries/MonCrypto.php
 *   - Hosting (web)            : require_once biasa
 *
 * Kunci: 32 byte acak, ditulis base64, HANYA ada di server (env MON_ENC_KEY atau config), tidak pernah di browser.
 *   Buat dengan:  openssl rand -base64 32
 * Butuh PHP 7.1+ (openssl AES-GCM).
 *
 * Format token: v1.<iv>.<tag>.<ciphertext>   (base64url, tanpa padding)
 *
 * AAD mengikat isi terenkripsi ke konteks request (arah + x-timestamp + jenis_req), jadi respons lama
 * tidak bisa diputar ulang untuk request yang lain dan isi tidak bisa ditukar antar-endpoint.
 */
class MonCrypto
{
    const VERSI  = 'v1';
    const CIPHER = 'aes-256-gcm';

    private $key;

    /**
     * @param string|array|null $cfg  kunci base64, atau ['key' => base64] (gaya $this->load->library),
     *                                atau null = ambil dari env MON_ENC_KEY
     */
    public function __construct($cfg = null)
    {
        $b64 = null;
        if (is_array($cfg))       $b64 = $cfg['key'] ?? null;
        elseif (is_string($cfg))  $b64 = $cfg;
        if (!$b64)                $b64 = getenv('MON_ENC_KEY');

        $k = $b64 ? base64_decode(trim((string)$b64), true) : false;
        if ($k === false || strlen($k) !== 32) {
            throw new RuntimeException('Kunci enkripsi harus 32 byte dalam base64. Buat dengan: openssl rand -base64 32');
        }
        $this->key = $k;
    }

    /** Konteks yang diikat ke ciphertext. $arah: 'req' atau 'res'. */
    public static function aad($arah, $timestamp, $jenis)
    {
        return implode('|', [self::VERSI, $arah, (string)$timestamp, (string)$jenis]);
    }

    public function encrypt($plain, $aad)
    {
        $iv  = random_bytes(12);
        $tag = '';
        $ct  = openssl_encrypt((string)$plain, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, (string)$aad, 16);
        if ($ct === false) {
            throw new RuntimeException('Enkripsi gagal.');
        }
        return self::VERSI . '.' . self::b64($iv) . '.' . self::b64($tag) . '.' . self::b64($ct);
    }

    /** Mengembalikan teks asli, atau false kalau token rusak / diubah / konteks tidak cocok. */
    public function decrypt($token, $aad)
    {
        $p = explode('.', (string)$token);
        if (count($p) !== 4 || $p[0] !== self::VERSI) return false;

        $iv  = self::unb64($p[1]);
        $tag = self::unb64($p[2]);
        $ct  = self::unb64($p[3]);
        if ($iv === false || strlen($iv) !== 12 || $tag === false || strlen($tag) !== 16 || $ct === false) return false;

        $plain = openssl_decrypt($ct, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv, $tag, (string)$aad);
        return $plain === false ? false : $plain;
    }

    private static function b64($s)
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function unb64($s)
    {
        return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
