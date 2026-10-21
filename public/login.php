<?php
define('PUBLIC_PAGE', true);               // halaman ini boleh dibuka tanpa login
require __DIR__ . '/lib.php';
require_once __DIR__ . '/api_client.php';  // klien API lokal (terenkripsi)

if (auth_user() !== null) {
    redirect(auth_aman_next((string)($_GET['next'] ?? '')));
}

$err  = '';
$user = '';
$next = auth_aman_next((string)($_POST['next'] ?? ($_GET['next'] ?? '')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string)($_POST['username'] ?? ''));
    $pass = (string)($_POST['password'] ?? '');

    $kUser = 'u|' . strtolower($user);
    $kIp   = 'ip|' . auth_ip();

    if ($user === '' || $pass === '' || strlen($user) > 100 || strlen($pass) > 200) {
        $err = 'Username dan password wajib diisi.';
    //} elseif (auth_throttle_blocked($kUser, AUTH_COBA_USER) || auth_throttle_blocked($kIp, AUTH_COBA_IP)) {
      //  $err = 'Terlalu banyak percobaan masuk. Coba lagi beberapa menit lagi.';
    } else {
        try {
            $r = api_call('login', ['username' => $user, 'password' => $pass]);

            $u = (($r['status'] ?? '') === 'success') ? ($r['data'][0] ?? null) : null;

            if (!is_array($u)) {
                auth_throttle_hit($kUser);
                auth_throttle_hit($kIp);
                usleep(400000);                                  // memperlambat tebak-tebakan
                $err = 'Username atau password salah.';          // sengaja tidak menyebut mana yang salah
            } elseif (!auth_staff_boleh((string)($u['staff'] ?? ''))) {
                $err = 'Akun ini tidak memiliki akses ke aplikasi monitoring.';
            } else {
                auth_throttle_clear($kUser);
                auth_login($u);
                redirect($next);
            }
        } catch (Throwable $e) {
            error_log('login: ' . $e->getMessage());
            $err = 'Layanan login sedang tidak tersedia. Coba lagi nanti.';
        }
    }
}
?>
<!doctype html>
<html lang="id">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Masuk · Monitoring Kredit</title>
    <link rel="stylesheet" href="style.css">
    <link rel="stylesheet" href="mobile.css">
</head>

<body>
    <main style="max-width:420px;margin:10vh auto 0;padding:0 16px">
        <form class="card" method="post" autocomplete="on" style="display:flex;flex-direction:column;gap:16px">
            <div>
                <h1 style="margin:0">Monitoring Kredit</h1>
                <div class="sub">Masuk dengan akun Anda</div>
            </div>
            <?php if ($err !== ''): ?><div class="flash err"><?= h($err) ?></div><?php endif; ?>
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="next" value="<?= h($next) ?>">
            <label class="f">Username<input name="username" value="<?= h($user) ?>" autocomplete="username" maxlength="100" required autofocus></label>
            <label class="f">Password<input type="password" name="password" autocomplete="current-password" maxlength="200" required></label>
            <button class="btn primary" style="justify-content:center;height:48px">Masuk</button>
        </form>
    </main>
</body>

</html>