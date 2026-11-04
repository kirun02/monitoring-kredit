<?php
/**
 * Cetak Tagihan AO ke Excel / PDF. Letak: folder api/ (sejajar export.php).
 *
 *   GET export_tagihan.php?awal=YYYY-MM-DD&akhir=YYYY-MM-DD&ao=&urut=tgl|ao|hari&format=xlsx|pdf[&unduh=1]
 *
 * Isinya sama dengan halaman tagihan.php. Id pengguna diambil dari SESI login (bukan dari alamat), jadi akun AO selalu terkunci ke kode_ao miliknya.
 * Hak akses: menu 'web_tagihan'. Pembuat berkas: export_lib.php (export_kirim).
 */
require __DIR__ . '/../lib.php';           // wajib login
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';
date_default_timezone_set('Asia/Jakarta');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') export_galat(405, 'Method tidak diizinkan.');
if (function_exists('boleh') && !boleh('web_tagihan')) export_galat(403, 'Kamu tidak punya akses ke laporan ini.');

$format = (string)($_GET['format'] ?? 'xlsx');
if (!in_array($format, ['xlsx', 'pdf'], true)) export_galat(400, 'Format harus xlsx atau pdf.');
$pdf = $format === 'pdf';

$u   = function_exists('auth_user') ? (array)(auth_user() ?? []) : [];
$uid = (int)($u['id'] ?? $u['id_user'] ?? $u['user_id'] ?? 0);
if ($uid <= 0) export_galat(500, 'Sesi login tidak memuat id pengguna. Hubungi admin.');

$awal  = trim((string)($_GET['awal'] ?? ''));
$akhir = trim((string)($_GET['akhir'] ?? ''));
$ao    = trim((string)($_GET['ao'] ?? ''));
$urut  = trim((string)($_GET['urut'] ?? ''));
$tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
if (($awal === '') !== ($akhir === ''))                              export_galat(422, 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.');
if ($awal !== '' && (!$tglOk($awal) || !$tglOk($akhir)))              export_galat(422, 'Tanggal tidak valid.');
if ($awal !== '' && $akhir < $awal)                                   export_galat(422, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
if ($awal !== '' && substr($awal, 0, 7) !== substr($akhir, 0, 7))     export_galat(422, 'Tanggal awal dan akhir harus dalam bulan yang sama.');
if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao))      export_galat(422, 'Kode AO tidak valid.');
if ($urut !== '' && !in_array($urut, ['tgl', 'ao', 'hari'], true))    export_galat(422, 'Urutan tidak valid.');

function tg_rp($v): string { return number_format((float)$v, 0, ',', '.'); }
function tg_tgl($t): string { $x = strtotime((string)$t); return $x === false ? '' : date('d/m/Y', $x); }

