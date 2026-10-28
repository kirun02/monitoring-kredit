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

/** Navigasi dari hak akses menu user (dikirim API saat login, disimpan di $_SESSION['menu']). */
function nav_html(string $active): string {
    $top = []; $kids = [];
    foreach (auth_menu() as $m) {
        if ($m['parent_id'] === 0) $top[] = $m; else $kids[$m['parent_id']][] = $m;
    }
    $cur = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));

    // hanya jalur lokal yang aman; url lain diabaikan
    $url = function (array $m): string {
        $u = $m['url'];
        return ($u !== '' && preg_match('~^[A-Za-z0-9_\-./?=&]+$~', $u) && strpos($u, '..') === false && strpos($u, '//') === false) ? $u : '';
    };
    $isCur = function (string $u) use ($cur): bool { return basename((string)parse_url($u, PHP_URL_PATH)) === $cur; };
    $link  = function (array $m) use ($url, $isCur): string {
        $u = $url($m);
        return $u === '' ? '' : '<a href="' . h($u) . '" class="' . ($isCur($u) ? 'on' : '') . '">' . h($m['label']) . '</a>';
    };

    $o = '';
    foreach ($top as $p) {
        $ch = $kids[$p['id']] ?? [];
        if (!$ch) { $o .= $link($p); continue; }
        $in = ''; $open = false;
        foreach ($ch as $c) {
            $in .= $link($c);
            if ($url($c) !== '' && $isCur($url($c))) $open = true;
        }
        if ($in !== '') {
            $o .= '<details class="navgrp"' . ($open ? ' open' : '') . '><summary>' . h($p['label']) . '</summary><div>' . $in . '</div></details>';
        }
    }
    // pengaturan hak akses: khusus admin, tidak bergantung pada tabel menu
    /*if (strtolower((string)(auth_user()['staff'] ?? '')) === 'admin') {
        $o .= '<a href="akses.php" class="' . ($active === 'akses' ? 'on' : '') . '">Hak akses</a>';
    }*/
    return $o;
}

function page_head(string $title, string $active): void {
    $flashes = $_SESSION['flash'] ?? []; unset($_SESSION['flash']);
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . h($title) .'</title>'
       . '<link rel="stylesheet" href="assets/style.css">'
       . '<link rel="stylesheet" href="assets/mobile.css">'
       . '<style>.navgrp{display:inline-block;position:relative}.navgrp>summary{cursor:pointer;list-style:none;padding:8px 12px}'
       . '.navgrp>summary::-webkit-details-marker{display:none}.navgrp>div{display:flex;flex-direction:column;padding-left:8px}</style>'
       . '</head><body>'
       . '<header class="top"><a class="brand" href="index.php">Monitoring Kredit</a><nav>'
       . nav_html($active)
       . '<a href="logout.php" >keluar</a>'
       . '</nav></header><main>';
    foreach ($flashes as [$c, $m]) echo '<div class="flash ' . h($c) . '">' . h($m) . '</div>';
}
function page_foot(): void { echo '</main></body></html>'; }