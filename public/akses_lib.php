<?php
declare(strict_types=1);
/**
 * Hak akses menu per pengguna (sisi hosting). Dimuat dari lib.php.
 *
 * Daftar menu yang boleh dibuka dikirim backend saat login dan disimpan di $_SESSION['user']['menu'].
 * Perubahan hak akses berlaku saat pengguna yang bersangkutan masuk ulang.
 * Menu yang tidak ada di daftar tidak tampil di navigasi dan halamannya ditolak (403).
 */

// kunci $active di page_head() -> kode menu di m_menu
const AKTIF_KE_KODE = [
    'dash'      => 'web_dashboard',
    'gerak'     => 'web_pergerakan',
    'prioritas' => 'web_prioritas',
    'debitur'   => 'web_debitur',
    'kunjungan' => 'web_kunjungan',
    'lapkun'    => 'web_lap_kunjungan',
    'absensi'   => 'web_absensi',
    'upload'    => 'web_upload',
    'karyawan'  => 'web_karyawan',
    'pengguna'  => 'web_pengguna',
    'akses'     => 'web_akses',
];

function akses_kode_aktif(string $active): string
{
    return AKTIF_KE_KODE[$active] ?? $active;
}

/** Daftar menu di sesi; null bila sesi dibuat sebelum fitur hak akses ada. */
function akses_menu_sesi(): ?array
{
    $m = $_SESSION['user']['menu'] ?? null;
    return is_array($m) ? $m : null;
}

function boleh(string $kode): bool
{
    foreach (akses_menu_sesi() ?? [] as $x) {
        if (($x['kode'] ?? '') === $kode) return true;
    }
    return false;
}

/** Halaman dengan kunci dikenal (atau berawalan web_) wajib punya akses; selain itu dibiarkan. */
function akses_halaman_ok(string $kode, string $active): bool
{
    $dicek = isset(AKTIF_KE_KODE[$active]) || strpos($active, 'web_') === 0;
    return !$dicek || boleh($kode);
}

function akses_pesan(): string
{
    return akses_menu_sesi() === null
        ? 'Hak akses belum dimuat untuk sesi ini. Keluar, lalu masuk kembali.'
        : 'Kamu tidak punya akses ke halaman ini. Hubungi admin bila perlu.';
}

/** Untuk endpoint JSON (api/api_*.php): hentikan dengan 403 bila tidak punya akses menu. */
function akses_atau_403(string $kode): void
{
    if (boleh($kode)) return;
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(['error' => akses_pesan()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** URL menu dari database hanya boleh berupa halaman .php relatif. */
function akses_url_aman(string $u): string
{
    return preg_match('~^[A-Za-z0-9_\-/]+\.php(\?[A-Za-z0-9_=&\-]*)?$~', $u) ? $u : '';
}

/** Menu di sesi disusun jadi pohon: [{..menu.., children:[...]}]. */
function akses_pohon(): array
{
    $anak = [];
    $top  = [];
    foreach (akses_menu_sesi() ?? [] as $x) {
        $pid = (int)($x['parent_id'] ?? 0);
        if ($pid > 0) $anak[$pid][] = $x; else $top[] = $x;
    }
    foreach ($top as &$t) {
        $t['children'] = $anak[(int)($t['id'] ?? 0)] ?? [];
    }
    unset($t);
    return $top;
}

/** Isi <nav> dari menu yang boleh dibuka. Induk dengan anak menautkan ke anak pertama yang boleh dibuka. */
function menu_nav_html(string $kodeAktif): string
{
    $out = '';
    foreach (akses_pohon() as $t) {
        $anak = $t['children'];
        if ($anak) {
            $href = akses_url_aman((string)($anak[0]['url'] ?? ''));
            $on   = $kodeAktif === ($t['kode'] ?? '') || in_array($kodeAktif, array_column($anak, 'kode'), true);
        } else {
            $href = akses_url_aman((string)($t['url'] ?? ''));
            $on   = $kodeAktif === ($t['kode'] ?? '');
        }
        if ($href === '') continue;
        $out .= '<a href="' . h($href) . '" class="' . ($on ? 'on' : '') . '">' . h((string)($t['label'] ?? '')) . '</a>';
    }
    return $out;
}

/** Tab antar-menu satu induk (mis. Data Karyawan | Pengguna Aplikasi | Hak Akses); hanya yang boleh dibuka. */
function tabs_html(string $kode): void
{
    foreach (akses_pohon() as $t) {
        $anak = $t['children'];
        if (!in_array($kode, array_column($anak, 'kode'), true)) continue;
        if (count($anak) < 2) return;
        echo '<nav class="tabs" aria-label="' . h((string)($t['label'] ?? '')) . '">';
        foreach ($anak as $c) {
            $href = akses_url_aman((string)($c['url'] ?? ''));
            if ($href === '') continue;
            $on = ($c['kode'] ?? '') === $kode;
            echo '<a href="' . h($href) . '"' . ($on ? ' class="on" aria-current="page"' : '') . '>' . h((string)($c['label'] ?? '')) . '</a>';
        }
        echo '</nav>';
        return;
    }
}