try {
    $p = ['uid' => (string)$uid];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao'] = $ao;
    if ($urut !== '') $p['urut'] = $urut;
    $r = api_call('tagihan', ['params' => $p], api_cfg()['path_monitoring'], 120);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));

    $rk = (array)$d['ringkas'];
    $kjOk = ($d['kunjungan_tersedia'] ?? true) !== false;
    $tpOk = ($d['telepon_tersedia'] ?? true) !== false;
    // teks ringkas kunjungan / telepon: "2x, terakhir 05/11; janji 12/11"
    $aktTeks = function ($v, string $kosong, bool $ok): string {
        if (!$ok) return '–';
        if (!$v) return $kosong;
        return $v['n'] . 'x, terakhir ' . date('d/m', strtotime((string)$v['terakhir']))
            . (!empty($v['komit']) ? '; ' . (!empty($v['komit_lewat']) ? 'janji lewat ' : 'janji ') . date('d/m', strtotime((string)$v['komit'])) : '');
    };
    $rows = [];
    $sum = ['kw_pkk' => 0.0, 'kw_bg' => 0.0, 'byr_pkk' => 0.0, 'byr_bg' => 0.0, 'kurang_pkk' => 0.0, 'kurang_bg' => 0.0,
            'tung_awal_pkk' => 0.0, 'tung_awal_bg' => 0.0, 'tung_sisa_pkk' => 0.0, 'tung_sisa_bg' => 0.0];
    foreach ((array)($d['rows'] ?? []) as $x) {
        $kv = $x['kunjungan'] ?? null;
        $kjTeks = $aktTeks($kv, 'Belum dikunjungi', $kjOk);
        $tv = $x['telepon'] ?? null;
        $tpTeks = $aktTeks($tv, 'Belum ditelepon', $tpOk);
        $row = [
            'nama' => trim((string)$x['nama']), 'norek' => (string)$x['norek'], 'cif' => (string)$x['cif'], 'ao' => (string)$x['ao'], 'alamat' => (string)$x['alamat'],
            'tgl_jt' => $x['tgl_jt'], 'hari' => $x['hari'], 'kolek' => $x['kolek'], 'xnunggak' => $x['xnunggak'],
            'kw_pkk' => (float)$x['kw_pkk'], 'kw_bg' => (float)$x['kw_bg'], 'byr_pkk' => (float)$x['byr_pkk'], 'byr_bg' => (float)$x['byr_bg'],
            'kurang_pkk' => $x['kurang_pkk'], 'kurang_bg' => $x['kurang_bg'],
            'tung_awal_pkk' => $x['tung_awal_pkk'], 'tung_awal_bg' => $x['tung_awal_bg'], 'tung_sisa_pkk' => $x['tung_sisa_pkk'], 'tung_sisa_bg' => $x['tung_sisa_bg'],
            'kunjungan' => $kjTeks,
            'kj_n' => $kv ? $kv['n'] : 0, 'kj_terakhir' => $kv ? tg_tgl($kv['terakhir']) : '', 'kj_komit' => ($kv && !empty($kv['komit'])) ? tg_tgl($kv['komit']) : '',
            'kj_ket' => $kv ? (string)$kv['ket'] : '',
            'telepon' => $tpTeks, 'tp_n' => $tv ? $tv['n'] : 0, 'tp_terakhir' => $tv ? tg_tgl($tv['terakhir']) : '', 'tp_komit' => ($tv && !empty($tv['komit'])) ? tg_tgl($tv['komit']) : '',
            'tp_ket' => $tv ? (string)$tv['ket'] : '',
        ];
        foreach ($sum as $k => $_) $sum[$k] += (float)($row[$k] ?? 0);
        // PDF: pokok / bunga digabung dalam satu sel teks (tidak dijumlahkan) supaya muat di kertas
        $pb = function ($a, $b) { return $a === null ? '–' : tg_rp($a) . ' / ' . tg_rp($b ?? 0); };
        $row['t_kw']     = $pb($row['kw_pkk'], $row['kw_bg']);
        $row['t_byr']    = $pb($row['byr_pkk'], $row['byr_bg']);
        $row['t_kurang'] = $pb($row['kurang_pkk'], $row['kurang_bg']);
        $row['t_awal']   = $pb($row['tung_awal_pkk'], $row['tung_awal_bg']);
        $row['t_sisa']   = $pb($row['tung_sisa_pkk'], $row['tung_sisa_bg']);
        $row['t_kolek']  = ($x['kolek'] ?? '–') . ($x['xnunggak'] === null ? '' : ' / ' . $x['xnunggak'] . 'x');
        $rows[] = $row;
    }

    // ---- RINGKASAN (lembar terpisah di Excel, halaman terakhir di PDF): hanya debitur yang tampil di daftar tagihan ----
    $batasNpl = (int)($d['batas_npl'] ?? 90);
    $g = ['n' => 0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0, 'byr_pkk' => 0.0, 'byr_bg' => 0.0,
          'tg_pkk' => 0.0, 'tg_bg' => 0.0, 'ta_pkk' => 0.0, 'ta_bg' => 0.0,
          'pot_n' => 0, 'pot_baki' => 0.0, 'npl_n' => 0, 'npl_baki' => 0.0];
    foreach ((array)($d['rows'] ?? []) as $x) {
        $g['n']++;
        $g['kw_pkk']  += (float)$x['kw_pkk'];         $g['kw_bg']  += (float)$x['kw_bg'];
        $g['byr_pkk'] += (float)$x['byr_pkk'];        $g['byr_bg'] += (float)$x['byr_bg'];
        $g['tg_pkk']  += (float)$x['tung_sisa_pkk'];  $g['tg_bg']  += (float)$x['tung_sisa_bg'];
        $g['ta_pkk']  += (float)$x['tung_awal_pkk'];  $g['ta_bg']  += (float)$x['tung_awal_bg'];
        $hr = (int)$x['hari'];
        if ($hr > $batasNpl)          { $g['npl_n']++; $g['npl_baki'] += (float)$x['baki']; }
        elseif ($hr > $batasNpl - 30) { $g['pot_n']++; $g['pot_baki'] += (float)$x['baki']; }
    }
    $tungOk  = !empty($d['tung_tersedia']);
    $rentang = 'tgl pinjam ' . $d['hari_dari'] . '–' . $d['hari_sampai'];
    $ringkasan = [
        'judul'   => 'Ringkasan Tagihan',
        'sub'     => $rentang . ' · periode ' . $d['periode'] . ' · ' . $d['ao']['nama'] . ' · ' . tg_rp($g['n']) . ' debitur pada daftar tagihan',
        'kolom'   => ['Uraian', 'Debitur', 'Pokok (Rp)', 'Bunga (Rp)', 'Baki debet (Rp)'],
        'baris'   => [
            ['1. Total kewajiban bulan ini (' . $rentang . ')',                                   $g['n'], $g['kw_pkk'], $g['kw_bg'], null],
            ['2. Total tunggakan (sisa)',                                                         $g['n'], $tungOk ? $g['tg_pkk'] : null, $tungOk ? $g['tg_bg'] : null, null],
            ['    Tunggakan awal (baseline)',                                                     $g['n'], $tungOk ? $g['ta_pkk'] : null, $tungOk ? $g['ta_bg'] : null, null],
            ['3. Berpotensi masuk NPL (hari tunggak ' . ($batasNpl - 29) . '–' . $batasNpl . ')', $g['pot_n'], null, null, $g['pot_baki']],
            ['    Sudah NPL (lebih dari ' . $batasNpl . ' hari)',                                  $g['npl_n'], null, null, $g['npl_baki']],
            ['4. Pelunasan (baki debet nol)',                                                     (int)($rk['lunas_keluar'] ?? 0), null, null, (float)($rk['lunas_baki'] ?? 0)],
            ['    Pembayaran angsuran bulan ini (debitur yang masih di daftar)',                  $g['n'], $g['byr_pkk'], $g['byr_bg'], null],
        ],
        'catatan' => 'Poin 1, 2, 3, dan pembayaran angsuran dihitung dari debitur yang tampil di daftar tagihan. '
                   . 'Pelunasan = rekening di baseline yang baki debetnya nol di nominatif terakhir (atau sudah tidak ada di nominatif); nilainya baki debet awal (baseline). Pembayaran angsuran dipisah dari pelunasan. '
                   . 'Pokok dan bunga tidak dijumlahkan.',
    ];

    if ($pdf) {
        $kolom = [
            ['h' => 'No',            't' => 'no',   'w' => 4],
            ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 17],
            ['h' => 'No. rekening',  'k' => 'norek','w' => 12],
        ];
        if ($ao === '' && empty($d['kunci_ao'])) $kolom[] = ['h' => 'AO', 'k' => 'ao', 'w' => 9];
        $kolom = array_merge($kolom, [
            ['h' => 'Alamat',        'k' => 'alamat', 'w' => 17],
            ['h' => 'Tgl pinjam',    'k' => 'tgl_jt', 't' => 'int', 'w' => 4],
            ['h' => 'Kolek / nunggak', 'k' => 't_kolek', 'w' => 6],
            ['h' => 'Kewajiban bln ini pokok / bunga', 'k' => 't_kw',     'w' => 11],
            ['h' => 'Sudah bayar pokok / bunga',       'k' => 't_byr',    'w' => 10],
            ['h' => 'Kurang kewajiban pokok / bunga',  'k' => 't_kurang', 'w' => 11],
            ['h' => 'Tunggakan awal pokok / bunga',    'k' => 't_awal',   'w' => 12],
            ['h' => 'Sisa tunggakan pokok / bunga',    'k' => 't_sisa',   'w' => 12],
            ['h' => 'Kunjungan',     'k' => 'kunjungan', 'w' => 13],
            ['h' => 'Telepon',       'k' => 'telepon',   'w' => 13],
        ]);
        $total = null;
    } else {
        $kolom = [
            ['h' => 'No',            't' => 'no',   'w' => 5],
            ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 24],
            ['h' => 'No. rekening',  'k' => 'norek','w' => 16],
            ['h' => 'CIF',           'k' => 'cif',  'w' => 12],
            ['h' => 'AO',            'k' => 'ao',   'w' => 16],
            ['h' => 'Alamat',        'k' => 'alamat', 'w' => 34],
            ['h' => 'Tgl pinjam (hari)', 'k' => 'tgl_jt', 't' => 'int', 'w' => 9],
            ['h' => 'Kolek',         'k' => 'kolek', 't' => 'int', 'w' => 7],
            ['h' => 'Nunggak (x)',   'k' => 'xnunggak', 't' => 'int', 'w' => 8],
            ['h' => 'Kewajiban bln ini pokok (Rp)', 'k' => 'kw_pkk', 't' => 'rp', 'w' => 16],
            ['h' => 'Kewajiban bln ini bunga (Rp)', 'k' => 'kw_bg',  't' => 'rp', 'w' => 16],
            ['h' => 'Sudah bayar pokok (Rp)',       'k' => 'byr_pkk', 't' => 'rp', 'w' => 15],
            ['h' => 'Sudah bayar bunga (Rp)',       'k' => 'byr_bg',  't' => 'rp', 'w' => 15],
            ['h' => 'Kurang kewajiban pokok (Rp)',  'k' => 'kurang_pkk', 't' => 'rp', 'w' => 16],
            ['h' => 'Kurang kewajiban bunga (Rp)',  'k' => 'kurang_bg',  't' => 'rp', 'w' => 16],
            ['h' => 'Tunggakan awal pokok (Rp)',    'k' => 'tung_awal_pkk', 't' => 'rp', 'w' => 16],
            ['h' => 'Tunggakan awal bunga (Rp)',    'k' => 'tung_awal_bg',  't' => 'rp', 'w' => 16],
            ['h' => 'Sisa tunggakan pokok (Rp)',    'k' => 'tung_sisa_pkk', 't' => 'rp', 'w' => 16],
            ['h' => 'Sisa tunggakan bunga (Rp)',    'k' => 'tung_sisa_bg',  't' => 'rp', 'w' => 16],
            ['h' => 'Jumlah kunjungan bulan ini', 'k' => 'kj_n', 't' => 'int', 'w' => 11],
            ['h' => 'Kunjungan terakhir', 'k' => 'kj_terakhir', 'w' => 13],
            ['h' => 'Janji bayar',        'k' => 'kj_komit',    'w' => 12],
            ['h' => 'Keterangan kunjungan', 'k' => 'kj_ket',    'w' => 40],
            ['h' => 'Jumlah telepon bulan ini', 'k' => 'tp_n', 't' => 'int', 'w' => 11],
            ['h' => 'Telepon terakhir',   'k' => 'tp_terakhir', 'w' => 13],
            ['h' => 'Janji bayar (telepon)', 'k' => 'tp_komit', 'w' => 14],
            ['h' => 'Keterangan telepon', 'k' => 'tp_ket',      'w' => 40],
        ];
        $total = $sum;
    }

    $meta = [
        'Periode: ' . $d['periode'] . '   |   Tanggal pinjam: ' . $d['hari_dari'] . '–' . $d['hari_sampai'] . ' (rentang ' . tg_tgl($d['sel']['awal']) . ' s/d ' . tg_tgl($d['sel']['akhir']) . ')'
            . '   |   AO: ' . $d['ao']['nama'],
        'Data inti dari baseline ' . tg_tgl($d['posisi_baseline']) . '   |   Pembayaran dan sisa tunggakan dari posisi ' . tg_tgl($d['posisi_akhir']),
        'Perlu ditagih: ' . tg_rp($rk['n']) . ' debitur (belum bayar sama sekali ' . tg_rp($rk['belum_bayar']) . ', sebagian ' . tg_rp($rk['sebagian']) . ', kewajiban lunas tapi tunggakan tersisa ' . tg_rp($rk['kewajiban_ok'])
            . ')   |   Belum dikunjungi: ' . ($kjOk ? tg_rp($rk['belum_kunjung']) : '–') . '   |   Belum ditelepon: ' . ($tpOk ? tg_rp($rk['belum_telepon'] ?? 0) : '–') . '   |   Janji bayar: ' . tg_rp($rk['janji']) . ' (lewat ' . tg_rp($rk['janji_lewat']) . ')',
        'Kurang kewajiban: pokok Rp ' . tg_rp($rk['kurang_pkk']) . ', bunga Rp ' . tg_rp($rk['kurang_bg']) . '   |   Sisa tunggakan: pokok Rp ' . tg_rp($rk['tung_sisa_pkk']) . ', bunga Rp ' . tg_rp($rk['tung_sisa_bg']),
        'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)($u['nama'] ?? '-'),
    ];
    $catatan = array_map('strval', (array)($d['peringatan'] ?? []));
    $catatan[] = 'Rentang tanggal memilih debitur menurut hari pada tanggal pinjam. Daftar, kewajiban bulan ini, dan tunggakan awal dari baseline; pembayaran dan sisa tunggakan dari nominatif harian terakhir. '
        . 'Debitur tampil bila kewajiban bulan ini belum terbayar penuh atau masih ada sisa tunggakan; hilang bila keduanya terpenuhi atau rekening sudah tidak ada di nominatif (lunas). '
        . 'Kewajiban dan tunggakan tidak dijumlahkan; pokok dan bunga ditampilkan terpisah' . ($pdf ? ' (dalam satu sel: pokok / bunga)' : '') . '. Kunjungan dan telepon dihitung dari catatan bulan itu.';

    if ($pdf && count($rows) > 1500) {
        export_galat(413, 'Data terlalu banyak untuk PDF (' . tg_rp(count($rows)) . ' baris, batas 1.500). Persempit rentang tanggal atau pilih satu AO, atau unduh Excel.');
    }
    export_kirim([
        'berkas'  => 'tagihan_ao',
        'judul'   => 'Tagihan Account Officer',
        'meta'    => $meta,
        'kolom'   => $kolom,
        'rows'    => $rows,
        'total'   => $total,
        'catatan' => $catatan,
        'ringkasan' => $ringkasan,
    ], $format);
} catch (Throwable $e) {
    error_log('export_tagihan: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    export_galat(502, 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
}