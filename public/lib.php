<?php
declare(strict_types=1);
require __DIR__ . '/auth.php';
auth_session_start();
if (!defined('PUBLIC_PAGE')) require_login();                                   // semua halaman dan endpoint data wajib login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !defined('NO_CSRF_CHECK')) csrf_check();

// ---------- LIB HOSTING: TIDAK menyentuh database. Semua data diambil dari API backend (api_client.php). ----------
// Upload nominatif tidak ada di sini; itu tetap di server lokal.

// ---------- format dasar ----------
function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash(string $cat, string $msg): void { $_SESSION['flash'][] = [$cat, $msg]; }
function redirect(string $to) { header('Location: ' . $to); exit; }

function page_head(string $title, string $active): void {
    $flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) . '</title><link rel="stylesheet" href="style.css"></head><body>'
       . '<header class="top"><a class="brand" href="index.php">Monitoring Kredit</a><nav>'
       . '<a href="index.php" class="' . ($active === 'dash' ? 'on' : '') . '">Dashboard</a>'
       . '<a href="pergerakan.php" class="' . ($active === 'gerak' ? 'on' : '') . '">Pergerakan</a>'
       . '<a href="prioritas.php" class="' . ($active === 'prioritas' ? 'on' : '') . '">Prioritas tagih</a>'
       . '<a href="nasabah.php" class="' . ($active === 'debitur' ? 'on' : '') . '">Debitur</a>'
       . '<a href="upload.php" class="' . ($active === 'upload' ? 'on' : '') . '">Upload nominatif</a></nav></header><main>';
    foreach ($flashes as [$c, $m]) echo '<div class="flash ' . h($c) . '">' . h($m) . '</div>';
}
function page_foot(): void { echo '</main></body></html>'; }