<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
require_once __DIR__ . '/akses_lib.php';          // hak akses menu per pengguna
auth_session_start();
if (!defined('PUBLIC_PAGE')) require_login();                                   // semua halaman dan endpoint data wajib login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('NO_CSRF_CHECK')) csrf_check();

// ---------- LIB HOSTING: TIDAK menyentuh database. Semua data diambil dari API backend (api_client.php). ----------
// Upload nominatif tidak ada di sini; itu tetap di server lokal.

// ---------- format dasar ----------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash(string $cat, string $msg): void { $_SESSION['flash'][] = [$cat, $msg]; }
function redirect(string $to) { header('Location: ' . $to); exit; }

/**
 * Kepala halaman. Navigasi dibangun dari menu yang boleh dibuka user (akses_lib.php);
 * halaman yang kuncinya dikenal di AKTIF_KE_KODE ditolak (403) bila user tidak punya menunya.
 */
function page_head(string $title, string $active): void {
    $flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    $kode    = akses_kode_aktif($active);
    $boleh   = defined('PUBLIC_PAGE') || akses_halaman_ok($kode, $active);
    if (!$boleh) http_response_code(403);

    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) .'</title>'
       . '<link rel="stylesheet" href="assets/style.css">'
       . '<link rel="stylesheet" href="assets/mobile.css">'
       . '<link rel="stylesheet" href="assets/header-rapi.css?v=1">'
       . '<script src="assets/menu.js?v=1" defer></script>'
       . '</head><body>'
       . '<header class="top"><a class="brand" href="index.php">Monitoring Kredit</a><nav>'
       . menu_nav_html($kode)
       . '</nav>' . auth_header_html() . '</header><main>';

    if (!$boleh) {
        echo '<div class="flash err">' . h(akses_pesan()) . '</div></main></body></html>';
        exit;
    }
    foreach ($flashes as [$c, $m]) echo '<div class="flash ' . h($c) . '">' . h($m) . '</div>';
}
function page_foot(): void { echo '</main></body></html>'; }