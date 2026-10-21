<?php
/**
 * Membuat SQL untuk menambah / mengganti password akun app_user. Dijalankan dari terminal (CLI), TIDAK memakai database:
 * hasilnya berupa perintah SQL yang kamu tempel sendiri di pgAdmin. Password tidak pernah ditulis ke file atau riwayat.
 *
 *   php buat_user.php baru   budi "Budi Santoso" admin
 *   php buat_user.php baru   edy  "Edy"          ao 05          (role ao wajib kode AO)
 *   php buat_user.php ganti  budi                               (ganti password akun yang sudah ada)
 *
 * Role: admin | pimpinan | ao
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

function baca_password($label)
{
    $windows = DIRECTORY_SEPARATOR === '\\';
    echo $label;
    if (!$windows) system('stty -echo');
    $p = rtrim((string)fgets(STDIN), "\r\n");
    if (!$windows) { system('stty echo'); echo PHP_EOL; }
    else echo "(catatan: di Windows ketikan terlihat di layar)" . PHP_EOL;
    return $p;
}
function sql($s) { return "'" . str_replace("'", "''", $s) . "'"; }

$mode = $argv[1] ?? '';
if ($mode === 'baru' && count($argv) >= 5) {
    list(, , $username, $nama, $role) = $argv;
    $kode = $argv[5] ?? null;
} elseif ($mode === 'ganti' && count($argv) >= 3) {
    $username = $argv[2];
} else {
    fwrite(STDERR, "Pemakaian:\n  php buat_user.php baru <username> \"<nama>\" <admin|pimpinan|ao> [kode_ao]\n  php buat_user.php ganti <username>\n");
    exit(1);
}
if ($mode === 'baru') {
    if (!in_array($role, ['admin', 'pimpinan', 'ao'], true)) { fwrite(STDERR, "Role harus admin, pimpinan, atau ao.\n"); exit(1); }
    if ($role === 'ao' && !$kode) { fwrite(STDERR, "Role ao wajib diisi kode AO (argumen ke-5).\n"); exit(1); }
}

$p1 = baca_password('Password baru : ');
$p2 = baca_password('Ulangi        : ');
if ($p1 !== $p2)            { fwrite(STDERR, "Password tidak sama.\n"); exit(1); }
if (strlen($p1) < 10)       { fwrite(STDERR, "Password minimal 10 karakter.\n"); exit(1); }
if (strlen($p1) > 200)      { fwrite(STDERR, "Password terlalu panjang.\n"); exit(1); }

$hash = password_hash($p1, PASSWORD_DEFAULT);
echo PHP_EOL . "-- Tempel di pgAdmin:" . PHP_EOL;
if ($mode === 'baru') {
    echo "INSERT INTO app_user (username, nama, password_hash, role, kode_ao) VALUES ("
       . sql(strtolower($username)) . ', ' . sql($nama) . ', ' . sql($hash) . ', ' . sql($role) . ', ' . ($kode ? sql($kode) : 'NULL') . ");" . PHP_EOL;
} else {
    echo "UPDATE app_user SET password_hash = " . sql($hash) . ", gagal_login = 0, terkunci_sampai = NULL WHERE lower(username) = lower(" . sql($username) . ");" . PHP_EOL;
}
