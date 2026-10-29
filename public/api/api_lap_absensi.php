<?php
/**
 * Proxy "laporan absensi" untuk halaman lap_absensi.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 *
 *   GET ?opsi=1                                       -> {karyawan:[{id,nama}]}   (isi dropdown filter)
 *   GET ?dari=&sampai=&karyawan=&status=&page=&per=   -> {rows:[...], total, total_page, page, ringkas:{...}, dari, sampai}
 *
 * Letak: folder api/. Foto absen TIDAK ikut (laporan hanya nama, waktu, lokasi, status).
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
date_default_timezone_set('Asia/Jakarta');
akses_atau_403('web_lap_absensi');         // hak akses menu Lap. Absensi

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function tgl_ok(string $t): bool
{
    $d = DateTime::createFromFormat('Y-m-d', $t);
    return $d && $d->format('Y-m-d') === $t;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') keluar(['error' => 'Method tidak diizinkan.'], 405);

try {
    $path = api_cfg()['path_absensi'];

    // ---------- isi dropdown karyawan ----------
    if (isset($_GET['opsi'])) {
        $r = api_call('lap_karyawan', [], $path);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => 'Daftar karyawan tidak dapat dimuat.'], 502);
        $out = [];
        foreach (($r['rows'] ?? []) as $x) $out[] = ['id' => (int)($x['id'] ?? 0), 'nama' => trim((string)($x['nama'] ?? ''))];
        keluar(['karyawan' => $out]);
    }

    // ---------- daftar per halaman ----------
    $dari     = trim((string)($_GET['dari'] ?? ''))   ?: date('Y-m-01');
    $sampai   = trim((string)($_GET['sampai'] ?? '')) ?: date('Y-m-d');
    $karyawan = trim((string)($_GET['karyawan'] ?? ''));
    $status   = trim((string)($_GET['status'] ?? ''));
    $hal      = max(1, min(10000, (int)($_GET['page'] ?? 1)));
    $per      = in_array((int)($_GET['per'] ?? 20), [10, 20, 50], true) ? (int)$_GET['per'] : 20;

    if (!tgl_ok($dari) || !tgl_ok($sampai))                  keluar(['error' => 'Tanggal tidak valid.'], 422);
    if ($sampai < $dari)                                     keluar(['error' => 'Tanggal akhir tidak boleh sebelum tanggal awal.'], 422);
    if ($karyawan !== '' && !preg_match('/^\d{1,9}$/', $karyawan)) keluar(['error' => 'Karyawan tidak valid.'], 422);
    if ($status !== '' && !in_array($status, ['masuk', 'pulang'], true)) keluar(['error' => 'Status tidak valid.'], 422);

    $isi = ['dari' => $dari, 'sampai' => $sampai, 'page' => $hal, 'total_record' => $per];
    if ($karyawan !== '') $isi['karyawan'] = (int)$karyawan;
    if ($status !== '')   $isi['status']   = $status;

    $r = api_call('lap_daftar', $isi, $path);
    if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Data tidak dapat dimuat.')], 502);

    $rows = [];
    foreach (($r['rows'] ?? []) as $x) {
        $rows[] = [
            'nama'   => trim((string)($x['nama'] ?? '')),
            'bagian' => trim((string)($x['bagian'] ?? '')),
            'status' => (string)($x['status'] ?? ''),
            'lokasi' => trim((string)($x['lokasi'] ?? '')),
            'waktu'  => (string)($x['waktu'] ?? ''),
        ];
    }
    keluar([
        'rows'       => $rows,
        'total'      => (int)($r['total'] ?? 0),
        'total_page' => max(1, (int)($r['total_page'] ?? 1)),
        'page'       => $hal,
        'ringkas'    => (array)($r['ringkas'] ?? []),
        'dari'       => $dari,
        'sampai'     => $sampai,
    ]);
} catch (Throwable $e) {
    error_log('api_lap_absensi: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
