<?php
/**
 * Proxy "belum bayar bulan ini" untuk halaman belum_bayar.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET ?nama=&kolek=&page=&per=   -> {rows:[...], total, total_page, page, total_kewajiban, total_baki, posisi}
 *
 * Pencarian dan pembagian halaman dilakukan di server; browser hanya menerima satu halaman (10 / 20 / 50 baris).
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
if (function_exists('akses_atau_403')) akses_atau_403('web_belum_bayar');   // hak akses menu (bila paket hak akses terpasang)

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

$nama  = trim((string)($_GET['nama'] ?? ''));
$kolek = trim((string)($_GET['kolek'] ?? ''));
$hal   = max(1, min(10000, (int)($_GET['page'] ?? 1)));
$per   = in_array((int)($_GET['per'] ?? 20), [10, 20, 50], true) ? (int)$_GET['per'] : 20;

if ($nama !== '' && mb_strlen($nama) < 2)          keluar(['error' => 'Nama minimal 2 karakter.'], 422);
if ($kolek !== '' && !preg_match('/^[0-9]$/', $kolek)) keluar(['error' => 'Kolektibilitas tidak valid.'], 422);

try {
    $cfg = api_cfg();
    $isi = ['page' => $hal, 'total_record' => $per];
    if ($nama !== '')  $isi['nama']  = $nama;
    if ($kolek !== '') $isi['kolek'] = $kolek;

    $r = api_call('belum_bayar', $isi, $cfg['path_nasabah']);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);

    $rows = [];
    foreach (($r['debitur'] ?? []) as $x) {
        $rows[] = [
            'norek'     => (string)($x['rekening'] ?? ''),
            'cif'       => (string)($x['cif'] ?? ''),
            'nama'      => trim((string)($x['nama'] ?? '')),
            'alamat'    => trim((string)($x['alamat'] ?? '')),
            'kolek'     => (string)($x['kolek'] ?? ''),
            'baki'      => (float)($x['baki'] ?? 0),
            'kewajiban' => (float)($x['tungPokok'] ?? 0) + (float)($x['tungBung'] ?? 0),
            'hari'      => $x['hari'] === null ? null : (int)$x['hari'],
            'tempo'     => (string)($x['tempo'] ?? ''),
        ];
    }
    keluar([
        'rows'            => $rows,
        'total'           => (int)($r['total'] ?? 0),
        'total_page'      => max(1, (int)($r['total_page'] ?? 1)),
        'page'            => $hal,
        'total_kewajiban' => (float)($r['total_kewajiban'] ?? 0),
        'total_baki'      => (float)($r['total_baki'] ?? 0),
        'posisi'          => (string)($r['posisi'] ?? ''),
    ]);
} catch (Throwable $e) {
    error_log('api_belum_bayar: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
