<?php
/**
 * RIPLAY Personal Kredit Arthamas -> PDF.  Letak: folder api/ (sejajar dengan export.php).
 *
 *   POST api/export_riplay.php?produk=kredit           -> PDF dibuka di tab browser (Preview)
 *   POST api/export_riplay.php?produk=kredit&unduh=1   -> PDF diunduh
 *   produk: kredit | sikemas | deposito | tabungan | qurban | siswa (baru 'kredit' dan 'sikemas' yang sudah dibuat)
 *
 * Isian datang dari riplay.php lewat POST (data pribadi tidak masuk URL/log) dan TIDAK disimpan.
 * Validasi, simulasi, susunan dokumen, dan pembuat PDF ada di ../export_lib_riplay.php.
 * Hak akses: kode menu tiap produk (tabel menu, grup web_grup_riplay), lihat $AKSES di bawah.
 */
require __DIR__ . '/../lib.php';                 // wajib login
require_once __DIR__ . '/../export_lib.php';     // export_galat, export_siap, export_kop, export_logo, h_e
require_once __DIR__ . '/../export_lib_riplay.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') export_galat(405, 'Method tidak diizinkan.');
// produk => kode hak akses menu
$AKSES = ['kredit' => 'web_kredit_arthamas', 'sikemas' => 'web_sikemas', 'deposito' => 'web_depo_arthamas',
          'tabungan' => 'web_tab_arthamas', 'qurban' => 'web_tab_qurban', 'siswa' => 'web_tab_siswa'];
$produk = (string)($_GET['produk'] ?? 'kredit');
if (!isset($AKSES[$produk])) export_galat(400, 'Produk tidak dikenal.');
if (function_exists('boleh') && !boleh($AKSES[$produk])) export_galat(403, 'Kamu tidak punya akses ke RIPLAY produk ini.');
if (!in_array($produk, ['kredit', 'sikemas'], true)) export_galat(501, 'RIPLAY untuk produk ini belum tersedia.');

try {
    $unduh = (string)($_GET['unduh'] ?? '') === '1';
    if ($produk === 'sikemas') {
        require_once __DIR__ . '/../export_riplay_sikemas.php';
        $d = sikemas_baca($_POST);
        riplay_cetak(sikemas_html($d), 'RIPLAY Personal – SikEMAS', 'RIPLAY_SikEMAS_' . riplay_slug($d['nama']), $unduh);
    } else {
        riplay_pdf(riplay_baca($_POST), $unduh);                     // kredit
    }
} catch (Throwable $e) {
    error_log('export_riplay: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));   // tanpa data nasabah
    if (!headers_sent()) {
        header_remove('Content-Disposition');
        export_galat(500, 'PDF gagal dibuat. Coba lagi, atau hubungi admin.');
    }
}
exit;