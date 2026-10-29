<?php
/**
 * Unduh laporan ke Excel / PDF (PhpSpreadsheet). Letak: folder api/
 *
 *   GET export.php?laporan=belum_bayar&format=xlsx|pdf&nama=&kolek=
 *   GET export.php?laporan=prioritas&format=xlsx|pdf&tgl=&ao=&min=
 *   GET export.php?laporan=lap_absensi&format=xlsx|pdf&dari=&sampai=&karyawan=&status=
 *
 * Hanya laporan yang terdaftar di $LAPORAN, dan hanya filter yang di-whitelist per laporan.
 * Data diambil lewat API backend (terenkripsi) dalam SATU panggilan khusus cetak (tanpa halaman; backend hanya memasang batas pengaman).
 * Menambah laporan baru = tambah satu fungsi export_xxx() yang mengembalikan $spec, lalu daftarkan di $LAPORAN.
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';
date_default_timezone_set('Asia/Jakarta');

function rp_teks($v): string { return number_format((float)$v, 0, ',', '.'); }

// ---------------------------------------------------------------------------------------------
// LAPORAN: debitur belum bayar bulan ini
// ---------------------------------------------------------------------------------------------
function export_belum_bayar(): array
{
    $nama  = trim((string)($_GET['nama'] ?? ''));
    $kolek = trim((string)($_GET['kolek'] ?? ''));
    if ($nama !== '' && mb_strlen($nama) < 2)              export_galat(422, 'Nama minimal 2 huruf.');
    if ($kolek !== '' && !preg_match('/^[0-9]$/', $kolek)) export_galat(422, 'Kolektibilitas tidak valid.');

    $isi = [];                                  // jenis khusus cetak: semua baris, tanpa halaman
    if ($nama !== '')  $isi['nama']  = $nama;
    if ($kolek !== '') $isi['kolek'] = $kolek;

    $r = api_call('belum_bayar_semua', $isi, api_cfg()['path_nasabah']);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));

    $rows = [];
    $sum  = ['baki' => 0.0, 'kw_pokok' => 0.0, 'kw_bunga' => 0.0, 'kw_total' => 0.0];
    foreach (($r['debitur'] ?? []) as $x) {
        $row = [
            'nama'     => trim((string)($x['nama'] ?? '')),
            'norek'    => (string)($x['rekening'] ?? ''),
            'alamat'   => trim((string)($x['alamat'] ?? '')),
            'kolek'    => $x['kolek'] ?? null,
            'hari'     => $x['hari'] ?? null,
            'tempo'    => (string)($x['tempo'] ?? ''),
            'baki'     => (float)($x['baki'] ?? 0),
            'kw_pokok' => (float)($x['tungPokok'] ?? 0),
            'kw_bunga' => (float)($x['tungBung'] ?? 0),
        ];
        $row['kw_total'] = $row['kw_pokok'] + $row['kw_bunga'];
        foreach ($sum as $k => $_) $sum[$k] += $row[$k];
        $rows[] = $row;
    }

    $terpotong = !empty($r['terpotong']);
    $filter = [];
    if ($nama !== '')  $filter[] = 'nama mengandung "' . $nama . '"';
    if ($kolek !== '') $filter[] = 'kolektibilitas ' . $kolek;
    $posisi = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)($r['posisi'] ?? ''), $m) ? "$m[3]/$m[2]/$m[1]" : '–';

    return [
        'berkas' => 'belum_bayar',
        'judul'  => 'Debitur Belum Ada Pembayaran Bulan Ini',
        'meta'   => [
            'Posisi data: ' . $posisi . '   |   Filter: ' . ($filter ? implode('; ', $filter) : 'semua debitur yang belum bayar'),
            'Jumlah: ' . rp_teks($r['total'] ?? count($rows)) . ' rekening   |   Kewajiban bulan ini: Rp ' . rp_teks($r['total_kewajiban'] ?? 0)
                . '   |   Baki debet: Rp ' . rp_teks($r['total_baki'] ?? 0),
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)((auth_user() ?? [])['nama'] ?? '-'),
        ],
        'kolom'  => [
            ['h' => 'No',               't' => 'no',   'w' => 5],
            ['h' => 'Nama debitur',     'k' => 'nama',  'w' => 24],
            ['h' => 'No. rekening',     'k' => 'norek', 'w' => 14],
            ['h' => 'Alamat',           'k' => 'alamat','w' => 30],
            ['h' => 'Kolek',            'k' => 'kolek', 't' => 'int',  'w' => 6],
            ['h' => 'Hari tunggak',     'k' => 'hari',  't' => 'int',  'w' => 8],
            ['h' => 'Jatuh tempo',      'k' => 'tempo', 't' => 'date', 'w' => 11],
            ['h' => 'Baki debet (Rp)',  'k' => 'baki',  't' => 'rp',   'w' => 15],
            ['h' => 'Kewajiban pokok (Rp)', 'k' => 'kw_pokok', 't' => 'rp', 'w' => 15, 'xlsx' => true],   // rincian hanya di Excel
            ['h' => 'Kewajiban bunga (Rp)', 'k' => 'kw_bunga', 't' => 'rp', 'w' => 15, 'xlsx' => true],
            ['h' => 'Kewajiban bulan ini (Rp)', 'k' => 'kw_total', 't' => 'rp', 'w' => 16],
        ],
        'rows'    => $rows,
        'total'   => $terpotong ? null : $sum,
        'catatan' => array_values(array_filter([
            $terpotong ? 'Data dibatasi pada baris pertama dari ' . rp_teks($r['total'] ?? 0) . ' rekening. Persempit filter untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.' : null,
            'Belum bayar = ada kewajiban bulan ini (pokok + bunga) tetapi pembayaran pokok dan bunga bulan ini nol, menurut data posisi terakhir. Urut: paling lama menunggak, lalu baki debet terbesar.',
        ])),
    ];
}

// ---------------------------------------------------------------------------------------------
// LAPORAN: prioritas tagih (debitur kolek 1-2 yang hari tunggaknya mendekati batas NPL)
// Sumber: jenis_req 'prioritas' (endpoint Monitoring), sama dengan yang dipakai halaman prioritas.php.
// ---------------------------------------------------------------------------------------------
function export_prioritas(): array
{
    $tgl = trim((string)($_GET['tgl'] ?? ''));
    $ao  = trim((string)($_GET['ao'] ?? ''));
    $min = (int)($_GET['min'] ?? 60);
    if ($tgl !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl))      export_galat(422, 'Tanggal posisi tidak valid.');
    if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao))      export_galat(422, 'Kode AO tidak valid.');
    if (!in_array($min, [30, 45, 60, 75, 90], true))                     export_galat(422, 'Hari tunggak minimal tidak valid.');

    $p = ['min' => (string)$min];
    if ($tgl !== '') $p['tgl'] = $tgl;
    if ($ao !== '')  $p['ao']  = $ao;

    $r = api_call('prioritas', ['params' => $p]);          // path_monitoring dipilih otomatis oleh api_path()
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));
    $d = (array)($r['data'] ?? []);
    if (!empty($d['empty'])) export_galat(404, 'Belum ada data posisi. Upload nominatif dulu.');

    $rows = [];
    $sum  = ['bakidebet' => 0.0, 'tagihan' => 0.0, 'bayar' => 0.0];
    foreach (($d['rows'] ?? []) as $x) {
        $row = [
            'nama'      => trim((string)($x['nama'] ?? '')),
            'norek'     => (string)($x['norek'] ?? ''),
            'cif'       => (string)($x['cif'] ?? ''),
            'alamat'    => trim((string)($x['alamat'] ?? '')),
            'ao'        => (string)($x['ao'] ?? ''),
            'kolek'     => $x['kolek'] ?? null,
            'hari'      => $x['hari'] ?? null,
            'sisa'      => (string)($x['sisa'] ?? ''),
            'bakidebet' => (float)($x['bakidebet_raw'] ?? 0),
            'tagihan'   => (float)($x['tagihan_raw'] ?? 0),
            'bayar'     => (float)($x['bayar_raw'] ?? 0),
            'status'    => (string)($x['status'] ?? ''),
        ];
        foreach ($sum as $k => $_) $sum[$k] += $row[$k];
        $rows[] = $row;
    }

    // backend membatasi daftar kerja 5.000 baris; bila jumlahnya persis segitu, anggap terpotong
    $terpotong = count($rows) >= 5000;

    $namaAo = 'Semua AO';
    if ($ao !== '') {
        $namaAo = $ao;
        foreach (($d['aos'] ?? []) as $a) if ((string)($a['v'] ?? '') === $ao) { $namaAo = (string)$a['l']; break; }
    }
    $posisi = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string)($d['sel']['tgl'] ?? ''), $m) ? "$m[3]/$m[2]/$m[1]" : '–';
    $rk     = (array)($d['ringkas'] ?? []);
    $batas  = (int)($d['batas'] ?? 90);

    return [
        'berkas' => 'prioritas_tagih',
        'judul'  => 'Prioritas Tagih',
        'meta'   => [
            'Posisi data: ' . $posisi . '   |   AO: ' . $namaAo . '   |   Hari tunggak minimal: ' . $min . ' hari   |   Batas NPL: ' . $batas . ' hari',
            'Jumlah: ' . ($d['daftar']['n'] ?? count($rows)) . ' debitur   |   Baki debet: ' . ($d['daftar']['nom'] ?? '–')
                . '   |   Potensi tambahan NPL (semua AO): ' . ($rk['potensi'] ?? '–')
                . '   |   NPL sekarang: ' . ($rk['npl_pct'] ?? '–') . ', jadi ' . ($rk['proyeksi_pct'] ?? '–') . ' bila semuanya tidak tertagih',
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)((auth_user() ?? [])['nama'] ?? '-'),
        ],
        'kolom'  => [
            ['h' => 'No',             't' => 'no',    'w' => 5],
            ['h' => 'Nama debitur',   'k' => 'nama',  'w' => 24],
            ['h' => 'No. rekening',   'k' => 'norek', 'w' => 14],
            ['h' => 'CIF',            'k' => 'cif',   'w' => 12, 'xlsx' => true],                  // hanya di Excel
            ['h' => 'Alamat',         'k' => 'alamat','w' => 28],
            ['h' => 'AO',             'k' => 'ao',    'w' => 16],
            ['h' => 'Kolek',          'k' => 'kolek', 't' => 'int', 'w' => 6],
            ['h' => 'Hari tunggak',   'k' => 'hari',  't' => 'int', 'w' => 8],
            ['h' => 'Sisa waktu',     'k' => 'sisa',  'w' => 12],
            ['h' => 'Baki debet (Rp)',      'k' => 'bakidebet', 't' => 'rp', 'w' => 15],
            ['h' => 'Tagihan bln ini (Rp)', 'k' => 'tagihan',   't' => 'rp', 'w' => 15],
            ['h' => 'Bayar bln ini (Rp)',   'k' => 'bayar',     't' => 'rp', 'w' => 15],
            ['h' => 'Status bayar',   'k' => 'status','w' => 12],
        ],
        'rows'    => $rows,
        'total'   => $terpotong ? null : $sum,
        'catatan' => array_values(array_filter([
            $terpotong ? 'Daftar dibatasi 5.000 baris pertama. Pilih satu AO untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.' : null,
            'Debitur kolek 1-2 dengan hari tunggak minimal ' . $min . ' hari. "Sisa waktu" dihitung dari batas NPL ' . $batas
                . ' hari; "Lewat batas" berarti hari tunggak sudah di atas batas tetapi kolek di data belum berubah. Urut: hari tunggak terbanyak, lalu baki debet terbesar.',
        ])),
    ];
}

// ---------------------------------------------------------------------------------------------
// LAPORAN: absensi karyawan (nama, waktu, lokasi, status; tanpa foto)
// Sumber: jenis_req 'lap_semua' (endpoint absensi), urut waktu terlama dulu.
// ---------------------------------------------------------------------------------------------
function export_absensi(): array
{
    $dari     = trim((string)($_GET['dari'] ?? ''))   ?: date('Y-m-01');
    $sampai   = trim((string)($_GET['sampai'] ?? '')) ?: date('Y-m-d');
    $karyawan = trim((string)($_GET['karyawan'] ?? ''));
    $status   = trim((string)($_GET['status'] ?? ''));

    $tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
    if (!$tglOk($dari) || !$tglOk($sampai))                          export_galat(422, 'Tanggal tidak valid.');
    if ($sampai < $dari)                                             export_galat(422, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
    if ($karyawan !== '' && !preg_match('/^\d{1,9}$/', $karyawan))   export_galat(422, 'Karyawan tidak valid.');
    if ($status !== '' && !in_array($status, ['masuk', 'pulang'], true)) export_galat(422, 'Status tidak valid.');

    $isi = ['dari' => $dari, 'sampai' => $sampai];
    if ($karyawan !== '') $isi['karyawan'] = (int)$karyawan;
    if ($status !== '')   $isi['status']   = $status;

    $r = api_call('lap_semua', $isi, api_cfg()['path_absensi']);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));

    $rows = [];
    foreach (($r['rows'] ?? []) as $x) {
        $w = (string)($x['waktu'] ?? '');                       // 2026-10-21 08:03:12
        $rows[] = [
            'nama'    => trim((string)($x['nama'] ?? '')),
            'bagian'  => trim((string)($x['bagian'] ?? '')),
            'tanggal' => substr($w, 0, 10),
            'jam'     => substr($w, 11, 8),
            'status'  => ($x['status'] ?? '') === 'masuk' ? 'Masuk' : (($x['status'] ?? '') === 'pulang' ? 'Pulang' : (string)($x['status'] ?? '')),
            'lokasi'  => trim((string)($x['lokasi'] ?? '')),
        ];
    }

    $terpotong = !empty($r['terpotong']);
    $fmt       = function (string $t): string { return date('d/m/Y', strtotime($t)); };
    $rk        = (array)($r['ringkas'] ?? []);
    $namaKar   = 'Semua karyawan';
    if ($karyawan !== '') $namaKar = $rows ? $rows[0]['nama'] : ('ID ' . $karyawan);
    $namaStat  = $status === 'masuk' ? 'Masuk' : ($status === 'pulang' ? 'Pulang' : 'Masuk dan pulang');

    return [
        'berkas' => 'laporan_absensi',
        'judul'  => 'Laporan Absensi Karyawan',
        'meta'   => [
            'Periode: ' . $fmt($dari) . ' s/d ' . $fmt($sampai) . '   |   Karyawan: ' . $namaKar . '   |   Status: ' . $namaStat,
            'Jumlah: ' . rp_teks($rk['total'] ?? count($rows)) . ' absen   |   Masuk: ' . rp_teks($rk['masuk'] ?? 0)
                . '   |   Pulang: ' . rp_teks($rk['pulang'] ?? 0) . '   |   Karyawan tercatat: ' . rp_teks($rk['orang'] ?? 0) . ' orang',
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)((auth_user() ?? [])['nama'] ?? '-'),
        ],
        'kolom'  => [
            ['h' => 'No',            't' => 'no',     'w' => 5],
            ['h' => 'Nama karyawan', 'k' => 'nama',   'w' => 26],
            ['h' => 'Bagian',        'k' => 'bagian', 'w' => 16],
            ['h' => 'Tanggal',       'k' => 'tanggal','t' => 'date', 'w' => 11],
            ['h' => 'Jam',           'k' => 'jam',    'w' => 9],
            ['h' => 'Status',        'k' => 'status', 'w' => 9],
            ['h' => 'Lokasi (lintang, bujur)', 'k' => 'lokasi', 'w' => 28],
        ],
        'rows'    => $rows,
        'total'   => null,                                       // tidak ada angka yang dijumlahkan
        'catatan' => array_values(array_filter([
            $terpotong ? 'Data dibatasi pada ' . rp_teks(count($rows)) . ' baris pertama dari ' . rp_teks($rk['total'] ?? 0) . ' absen. Persempit periode atau pilih satu karyawan untuk melihat seluruhnya.' : null,
            'Waktu sesuai catatan sistem saat absen dikirim. Lokasi berupa koordinat GPS dari perangkat karyawan. Urut: waktu terlama dulu. Foto absen tidak disertakan.',
        ])),
    ];
}

// ---------------------------------------------------------------------------------------------
// DAFTAR LAPORAN: kode menu (hak akses), batas baris PDF, fungsi pembangun
// ---------------------------------------------------------------------------------------------
$LAPORAN = [
    'belum_bayar' => ['menu' => 'web_belum_bayar', 'batas_pdf' => 1500, 'bangun' => 'export_belum_bayar'],
    'prioritas'   => ['menu' => 'web_prioritas',   'batas_pdf' => 1500, 'bangun' => 'export_prioritas'],     // CEK kode menu ini di sistem hak akses
    'lap_absensi' => ['menu' => 'web_lap_absensi', 'batas_pdf' => 1500, 'bangun' => 'export_absensi'],
];

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');

$kode   = (string)($_GET['laporan'] ?? '');
$format = (string)($_GET['format'] ?? 'xlsx');
if (!isset($LAPORAN[$kode]))                  export_galat(404, 'Laporan tidak dikenal.');
if (!in_array($format, ['xlsx', 'pdf'], true)) export_galat(400, 'Format harus xlsx atau pdf.');

$def = $LAPORAN[$kode];
if (function_exists('boleh') && !boleh($def['menu'])) export_galat(403, 'Kamu tidak punya akses ke laporan ini.');

try {
    $spec = $def['bangun']();
    if ($format === 'pdf' && count($spec['rows']) > $def['batas_pdf']) {
        export_galat(413, 'Data terlalu banyak untuk PDF (' . rp_teks(count($spec['rows'])) . ' baris, batas ' . rp_teks($def['batas_pdf'])
            . '). Persempit filter, atau unduh Excel.');
    }
    export_kirim($spec, $format);
} catch (Throwable $e) {
    error_log('export ' . $kode . ': ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    export_galat(502, 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
}