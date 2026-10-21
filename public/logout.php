<?php
define('PUBLIC_PAGE', true);
define('NO_CSRF_CHECK', true);   // keluar harus selalu berhasil, meski sesi sudah habis
require __DIR__ . '/lib.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    auth_destroy();
}
redirect('login.php');
