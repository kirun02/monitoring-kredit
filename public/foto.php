<?php
// Menyajikan foto kunjungan (disimpan di luar folder web). Wajib login; AO hanya boleh melihat fotonya sendiri.
require __DIR__ . '/lib.php';
require_once __DIR__ . 'api/api_client.php';
require_once __DIR__ . '/foto_lib.php';

$u    = auth_user() ?? [];
$path = null;
try {
    $path = foto_path((string)($_GET['f'] ?? ''), (int)($u['id'] ?? 0), (string)($u['staff'] ?? ''));
} catch (Throwable $e) {
    error_log('foto.php: ' . $e->getMessage());
}
if ($path === null) {
    http_response_code(404);
    exit;
}
header('Content-Type: image/jpeg');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
header('Content-Length: ' . filesize($path));
readfile($path);