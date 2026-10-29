<?php
/**
 * Autentikasi aplikasi monitoring-kredit.
 * Akun diperiksa di API lokal (endpoint Login, terenkripsi); di sini hanya sesi, pembatasan percobaan, dan CSRF.
 */

// Staff (kolom "staff" dari API Login) yang boleh masuk. Kosong = semua akun yang valid.
// Isi setelah kamu tahu nilai staff_type-nya, mis. ['admin', 'pimpinan', 'kabag kredit'].
const AUTH_STAFF_BOLEH = [];

const AUTH_IDLE_DETIK  = 1800;    // keluar otomatis bila 30 menit tidak aktif
const AUTH_MAKS_DETIK  = 28800;   // dan paling lama 8 jam sejak login
const AUTH_COBA_USER   = 5;       // salah password 5x untuk satu username -> kunci sementara
const AUTH_COBA_IP     = 50;      // batas lebih longgar per IP (di balik tunnel, IP bisa sama untuk semua)
const AUTH_JENDELA     = 900;     // 15 menit
const AUTH_PERCAYA_PROXY = false; // true HANYA bila server benar-benar di belakang Cloudflare/proxy tepercaya

function auth_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

function auth_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
    header('Cache-Control: no-store');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_name('MONKREDIT');
    if (PHP_VERSION_ID >= 70300) {
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => auth_https(), 'httponly' => true, 'samesite' => 'Lax']);
    } else {
        session_set_cookie_params(0, '/; samesite=Lax', '', auth_https(), true);
    }
    session_start();
}

function auth_ip(): string
{
    if (AUTH_PERCAYA_PROXY && !empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string)$_SERVER['HTTP_CF_CONNECTING_IP'];
    return (string)($_SERVER['REMOTE_ADDR'] ?? '');
}

// ---------- sesi pengguna ----------
function auth_destroy(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], (bool)$p['secure'], (bool)$p['httponly']);
        }
        session_destroy();
    }
}

/** Pengguna yang sedang login, atau null (sesi habis/tidak cocok dibersihkan di sini). */
function auth_user(): ?array
{
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u)) return null;
    $now = time();
    if ($now - (int)($_SESSION['last'] ?? 0) > AUTH_IDLE_DETIK || $now - (int)($_SESSION['login_at'] ?? 0) > AUTH_MAKS_DETIK
        || !hash_equals((string)($_SESSION['ua'] ?? ''), hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')))) {
        auth_destroy();
        return null;
    }
    $_SESSION['last'] = $now;
    return $u;
}

/** Dipanggil setelah API membenarkan username/password. */
function auth_login(array $u): void
{
    session_regenerate_id(true);               // cegah session fixation
    $_SESSION = [];
    $_SESSION['user'] = [
        'id'       => (string)($u['id_user'] ?? ''),
        'username' => (string)($u['username'] ?? ''),
        'nama'     => (string)($u['nama'] ?? ''),
        'staff'    => (string)($u['staff'] ?? ''),
        'kode_ao'  => (string)($u['kode_ao'] ?? ''),   // dipakai Input kunjungan untuk daftar debitur milik AO
        'menu'     => auth_menu_bersih($u['menu'] ?? []),   // hak akses menu dari API Login (dibaca akses_lib.php)
    ];
    $_SESSION['login_at'] = $_SESSION['last'] = time();
    $_SESSION['ua']   = hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

/** Ambil hanya field yang dipakai dari daftar menu yang dikirim API. */
function auth_menu_bersih($rows): array
{
    $out = [];
    if (!is_array($rows)) return $out;
    foreach ($rows as $m) {
        if (!is_array($m)) continue;
        $out[] = [
            'id'        => (int)($m['id'] ?? 0),
            'parent_id' => (int)($m['parent_id'] ?? 0),
            'kode'      => (string)($m['kode'] ?? ''),
            'label'     => (string)($m['label'] ?? ''),
            'url'       => (string)($m['url'] ?? ''),
        ];
    }
    return $out;
}

function auth_staff_boleh(string $staff): bool
{
    if (!AUTH_STAFF_BOLEH) return true;
    return in_array(strtolower(trim($staff)), array_map('strtolower', AUTH_STAFF_BOLEH), true);
}

function auth_minta_json(): bool
{
    return ($_GET['data'] ?? '') === '1'
        || strpos(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')), 'api') === 0   // api.php, api_kunjungan.php, ...
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

/** Alamat tujuan setelah login: hanya jalur lokal, tidak boleh ke situs lain. */
function auth_aman_next(string $next): string
{
    if ($next === '' || strpos($next, '//') !== false || strpos($next, '..') !== false || strpos($next, '\\') !== false
        || !preg_match('~^/?[A-Za-z0-9_\-./]*(\?[A-Za-z0-9_\-=&%.:+]*)?$~', $next)
        || preg_match('~^/?login\.php|^/?logout\.php~', $next)) {
        return 'index.php';
    }
    return $next;
}

/** Wajib login. Halaman biasa -> arahkan ke login; endpoint data (JSON) -> 401. */
function require_login(): void
{
    if (auth_user() !== null) return;
    if (auth_minta_json()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'Sesi berakhir. Silakan masuk lagi.']);
        exit;
    }
    $to = (string)($_SERVER['REQUEST_URI'] ?? '');
    header('Location: login.php' . ($to !== '' ? '?next=' . rawurlencode($to) : ''));
    exit;
}

// ---------- CSRF ----------
function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf'];
}

/** Semua POST wajib membawa token (field _csrf atau header X-CSRF-Token). */
function csrf_check(): void
{
    $t = (string)($_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    if ($t !== '' && hash_equals(csrf_token(), $t)) return;
    http_response_code(419);
    if (auth_minta_json()) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.']);
    } else {
        echo 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.';
    }
    exit;
}

// ---------- pembatasan percobaan login (berkas sementara, tanpa database) ----------
function auth_throttle_file(string $key): string
{
    $dir = sys_get_temp_dir() . '/monkredit_throttle';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    return $dir . '/' . hash('sha256', $key) . '.json';
}

function auth_throttle_blocked(string $key, int $maks): bool
{
    $f = auth_throttle_file($key);
    $d = is_file($f) ? json_decode((string)@file_get_contents($f), true) : [];
    $d = array_filter(is_array($d) ? $d : [], function ($t) { return $t > time() - AUTH_JENDELA; });
    return count($d) >= $maks;
}

function auth_throttle_hit(string $key): void
{
    $f = auth_throttle_file($key);
    $h = @fopen($f, 'c+');
    if (!$h) return;
    flock($h, LOCK_EX);
    $d = json_decode((string)stream_get_contents($h), true);
    $d = array_values(array_filter(is_array($d) ? $d : [], function ($t) { return $t > time() - AUTH_JENDELA; }));
    $d[] = time();
    ftruncate($h, 0); rewind($h); fwrite($h, json_encode($d));
    flock($h, LOCK_UN); fclose($h);
}

function auth_throttle_clear(string $key): void
{
    @unlink(auth_throttle_file($key));
}

/** Potongan HTML untuk bar atas: nama pengguna + tombol Keluar. */
function auth_header_html(): string
{
    $u = auth_user();
    if (!$u) return '';
    $e = function ($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); };
    return '<form method="post" action="logout.php" style="margin-left:auto;display:flex;gap:10px;align-items:center">'
         . '<span class="muted">' . $e($u['nama'] !== '' ? $u['nama'] : $u['username']) . '</span>'
         . '<input type="hidden" name="_csrf" value="' . $e(csrf_token()) . '"><button class="btn">Keluar</button></form>';
}