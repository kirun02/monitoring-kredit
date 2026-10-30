<?php
/**
 * Unduh laporan ke Excel / PDF (PhpSpreadsheet). Letak: folder api/
 *
 *   GET export.php?laporan=belum_bayar&format=xlsx|pdf&nama=&kolek=
 *   GET export.php?laporan=rencana_kunjungan&format=xlsx|pdf&tgl1=&tgl2=&status=&id_user=
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
// LAPORAN: rencana kunjungan
//   export.php?laporan=rencana_kunjungan&format=xlsx|pdf&tgl1=&tgl2=&status=&id_user=
//   AO dipaksa melihat rencananya sendiri; admin/pimpinan boleh memilih AO (kosong = semua).
// ---------------------------------------------------------------------------------------------
function tgl_ok($v): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
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
    $sum  = ['bakidebet' => 0.0, 'tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0,
             'byr_pkk' => 0.0, 'byr_bg' => 0.0, 'krg_pkk' => 0.0, 'krg_bg' => 0.0];      // pokok dan bunga dijumlahkan terpisah
    $tungAda = !empty($d['tung_tersedia']);
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
            'xnunggak'  => $x['xnunggak'] ?? null,
            'tung_pkk'  => $x['tung_pkk_raw'] ?? null,
            'tung_bg'   => $x['tung_bg_raw'] ?? null,
            'kw_pkk'    => (float)($x['kw_pkk_raw'] ?? 0),
            'kw_bg'     => (float)($x['kw_bg_raw'] ?? 0),
            'byr_pkk'   => (float)($x['byr_pkk_raw'] ?? 0),
            'byr_bg'    => (float)($x['byr_bg_raw'] ?? 0),
            'krg_pkk'   => (float)($x['krg_pkk_raw'] ?? 0),
            'krg_bg'    => (float)($x['krg_bg_raw'] ?? 0),
            'status'    => (string)($x['status'] ?? ''),
        ];
        foreach ($sum as $k => $_) $sum[$k] += $row[$k];
        $rows[] = $row;
    }

    if (!$tungAda) unset($sum['tung_pkk'], $sum['tung_bg']);          // jangan tampilkan jumlah 0 untuk kolom yang memang belum ada datanya

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
            ['h' => 'Nama debitur',   'k' => 'nama',  'w' => 20],
            ['h' => 'No. rekening',   'k' => 'norek', 'w' => 13],
            ['h' => 'CIF',            'k' => 'cif',   'w' => 12, 'xlsx' => true],                  // hanya di Excel
            ['h' => 'Alamat',         'k' => 'alamat','w' => 22],
            ['h' => 'AO',             'k' => 'ao',    'w' => 12],
            ['h' => 'Kolek',          'k' => 'kolek', 't' => 'int', 'w' => 6],
            ['h' => 'Hari tunggak',   'k' => 'hari',  't' => 'int', 'w' => 8],
            ['h' => 'Sisa waktu',     'k' => 'sisa',  'w' => 12],
            ['h' => 'Nunggak (x)',          'k' => 'xnunggak',  't' => 'int', 'w' => 7],
            ['h' => 'Baki debet (Rp)',      'k' => 'bakidebet', 't' => 'rp', 'w' => 13],
            ['h' => 'Total tunggakan pokok (Rp)',   'k' => 'tung_pkk', 't' => 'rp', 'w' => 12],
            ['h' => 'Total tunggakan bunga (Rp)',   'k' => 'tung_bg',  't' => 'rp', 'w' => 12],
            ['h' => 'Kewajiban bln ini pokok (Rp)', 'k' => 'kw_pkk',   't' => 'rp', 'w' => 12],
            ['h' => 'Kewajiban bln ini bunga (Rp)', 'k' => 'kw_bg',    't' => 'rp', 'w' => 12],
            ['h' => 'Bayar bln ini pokok (Rp)',     'k' => 'byr_pkk',  't' => 'rp', 'w' => 12],
            ['h' => 'Bayar bln ini bunga (Rp)',     'k' => 'byr_bg',   't' => 'rp', 'w' => 12],
            ['h' => 'Kurang bayar pokok (Rp)',      'k' => 'krg_pkk',  't' => 'rp', 'w' => 12],
            ['h' => 'Kurang bayar bunga (Rp)',      'k' => 'krg_bg',   't' => 'rp', 'w' => 12],
            ['h' => 'Status bayar',   'k' => 'status','w' => 10],
        ],
        'rows'    => $rows,
        'total'   => $terpotong ? null : $sum,
        'catatan' => array_values(array_filter([
            !$tungAda ? 'Total tunggakan dan jumlah nunggak belum tersedia untuk posisi ini (diupload sebelum kolom ttung_pkk, ttung_bg, xnunggak ada).' : null,
            $terpotong ? 'Daftar dibatasi 5.000 baris pertama. Pilih satu AO untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.' : null,
            'Debitur kolek 1-2 dengan hari tunggak minimal ' . $min . ' hari. "Sisa waktu" dihitung dari batas NPL ' . $batas
                . ' hari; "Lewat batas" berarti hari tunggak sudah di atas batas tetapi kolek di data belum berubah. Pokok dan bunga ditampilkan terpisah; kurang bayar = kewajiban bulan ini dikurangi pembayaran bulan ini (tidak negatif). Urut: hari tunggak terbanyak, lalu baki debet terbesar.',
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


function export_rencana_kunjungan(): array
{
    $u      = auth_user() ?? [];
    $idUser = (int)($u['id'] ?? ($u['id_user'] ?? 0));
    $ao     = (string)($u['staff'] ?? '') === 'ao';

    $tgl1 = (string)($_GET['tgl1'] ?? date('Y-m-01'));
    $tgl2 = (string)($_GET['tgl2'] ?? date('Y-m-d'));
    if (!tgl_ok($tgl1) || !tgl_ok($tgl2) || $tgl1 > $tgl2)    export_galat(422, 'Rentang tanggal tidak valid.');
    if (strtotime($tgl2) - strtotime($tgl1) > 92 * 86400)     export_galat(422, 'Rentang tanggal maksimal 3 bulan.');
    $nama_status = ['belum' => 'Belum', 'monitoring whatsapp' => 'Monitoring WA', 'dikunjungi' => 'Dikunjungi'];
    $status  = isset($nama_status[(string)($_GET['status'] ?? '')]) ? (string)$_GET['status'] : '';
    $idPilih = $ao ? $idUser : max(0, (int)($_GET['id_user'] ?? 0));

    $r = api_call('laporan', ['tgl1' => $tgl1, 'tgl2' => $tgl2, 'id_user' => $idPilih > 0 ? (string)$idPilih : '', 'status' => $status], api_cfg()['path_rencana']);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));

    $rows = [];
    $hit  = ['belum' => 0, 'monitoring whatsapp' => 0, 'dikunjungi' => 0];
    $aoNama = '';
    foreach (($r['rows'] ?? []) as $x) {
        $s = (string)($x['status'] ?? '');
        if (isset($hit[$s])) $hit[$s]++;
        $aoNama = $aoNama === '' ? (string)($x['nmuser'] ?? '') : $aoNama;
        $rows[] = [
            'tanggal'    => (string)($x['tanggal'] ?? ''),
            'nama'       => trim((string)($x['nama'] ?? '')),
            'rekening'   => (string)($x['rekening'] ?? ''),
            'nmuser'     => (string)($x['nmuser'] ?? ''),
            'status'     => $nama_status[$s] ?? $s,
            'waktu'      => (string)($x['waktu_kunjungan'] ?? ''),
            'keterangan' => trim((string)($x['keterangan'] ?? '')),
            'ket_kun'    => trim((string)($x['ket_kunjungan'] ?? '')),
        ];
    }
    $fmt = function ($t) { return date('d/m/Y', strtotime($t)); };
    $filter = [];
    if ($status !== '') $filter[] = 'status ' . $nama_status[$status];
    if ($idPilih > 0)   $filter[] = 'AO ' . ($aoNama !== '' ? $aoNama : '#' . $idPilih);

    return [
        'berkas' => 'rencana_kunjungan',
        'judul'  => 'Laporan Rencana Kunjungan',
        'meta'   => [
            'Periode: ' . $fmt($tgl1) . ' s/d ' . $fmt($tgl2) . '   |   Filter: ' . ($filter ? implode('; ', $filter) : 'semua AO, semua status'),
            'Jumlah rencana: ' . count($rows) . '   |   Belum: ' . $hit['belum'] . '   |   Monitoring WA: ' . $hit['monitoring whatsapp'] . '   |   Dikunjungi: ' . $hit['dikunjungi'],
            'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)($u['nama'] ?? '-'),
        ],
        'kolom'  => [
            ['h' => 'No',              't' => 'no',       'w' => 5],
            ['h' => 'Tanggal',         'k' => 'tanggal',  't' => 'date',     'w' => 11],
            ['h' => 'Nama debitur',    'k' => 'nama',     'w' => 24],
            ['h' => 'No. rekening',    'k' => 'rekening', 'w' => 14],
            ['h' => 'AO',              'k' => 'nmuser',   'w' => 16],
            ['h' => 'Status',          'k' => 'status',   'w' => 14],
            ['h' => 'Waktu kunjungan', 'k' => 'waktu',    't' => 'datetime', 'w' => 15],
            ['h' => 'Keterangan rencana', 'k' => 'keterangan', 'w' => 26],
            ['h' => 'Hasil kunjungan', 'k' => 'ket_kun',  'w' => 28],
        ],
        'rows'    => $rows,
        'total'   => null,
        'catatan' => ['Urut: tanggal rencana, lalu nama AO.'],
    ];
}


// ---------------------------------------------------------------------------------------------
// LAPORAN: progres mingguan (rekening belum bayar di posisi tanggal awal, dilacak tiap minggu)
// Sumber: jenis_req 'progres' (endpoint Monitoring).
//   PDF   -> ringkasan per minggu (jenis 'progres'); dengan &minggu=N -> PDF satu minggu: ringkasan minggu itu,
//            daftar yang bayar di minggu itu, lalu daftar yang belum bayar sampai akhir minggu itu (jenis 'progres_detail').
//   PDF + &daftar=1 (+ nama, status, urut) -> daftar debitur kelompok awal, seluruh baris, sama seperti daftar di halaman.
//   Excel -> rincian per debitur dengan kolom pembayaran tiap minggu (jenis 'progres_detail').
// ---------------------------------------------------------------------------------------------
function export_progres(): array
{
    $awal  = trim((string)($_GET['awal'] ?? ''));
    $akhir = trim((string)($_GET['akhir'] ?? ''));
    $ao    = trim((string)($_GET['ao'] ?? ''));
    $dasar = trim((string)($_GET['dasar'] ?? ''));
    $pdf   = (string)($_GET['format'] ?? 'xlsx') === 'pdf';
    $minggu = (int)($_GET['minggu'] ?? 0);                       // 0 = semua minggu (ringkasan); 1-6 = PDF satu minggu
    if ($minggu < 0 || $minggu > 6)                             export_galat(422, 'Minggu tidak valid.');
    $daftar = $pdf && (string)($_GET['daftar'] ?? '') === '1';    // PDF daftar debitur kelompok awal (mengikuti pencarian, status, dan urutan di halaman)
    $perMinggu = $pdf && $minggu > 0 && !$daftar;
    $nama   = trim((string)($_GET['nama'] ?? ''));
    $status = trim((string)($_GET['status'] ?? ''));
    $urut   = trim((string)($_GET['urut'] ?? ''));
    $petaStatus = ['belum' => 'Belum bayar', 'sebagian' => 'Sebagian', 'sudah' => 'Sudah bayar', 'tanpa' => 'Tanpa kewajiban bln ini',
                   'hilang' => 'Kredit lunas', 'tlunas' => 'Tunggakan lunas', 'tturun' => 'Tunggakan turun', 'ttetap' => 'Tunggakan tetap',
                   'tnaik' => 'Tunggakan naik'];
    if ($status !== '' && !isset($petaStatus[$status]))                                       export_galat(422, 'Status tidak valid.');
    if ($nama !== '' && mb_strlen($nama) < 2)                                                  export_galat(422, 'Nama atau nomor rekening minimal 2 karakter.');
    if (mb_strlen($nama) > 40) $nama = mb_substr($nama, 0, 40);
    if ($urut !== '' && !in_array($urut, ['hari', 'tgl'], true))                              export_galat(422, 'Urutan tidak valid.');
    if ($dasar !== '' && !in_array($dasar, ['keduanya', 'kewajiban', 'tunggakan'], true))      export_galat(422, 'Dasar kelompok tidak valid.');

    $tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
    if (($awal === '') !== ($akhir === ''))                     export_galat(422, 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.');
    if ($awal !== '' && (!$tglOk($awal) || !$tglOk($akhir)))    export_galat(422, 'Tanggal tidak valid.');
    if ($awal !== '' && $akhir < $awal)                         export_galat(422, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
    if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) export_galat(422, 'Kode AO tidak valid.');

    $p = [];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao'] = $ao;
    if ($dasar !== '') $p['dasar'] = $dasar;
    if ($daftar) {                                   // disaring dan diurutkan di backend, sama seperti daftar di halaman
        if ($nama !== '')   $p['nama']   = $nama;
        if ($status !== '') $p['status'] = $status;
        if ($urut !== '')   $p['urut']   = $urut;
    }

    $r = api_call(($pdf && !$perMinggu && !$daftar) ? 'progres' : 'progres_detail', ['params' => $p], api_cfg()['path_monitoring'], 120);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));

    $a     = (array)$d['awal'];
    $mg    = (array)($d['minggu'] ?? []);
    $tgOk  = !empty($d['tung_tersedia']);
    $dasarPakai = (string)($d['sel']['dasar'] ?? 'kewajiban');
    $fmt   = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m/Y', $x); };
    $pend  = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m', $x); };
    $persen = function ($v): string { return $v === null ? '–' : number_format((float)$v, 1, ',', '.') . '%'; };
    $n0    = function ($v): string { return number_format((float)$v, 0, ',', '.'); };
    $lblAlasan = ['kewajiban' => 'Belum bayar bln ini', 'tunggakan' => 'Menunggak', 'keduanya' => 'Belum bayar + menunggak'];
    $lblDasar  = ['keduanya' => 'Kewajiban bulan ini atau tunggakan', 'kewajiban' => 'Kewajiban bulan ini (belum bayar)', 'tunggakan' => 'Total tunggakan'];
    // status akhir satu debitur, lengkap dengan tanggal lunas
    $statKw = function (array $x): string {
        $s = (string)($x['status'] ?? '');
        if (!empty($x['tgl_lunas'])) $s .= ' (' . date('d/m/Y', strtotime((string)$x['tgl_lunas'])) . ')';
        elseif (!empty($x['tanpa_kw']) && $s !== 'Kredit lunas') $s .= ' · tanpa kewajiban bln ini';
        return $s;
    };
    $namaAo = 'Semua AO';
    if (($d['sel']['ao'] ?? '') !== '') {
        $namaAo = (string)$d['sel']['ao'];
        foreach (($d['aos'] ?? []) as $x) if ((string)($x['v'] ?? '') === $namaAo) { $namaAo = (string)$x['l']; break; }
    }

    $metaDasar = [
        'Periode tagihan: ' . (string)$d['periode'] . '   |   Rentang: ' . $fmt($d['sel']['awal']) . ' s/d ' . $fmt($d['sel']['akhir'])
            . '   |   AO: ' . $namaAo . '   |   Kelompok awal dari posisi ' . $fmt($d['posisi_awal']),
        'Dasar kelompok: ' . ($lblDasar[$dasarPakai] ?? $dasarPakai) . '   |   Rekening di kelompok awal: ' . rp_teks($a['rek'])
            . ' (belum bayar ' . rp_teks($a['rek_kewajiban']) . ', menunggak ' . rp_teks($a['rek_tunggakan']) . ', keduanya ' . rp_teks($a['rek_keduanya']) . ')'
            . '   |   Baki debet: Rp ' . rp_teks($a['baki']),
        ($tgOk ? 'Total tunggakan: pokok Rp ' . rp_teks($a['tung_pkk']) . ', bunga Rp ' . rp_teks($a['tung_bg']) . '   |   ' : '')
            . 'Kewajiban bulan ini: pokok Rp ' . rp_teks($a['kw_pkk']) . ', bunga Rp ' . rp_teks($a['kw_bg']) . '   (tidak dijumlahkan dengan tunggakan)',
    ];
    $metaMgg = [];
    if (!$pdf) {                                   // Excel: tabelnya rincian debitur, jadi ringkasan mingguan ditaruh di keterangan
        foreach ($mg as $m) {
            $metaMgg[] = 'Minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . ', posisi ' . $pend($m['tgl_posisi']) . '): bayar pokok Rp '
                . rp_teks($m['bayar_pkk']) . ', bunga Rp ' . rp_teks($m['bayar_bg']) . '   |   tertagih pokok ' . $persen($m['pct_pkk']) . ', bunga ' . $persen($m['pct_bg'])
                . '   |   sudah ada bayar ' . rp_teks($m['rek_bayar']) . ' rek, belum ' . rp_teks($m['rek_belum']) . ' rek'
                . ($m['rek_hilang'] ? ', kredit lunas ' . rp_teks($m['rek_hilang']) . ' rek' : '');
        }
    }
    $metaCetak = 'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)((auth_user() ?? [])['nama'] ?? '-');
    $susunMeta = function (array $sisip = []) use ($metaDasar, $metaMgg, $metaCetak): array {
        return array_merge($metaDasar, $sisip, $metaMgg, [$metaCetak]);
    };

    $catatan = array_map('strval', (array)($d['peringatan'] ?? []));
    $catatan[] = 'Kelompok awal menurut dasar yang dipilih: "kewajiban bulan ini" = ada kewajiban bulan ini dan belum ada pembayaran di posisi awal; '
        . '"total tunggakan" = tunggakan pokok atau bunga di atas nol (termasuk kredit yang sudah jatuh tempo). '
        . 'Kewajiban bulan ini dan tunggakan TIDAK dijumlahkan karena tanggal posisi dan jadwal angsuran bisa membuat tunggakan sudah atau belum memuat angsuran bulan ini; keduanya ditampilkan sendiri-sendiri, pokok dan bunga terpisah.';
    $catatan[] = 'Pembayaran tiap minggu = selisih pembayaran bulan ini antara dua posisi. "Tertagih" membandingkan pembayaran dengan kewajiban bulan ini (maksimal sebesar kewajiban tiap rekening). '
        . 'Kredit lunas = rekening yang ada di posisi awal tetapi tidak lagi ada di nominatif (nominatif hanya memuat debitur yang masih punya baki), dicek per tanggal; '
        . 'tanggal lunas = tanggal pertama rekening tidak muncul. Pembayaran pelunasan di hari rekening keluar tidak terlihat di angka bayar, dan hapus buku juga terhitung lunas.';

    // ---------------------------- PDF daftar debitur kelompok awal (seperti daftar di halaman, seluruh baris) ----------------------------
    if ($daftar) {
        $terpotong = !empty($d['terpotong']);
        $rows = [];
        $sum  = ['tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0, 'tung_pkk_akhir' => 0.0, 'tung_bg_akhir' => 0.0];
        foreach (($d['rows'] ?? []) as $x) {
            $row = [
                'nama' => trim((string)($x['nama'] ?? '')), 'norek' => (string)($x['norek'] ?? ''), 'ao' => (string)($x['ao'] ?? ''),
                'alasan' => $lblAlasan[$x['alasan'] ?? ''] ?? (string)($x['alasan'] ?? ''),
                'kolek' => (string)($x['kolek'] ?? '') . (($x['kolek_akhir'] ?? null) === null ? '' : ' → ' . $x['kolek_akhir']),
                'hari'  => (string)($x['hari'] ?? '')  . (($x['hari_akhir'] ?? null) === null ? '' : ' → ' . $x['hari_akhir']),
                'xnunggak' => $x['xnunggak'] ?? null, 'tgl_jt' => $x['tgl_jt'] ?? null,
                'tung_pkk' => $x['tung_pkk'] ?? null, 'tung_bg' => $x['tung_bg'] ?? null,
                'kw_pkk' => (float)($x['kw_pkk'] ?? 0), 'kw_bg' => (float)($x['kw_bg'] ?? 0),
                'kurang' => $n0($x['kurang_pkk'] ?? 0) . ' / ' . $n0($x['kurang_bg'] ?? 0),
                'tung_pkk_akhir' => $x['tung_pkk_akhir'] ?? null, 'tung_bg_akhir' => $x['tung_bg_akhir'] ?? null,
                'status' => $statKw($x), 'status_tung' => (string)($x['status_tung'] ?? ''),
            ];
            foreach ((array)($x['minggu'] ?? []) as $i => $w) {
                $row['m' . ($i + 1)] = ((float)($w['bayar_pkk'] ?? 0) > 0 || (float)($w['bayar_bg'] ?? 0) > 0)
                    ? $n0($w['bayar_pkk'] ?? 0) . ' / ' . $n0($w['bayar_bg'] ?? 0) : '–';
            }
            foreach ($sum as $k => $_) $sum[$k] += (float)($row[$k] ?? 0);
            $rows[] = $row;
        }

        $kolom = [
            ['h' => 'No',            't' => 'no',   'w' => 4],
            ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 19],
            ['h' => 'No. rekening',  'k' => 'norek','w' => 12],
            ['h' => 'AO',            'k' => 'ao',   'w' => 10],
            ['h' => 'Alasan masuk',  'k' => 'alasan', 'w' => 10],
            ['h' => 'Kolek (awal → akhir)',        'k' => 'kolek', 'w' => 6],
            ['h' => 'Hari tunggak (awal → akhir)', 'k' => 'hari',  'w' => 8],
            ['h' => 'Nunggak (x)',                 'k' => 'xnunggak', 't' => 'int', 'w' => 5],
            ['h' => 'Tgl jatuh tempo',             'k' => 'tgl_jt',   't' => 'int', 'w' => 5],
        ];
        if ($tgOk) {
            $kolom[] = ['h' => 'Tunggakan awal pokok (Rp)', 'k' => 'tung_pkk', 't' => 'rp', 'w' => 10];
            $kolom[] = ['h' => 'Tunggakan awal bunga (Rp)', 'k' => 'tung_bg',  't' => 'rp', 'w' => 10];
        }
        $kolom[] = ['h' => 'Kewajiban bln ini pokok (Rp)', 'k' => 'kw_pkk', 't' => 'rp', 'w' => 10];
        $kolom[] = ['h' => 'Kewajiban bln ini bunga (Rp)', 'k' => 'kw_bg',  't' => 'rp', 'w' => 10];
        foreach ($mg as $i => $m) {
            $kolom[] = ['h' => 'Bayar minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . ') pokok / bunga', 'k' => 'm' . ($i + 1), 'w' => 12];
        }
        $kolom[] = ['h' => 'Kurang kewajiban pokok / bunga', 'k' => 'kurang', 'w' => 12];
        if ($tgOk) {
            $kolom[] = ['h' => 'Sisa tunggakan pokok (Rp)', 'k' => 'tung_pkk_akhir', 't' => 'rp', 'w' => 10];
            $kolom[] = ['h' => 'Sisa tunggakan bunga (Rp)', 'k' => 'tung_bg_akhir',  't' => 'rp', 'w' => 10];
        }
        $kolom[] = ['h' => 'Status kewajiban', 'k' => 'status', 'w' => 11];
        if ($tgOk) $kolom[] = ['h' => 'Status tunggakan', 'k' => 'status_tung', 'w' => 8];
        if (!$tgOk) unset($sum['tung_pkk'], $sum['tung_bg'], $sum['tung_pkk_akhir'], $sum['tung_bg_akhir']);

        $ket = ['Urut: ' . ($urut === 'tgl' ? 'tanggal jatuh tempo (1–31)' : 'hari tunggak terbanyak')];
        if ($nama !== '')   array_unshift($ket, 'Pencarian: ' . $nama);
        if ($status !== '') array_unshift($ket, 'Status: ' . $petaStatus[$status]);
        $meta = $susunMeta(['Daftar: ' . rp_teks(count($rows)) . ' debitur   |   ' . implode('   |   ', $ket)]);

        $cat = $catatan;
        array_unshift($cat, 'Kolom minggu = pembayaran yang masuk pada minggu itu (pokok / bunga). Tanda panah menunjukkan kondisi di posisi awal lalu di posisi terakhir. Tgl jatuh tempo = hanya harinya (1–31) dari tanggal jatuh tempo kredit.');
        if ($terpotong) array_unshift($cat, 'Rincian dibatasi ' . rp_teks(count((array)($d['rows'] ?? []))) . ' baris pertama dari ' . rp_teks($a['rek']) . ' rekening. Pilih satu AO untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.');

        return [
            'berkas'  => 'progres_daftar_debitur',
            'judul'   => 'Daftar Debitur Kelompok Awal - Progres Mingguan',
            'meta'    => $meta,
            'kolom'   => $kolom,
            'rows'    => $rows,
            'total'   => $terpotong ? null : $sum,
            'catatan' => $cat,
        ];
    }

    // ---------------------------- PDF: ringkasan per minggu ----------------------------
    if ($pdf) {
        $rows = []; $sP = 0.0; $sB = 0.0;
        $mgPakai = $mg;
        if ($perMinggu) {                                      // PDF satu minggu: tabel ringkasan hanya berisi minggu itu
            $mgPakai = [];
            foreach ($mg as $m) if ((int)$m['no'] === $minggu) $mgPakai[] = $m;
            if (!$mgPakai) export_galat(422, 'Minggu ' . $minggu . ' tidak ada pada rentang tanggal ini.');
        }
        foreach ($mgPakai as $m) {
            $sP += (float)$m['bayar_pkk']; $sB += (float)$m['bayar_bg'];
            $rows[] = [
                'mgg' => 'Minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . '), posisi ' . $pend($m['tgl_posisi']),
                'bayar_pkk' => (float)$m['bayar_pkk'], 'bayar_bg' => (float)$m['bayar_bg'],
                'pct_pkk' => $persen($m['pct_pkk']), 'pct_bg' => $persen($m['pct_bg']),
                'rek_bayar' => $m['rek_bayar'], 'rek_lunas' => $m['rek_lunas'], 'rek_belum' => $m['rek_belum'], 'rek_hilang' => $m['rek_hilang'],
                'kurang_pkk' => (float)$m['kurang_pkk'], 'kurang_bg' => (float)$m['kurang_bg'],
                'sisa_tung_pkk' => $m['sisa_tung_pkk'], 'sisa_tung_bg' => $m['sisa_tung_bg'],
                'ubah_tung' => $tgOk ? 'Lunas ' . $m['rek_tung_nol'] . ' · Turun ' . $m['rek_tung_turun'] . ' · Tetap ' . $m['rek_tung_tetap'] . ' · Naik ' . $m['rek_tung_naik'] : '',
                'kolek_memburuk' => $m['kolek_memburuk'], 'masuk_npl' => $m['masuk_npl'],
            ];
        }
        $kolom = [
            ['h' => 'Minggu',                       'k' => 'mgg',        'w' => 20],
            ['h' => 'Bayar pokok (Rp)',             'k' => 'bayar_pkk',  't' => 'rp',  'w' => 11],
            ['h' => 'Bayar bunga (Rp)',             'k' => 'bayar_bg',   't' => 'rp',  'w' => 11],
            ['h' => 'Tertagih pokok',               'k' => 'pct_pkk',    'w' => 7],
            ['h' => 'Tertagih bunga',               'k' => 'pct_bg',     'w' => 7],
            ['h' => 'Sudah ada bayar (rek)',        'k' => 'rek_bayar',  't' => 'int', 'w' => 7],
            ['h' => 'Kewajiban terpenuhi (rek)',    'k' => 'rek_lunas',  't' => 'int', 'w' => 8],
            ['h' => 'Belum ada bayar (rek)',        'k' => 'rek_belum',  't' => 'int', 'w' => 7],
            ['h' => 'Kredit lunas (rek)',           'k' => 'rek_hilang', 't' => 'int', 'w' => 7],
            ['h' => 'Kurang kewajiban pokok (Rp)',  'k' => 'kurang_pkk', 't' => 'rp',  'w' => 11],
            ['h' => 'Kurang kewajiban bunga (Rp)',  'k' => 'kurang_bg',  't' => 'rp',  'w' => 11],
        ];
        if ($tgOk) {
            $kolom[] = ['h' => 'Sisa tunggakan pokok (Rp)', 'k' => 'sisa_tung_pkk', 't' => 'rp', 'w' => 11];
            $kolom[] = ['h' => 'Sisa tunggakan bunga (Rp)', 'k' => 'sisa_tung_bg',  't' => 'rp', 'w' => 11];
            $kolom[] = ['h' => 'Perubahan tunggakan (rek)', 'k' => 'ubah_tung', 'w' => 16];
        }
        $kolom[] = ['h' => 'Kolek memburuk', 'k' => 'kolek_memburuk', 't' => 'int', 'w' => 6];
        $kolom[] = ['h' => 'Masuk NPL',      'k' => 'masuk_npl',      't' => 'int', 'w' => 6];

        if ($perMinggu) {
            // rincian debitur untuk minggu ini; $x['minggu'][$idx] = ['bayar_pkk','bayar_bg' => masuk minggu ini, 'kum_pkk','kum_bg' => kumulatif, 'ada' => masih ada di nominatif]
            $idx = $minggu - 1;
            $mm  = $mgPakai[0];
            $bayar = []; $belum = []; $lunas = [];
            $sBy = ['kw_pkk' => 0.0, 'kw_bg' => 0.0, 'bayar_pkk' => 0.0, 'bayar_bg' => 0.0, 'kum_pkk' => 0.0, 'kum_bg' => 0.0];
            $sBl = ['tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0];
            $sLn = ['tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0];
            foreach (($d['rows'] ?? []) as $x) {
                $mx = (array)($x['minggu'] ?? []);
                $w  = $mx[$idx] ?? null;
                if (!is_array($w)) continue;
                $kwP = (float)($x['kw_pkk'] ?? 0); $kwB = (float)($x['kw_bg'] ?? 0);
                $byP = (float)($w['bayar_pkk'] ?? 0); $byB = (float)($w['bayar_bg'] ?? 0);
                $kuP = (float)($w['kum_pkk'] ?? 0);   $kuB = (float)($w['kum_bg'] ?? 0);
                $dasarRow = [
                    'nama' => trim((string)($x['nama'] ?? '')), 'norek' => (string)($x['norek'] ?? ''), 'ao' => (string)($x['ao'] ?? ''),
                    'alasan' => $lblAlasan[$x['alasan'] ?? ''] ?? (string)($x['alasan'] ?? ''),
                    'kolek' => $x['kolek'] ?? null, 'hari' => $x['hari'] ?? null, 'tgl_jt' => $x['tgl_jt'] ?? null,
                    'tung_pkk' => $x['tung_pkk'] ?? null, 'tung_bg' => $x['tung_bg'] ?? null, 'kw_pkk' => $kwP, 'kw_bg' => $kwB,
                ];
                if (empty($w['ada'])) {                         // tidak ada di nominatif minggu ini
                    $sebelumnya = $idx > 0 ? ($mx[$idx - 1] ?? null) : ['ada' => true];
                    if (is_array($sebelumnya) && !empty($sebelumnya['ada'])) {      // baru keluar di minggu ini = lunas di minggu ini
                        $lunas[] = $dasarRow + ['tgl_lunas' => !empty($x['tgl_lunas']) ? $fmt($x['tgl_lunas']) : '–'];
                        $sLn['tung_pkk'] += (float)($x['tung_pkk'] ?? 0); $sLn['tung_bg'] += (float)($x['tung_bg'] ?? 0);
                        $sLn['kw_pkk'] += $kwP; $sLn['kw_bg'] += $kwB;
                    }
                    continue;
                }
                if ($byP > 0 || $byB > 0) {                     // ada pembayaran masuk di minggu ini
                    $st = ($kwP + $kwB <= 0) ? 'Ada pembayaran (tanpa kewajiban bln ini)'
                        : ((max(0.0, $kwP - $kuP) <= 0.5 && max(0.0, $kwB - $kuB) <= 0.5) ? 'Kewajiban terpenuhi' : 'Sebagian');
                    $bayar[] = $dasarRow + ['bayar_pkk' => $byP, 'bayar_bg' => $byB, 'kum_pkk' => $kuP, 'kum_bg' => $kuB, 'status' => $st];
                    $sBy['kw_pkk'] += $kwP; $sBy['kw_bg'] += $kwB; $sBy['bayar_pkk'] += $byP; $sBy['bayar_bg'] += $byB;
                    $sBy['kum_pkk'] += $kuP; $sBy['kum_bg'] += $kuB;
                } elseif ($kuP + $kuB <= 0) {                   // masih ada di nominatif, tetapi sampai akhir minggu ini belum bayar sama sekali
                    $belum[] = $dasarRow;
                    $sBl['tung_pkk'] += (float)($x['tung_pkk'] ?? 0); $sBl['tung_bg'] += (float)($x['tung_bg'] ?? 0);
                    $sBl['kw_pkk'] += $kwP; $sBl['kw_bg'] += $kwB;
                }
            }

            $kolId = [
                ['h' => 'No',            't' => 'no',   'w' => 4],
                ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 20],
                ['h' => 'No. rekening',  'k' => 'norek','w' => 12],
                ['h' => 'AO',            'k' => 'ao',   'w' => 12],
                ['h' => 'Alasan masuk',  'k' => 'alasan', 'w' => 11],
                ['h' => 'Kolek awal',    'k' => 'kolek','t' => 'int', 'w' => 5],
                ['h' => 'Hari tunggak awal', 'k' => 'hari', 't' => 'int', 'w' => 7],
                ['h' => 'Tgl jatuh tempo',   'k' => 'tgl_jt', 't' => 'int', 'w' => 5],
            ];
            $kolTung = $tgOk ? [
                ['h' => 'Tunggakan awal pokok (Rp)', 'k' => 'tung_pkk', 't' => 'rp', 'w' => 11],
                ['h' => 'Tunggakan awal bunga (Rp)', 'k' => 'tung_bg',  't' => 'rp', 'w' => 11],
            ] : [];
            $kolKw = [
                ['h' => 'Kewajiban bln ini pokok (Rp)', 'k' => 'kw_pkk', 't' => 'rp', 'w' => 11],
                ['h' => 'Kewajiban bln ini bunga (Rp)', 'k' => 'kw_bg',  't' => 'rp', 'w' => 11],
            ];
            $kolBayar = array_merge($kolId, $kolTung, $kolKw, [
                ['h' => 'Bayar minggu ini pokok (Rp)',      'k' => 'bayar_pkk', 't' => 'rp', 'w' => 11],
                ['h' => 'Bayar minggu ini bunga (Rp)',      'k' => 'bayar_bg',  't' => 'rp', 'w' => 11],
                ['h' => 'Total bayar s/d minggu ini pokok (Rp)', 'k' => 'kum_pkk', 't' => 'rp', 'w' => 12],
                ['h' => 'Total bayar s/d minggu ini bunga (Rp)', 'k' => 'kum_bg',  't' => 'rp', 'w' => 12],
                ['h' => 'Status',                            'k' => 'status', 'w' => 12],
            ]);
            $kolBelum = array_merge($kolId, $kolTung, $kolKw);
            $kolLunas = array_merge($kolId, [['h' => 'Tanggal lunas', 'k' => 'tgl_lunas', 'w' => 9]], $kolTung, $kolKw);
            if (!$tgOk) {
                unset($sBl['tung_pkk'], $sBl['tung_bg'], $sLn['tung_pkk'], $sLn['tung_bg']);
            }

            $rentang = $pend($mm['dari']) . '–' . $pend($mm['sampai']);
            $catMgg  = [];
            if (!empty($d['terpotong'])) $catMgg[] = 'Rincian debitur dibatasi ' . rp_teks(count((array)($d['rows'] ?? []))) . ' rekening pertama dari ' . rp_teks($a['rek']) . '. Pilih satu AO agar daftar lengkap; baris JUMLAH pada daftar tidak mencakup rekening yang terpotong.';
            $catMgg[] = 'Yang bayar = rekening kelompok awal yang ada pembayaran masuk pada minggu ini (selisih pembayaran bulan ini dibanding posisi sebelumnya). '
                . 'Belum bayar = rekening yang masih ada di nominatif tetapi sampai akhir minggu ini belum ada pembayaran pokok maupun bunga (termasuk yang tanpa kewajiban bulan ini, misalnya kredit jatuh tempo). '
                . 'Kredit lunas = rekening yang keluar dari nominatif pada minggu ini. '
                . 'Rekening yang sudah membayar pada minggu sebelumnya dan tidak ada pembayaran baru minggu ini tidak masuk daftar bayar maupun belum bayar.';

            $meta = $susunMeta(['Minggu ' . $minggu . ' (' . $rentang . ', posisi ' . $fmt($mm['tgl_posisi']) . ')   |   Yang bayar: ' . rp_teks(count($bayar))
                . ' rekening   |   Belum bayar: ' . rp_teks(count($belum)) . ' rekening   |   Kredit lunas minggu ini: ' . rp_teks(count($lunas)) . ' rekening']);

            return [
                'berkas'  => 'progres_minggu_' . $minggu,
                'judul'   => 'Progres Mingguan Tunggakan dan Gagal Bayar - Minggu ' . $minggu . ' (' . $rentang . ')',
                'meta'    => $meta,
                'kolom'   => $kolom,
                'rows'    => array_merge($bayar, $belum, $lunas),      // hanya untuk pemeriksaan batas baris PDF; tabelnya diambil dari 'bagian'
                'bagian'  => [
                    ['judul' => '1. Ringkasan minggu ' . $minggu, 'kolom' => $kolom, 'rows' => $rows, 'total' => null],
                    ['judul' => '2. Rincian yang bayar di minggu ' . $minggu . ' (' . count($bayar) . ' rekening)',
                        'kolom' => $kolBayar, 'rows' => $bayar, 'total' => $sBy, 'kosong' => 'Tidak ada rekening yang membayar pada minggu ini.'],
                    ['judul' => '3. Rincian yang belum bayar sampai akhir minggu ' . $minggu . ' (' . count($belum) . ' rekening)',
                        'kolom' => $kolBelum, 'rows' => $belum, 'total' => $sBl, 'kosong' => 'Semua rekening yang masih ada di nominatif sudah ada pembayaran.'],
                    ['judul' => '4. Kredit lunas di minggu ' . $minggu . ' (keluar dari nominatif, ' . count($lunas) . ' rekening)',
                        'kolom' => $kolLunas, 'rows' => $lunas, 'total' => $sLn, 'kosong' => 'Tidak ada kredit yang lunas pada minggu ini.'],
                ],
                'catatan' => array_merge($catMgg, $catatan),
            ];
        }

        return [
            'berkas'  => 'progres_mingguan',
            'judul'   => 'Progres Mingguan Tunggakan dan Gagal Bayar',
            'meta'    => $susunMeta(),
            'kolom'   => $kolom,
            'rows'    => $rows,
            'total'   => ['bayar_pkk' => $sP, 'bayar_bg' => $sB],
            'catatan' => $catatan,
        ];
    }

    // ---------------------------- Excel: rincian per debitur ----------------------------
    $terpotong = !empty($d['terpotong']);
    $rows = [];
    $sum  = ['tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0, 'bayar_pkk' => 0.0, 'bayar_bg' => 0.0,
             'tung_pkk_akhir' => 0.0, 'tung_bg_akhir' => 0.0, 'kurang_pkk' => 0.0, 'kurang_bg' => 0.0];
    foreach ($mg as $i => $_) { $sum['mp' . ($i + 1)] = 0.0; $sum['mb' . ($i + 1)] = 0.0; }

    foreach (($d['rows'] ?? []) as $x) {
        $row = [
            'nama' => trim((string)($x['nama'] ?? '')), 'norek' => (string)($x['norek'] ?? ''), 'cif' => (string)($x['cif'] ?? ''),
            'ao' => (string)($x['ao'] ?? ''), 'alasan' => $lblAlasan[$x['alasan'] ?? ''] ?? (string)($x['alasan'] ?? ''),
            'kolek' => $x['kolek'] ?? null, 'hari' => $x['hari'] ?? null, 'xnunggak' => $x['xnunggak'] ?? null,
            'tempo' => !empty($x['tempo']) ? $fmt($x['tempo']) : '', 'tgl_jt' => $x['tgl_jt'] ?? null,
            'tung_pkk' => $x['tung_pkk'] ?? null, 'tung_bg' => $x['tung_bg'] ?? null,
            'kw_pkk' => (float)($x['kw_pkk'] ?? 0), 'kw_bg' => (float)($x['kw_bg'] ?? 0),
            'bayar_pkk' => (float)($x['bayar_pkk'] ?? 0), 'bayar_bg' => (float)($x['bayar_bg'] ?? 0),
            'kolek_akhir' => $x['kolek_akhir'] ?? null, 'hari_akhir' => $x['hari_akhir'] ?? null,
            'tung_pkk_akhir' => $x['tung_pkk_akhir'] ?? null, 'tung_bg_akhir' => $x['tung_bg_akhir'] ?? null,
            'kurang_pkk' => (float)($x['kurang_pkk'] ?? 0), 'kurang_bg' => (float)($x['kurang_bg'] ?? 0),
            'status' => $statKw($x), 'status_tung' => (string)($x['status_tung'] ?? ''),
            'tgl_lunas' => !empty($x['tgl_lunas']) ? $fmt($x['tgl_lunas']) : '',
        ];
        foreach ((array)($x['minggu'] ?? []) as $i => $w) {
            $row['mp' . ($i + 1)] = (float)($w['bayar_pkk'] ?? 0);
            $row['mb' . ($i + 1)] = (float)($w['bayar_bg'] ?? 0);
        }
        foreach ($sum as $k => $_) $sum[$k] += (float)($row[$k] ?? 0);
        $rows[] = $row;
    }

    $kolom = [
        ['h' => 'No',            't' => 'no',   'w' => 5],
        ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 24],
        ['h' => 'No. rekening',  'k' => 'norek','w' => 14],
        ['h' => 'CIF',           'k' => 'cif',  'w' => 12],
        ['h' => 'AO',            'k' => 'ao',   'w' => 16],
        ['h' => 'Alasan masuk',  'k' => 'alasan', 'w' => 22],
        ['h' => 'Kolek awal',    'k' => 'kolek','t' => 'int', 'w' => 7],
        ['h' => 'Hari tunggak awal', 'k' => 'hari', 't' => 'int', 'w' => 9],
        ['h' => 'Nunggak (x)',   'k' => 'xnunggak', 't' => 'int', 'w' => 8],
        ['h' => 'Tanggal jatuh tempo', 'k' => 'tempo', 'w' => 13],
        ['h' => 'Hari jatuh tempo (1-31)', 'k' => 'tgl_jt', 't' => 'int', 'w' => 10],
    ];
    if ($tgOk) {
        $kolom[] = ['h' => 'Tunggakan awal pokok (Rp)', 'k' => 'tung_pkk', 't' => 'rp', 'w' => 15];
        $kolom[] = ['h' => 'Tunggakan awal bunga (Rp)', 'k' => 'tung_bg',  't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Kewajiban bulan ini pokok (Rp)', 'k' => 'kw_pkk', 't' => 'rp', 'w' => 16];
    $kolom[] = ['h' => 'Kewajiban bulan ini bunga (Rp)', 'k' => 'kw_bg',  't' => 'rp', 'w' => 16];
    foreach ($mg as $i => $m) {
        $ket = $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . ')';
        $kolom[] = ['h' => 'Bayar minggu ' . $ket . ' pokok (Rp)', 'k' => 'mp' . ($i + 1), 't' => 'rp', 'w' => 15];
        $kolom[] = ['h' => 'Bayar minggu ' . $ket . ' bunga (Rp)', 'k' => 'mb' . ($i + 1), 't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Total bayar pokok (Rp)', 'k' => 'bayar_pkk', 't' => 'rp', 'w' => 15];
    $kolom[] = ['h' => 'Total bayar bunga (Rp)', 'k' => 'bayar_bg',  't' => 'rp', 'w' => 15];
    $kolom[] = ['h' => 'Kolek akhir',       'k' => 'kolek_akhir', 't' => 'int', 'w' => 7];
    $kolom[] = ['h' => 'Hari tunggak akhir','k' => 'hari_akhir',  't' => 'int', 'w' => 9];
    if ($tgOk) {
        $kolom[] = ['h' => 'Sisa tunggakan pokok (Rp)', 'k' => 'tung_pkk_akhir', 't' => 'rp', 'w' => 15];
        $kolom[] = ['h' => 'Sisa tunggakan bunga (Rp)', 'k' => 'tung_bg_akhir',  't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Kurang kewajiban pokok (Rp)', 'k' => 'kurang_pkk', 't' => 'rp', 'w' => 15];
    $kolom[] = ['h' => 'Kurang kewajiban bunga (Rp)', 'k' => 'kurang_bg',  't' => 'rp', 'w' => 15];
    $kolom[] = ['h' => 'Status kewajiban', 'k' => 'status', 'w' => 26];
    if ($tgOk) $kolom[] = ['h' => 'Status tunggakan', 'k' => 'status_tung', 'w' => 16];
    $kolom[] = ['h' => 'Tanggal lunas (keluar dari nominatif)', 'k' => 'tgl_lunas', 'w' => 16];

    if ($terpotong) {
        array_unshift($catatan, 'Rincian dibatasi ' . rp_teks(count($rows)) . ' baris pertama dari ' . rp_teks($a['rek']) . ' rekening. Pilih satu AO untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.');
    }
    if (!$tgOk) unset($sum['tung_pkk'], $sum['tung_bg'], $sum['tung_pkk_akhir'], $sum['tung_bg_akhir']);

    return [
        'berkas'  => 'progres_mingguan_rincian',
        'judul'   => 'Progres Mingguan Tunggakan dan Gagal Bayar (Rincian Debitur)',
        'meta'    => $susunMeta(),
        'kolom'   => $kolom,
        'rows'    => $rows,
        'total'   => $terpotong ? null : $sum,
        'catatan' => $catatan,
    ];
}

// ---------------------------------------------------------------------------------------------
// DAFTAR LAPORAN: kode menu (hak akses), batas baris PDF, fungsi pembangun
// ---------------------------------------------------------------------------------------------
$LAPORAN = [
    'belum_bayar'       => ['menu' => 'web_belum_bayar', 'batas_pdf' => 1500, 'bangun' => 'export_belum_bayar'],
    'rencana_kunjungan' => ['menu' => 'web_rencana',     'batas_pdf' => 1500, 'bangun' => 'export_rencana_kunjungan'],
    'prioritas'         => ['menu' => 'web_prioritas',   'batas_pdf' => 1500, 'bangun' => 'export_prioritas'],
    'lap_absensi'       => ['menu' => 'web_lap_absensi', 'batas_pdf' => 1500, 'bangun' => 'export_absensi'],
    'progres'           => ['menu' => 'web_progres',     'batas_pdf' => 1500, 'bangun' => 'export_progres'],
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