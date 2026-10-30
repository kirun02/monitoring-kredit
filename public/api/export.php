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
//   PDF   -> ringkasan per minggu (jenis 'progres').
//   Excel -> rincian per debitur dengan kolom pembayaran tiap minggu (jenis 'progres_detail').
// ---------------------------------------------------------------------------------------------
function export_progres(): array
{
    $awal  = trim((string)($_GET['awal'] ?? ''));
    $akhir = trim((string)($_GET['akhir'] ?? ''));
    $ao    = trim((string)($_GET['ao'] ?? ''));
    $pdf   = (string)($_GET['format'] ?? 'xlsx') === 'pdf';

    $tglOk = function (string $t): bool { $d = DateTime::createFromFormat('Y-m-d', $t); return $d && $d->format('Y-m-d') === $t; };
    if (($awal === '') !== ($akhir === ''))                     export_galat(422, 'Isi tanggal awal dan tanggal akhir, atau kosongkan keduanya.');
    if ($awal !== '' && (!$tglOk($awal) || !$tglOk($akhir)))    export_galat(422, 'Tanggal tidak valid.');
    if ($awal !== '' && $akhir < $awal)                         export_galat(422, 'Tanggal akhir tidak boleh sebelum tanggal awal.');
    if ($ao !== '' && !preg_match('/^[A-Za-z0-9_.\-]{1,20}$/', $ao)) export_galat(422, 'Kode AO tidak valid.');

    $p = [];
    if ($awal !== '') { $p['awal'] = $awal; $p['akhir'] = $akhir; }
    if ($ao !== '')   $p['ao'] = $ao;

    $r = api_call($pdf ? 'progres' : 'progres_detail', ['params' => $p], api_cfg()['path_monitoring'], 120);
    if (($r['status'] ?? '') !== 'success') export_galat(502, (string)($r['message'] ?? 'Data tidak dapat dimuat dari server.'));
    $d = (array)($r['data'] ?? []);
    if (!empty($d['error'])) export_galat(422, (string)$d['error']);
    if (!empty($d['empty'])) export_galat(404, (string)($d['pesan'] ?? 'Belum ada data.'));

    $a     = (array)$d['awal'];
    $mg    = (array)($d['minggu'] ?? []);
    $tgOk  = !empty($d['tung_tersedia']);
    $fmt   = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m/Y', $x); };
    $pend  = function ($t): string { $x = strtotime((string)$t); return $x === false ? '–' : date('d/m', $x); };
    $persen = function ($v): string { return $v === null ? '–' : number_format((float)$v, 1, ',', '.') . '%'; };
    $namaAo = 'Semua AO';
    if (($d['sel']['ao'] ?? '') !== '') {
        $namaAo = (string)$d['sel']['ao'];
        foreach (($d['aos'] ?? []) as $x) if ((string)($x['v'] ?? '') === $namaAo) { $namaAo = (string)$x['l']; break; }
    }

    $meta = [
        'Periode tagihan: ' . (string)$d['periode'] . '   |   Rentang: ' . $fmt($d['sel']['awal']) . ' s/d ' . $fmt($d['sel']['akhir'])
            . '   |   AO: ' . $namaAo . '   |   Kelompok awal dari posisi ' . $fmt($d['posisi_awal']),
        'Belum bayar di posisi awal: ' . rp_teks($a['rek']) . ' rekening   |   Baki debet: Rp ' . rp_teks($a['baki'])
            . ($tgOk ? '   |   Tunggakan pokok: Rp ' . rp_teks($a['tung_pkk']) . ', bunga: Rp ' . rp_teks($a['tung_bg']) : '')
            . '   |   Kewajiban bulan ini: pokok Rp ' . rp_teks($a['kw_pkk']) . ', bunga Rp ' . rp_teks($a['kw_bg']),
    ];
    if (!$pdf) {                                   // Excel: tabelnya rincian debitur, jadi ringkasan mingguan ditaruh di keterangan
        foreach ($mg as $m) {
            $meta[] = 'Minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . ', posisi ' . $pend($m['tgl_posisi']) . '): bayar Rp '
                . rp_teks($m['bayar_total']) . ' (pokok Rp ' . rp_teks($m['bayar_pkk']) . ', bunga Rp ' . rp_teks($m['bayar_bg']) . ')   |   tertagih '
                . $persen($m['pct_tertagih']) . '   |   sudah bayar ' . rp_teks($m['rek_bayar']) . ' rek, masih belum ' . rp_teks($m['rek_belum'])
                . ' rek' . ($m['rek_hilang'] ? ', tidak ada di posisi ' . rp_teks($m['rek_hilang']) . ' rek' : '');
        }
    }
    $meta[] = 'Dicetak: ' . date('d/m/Y H:i') . ' oleh ' . (string)((auth_user() ?? [])['nama'] ?? '-');

    $catatan = array_map('strval', (array)($d['peringatan'] ?? []));
    $catatan[] = 'Kelompok awal = rekening dengan kewajiban bulan ini tetapi belum ada pembayaran pokok maupun bunga pada posisi tanggal awal. '
        . 'Pembayaran tiap minggu = selisih pembayaran bulan ini antara dua posisi, hanya untuk rekening kelompok awal. '
        . '"Tertagih" membandingkan pembayaran dengan kewajiban bulan ini (maksimal sebesar kewajiban tiap rekening).';

    // ---------------------------- PDF: ringkasan per minggu ----------------------------
    if ($pdf) {
        $rows = []; $sP = 0.0; $sB = 0.0;
        foreach ($mg as $m) {
            $sP += (float)$m['bayar_pkk']; $sB += (float)$m['bayar_bg'];
            $rows[] = [
                'mgg' => 'Minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . '), posisi ' . $pend($m['tgl_posisi']),
                'bayar_pkk' => (float)$m['bayar_pkk'], 'bayar_bg' => (float)$m['bayar_bg'], 'bayar_total' => (float)$m['bayar_total'],
                'pct' => $persen($m['pct_tertagih']),
                'rek_bayar' => $m['rek_bayar'], 'rek_lunas' => $m['rek_lunas'], 'rek_belum' => $m['rek_belum'], 'rek_hilang' => $m['rek_hilang'],
                'sisa_kw' => (float)$m['sisa_kw'], 'sisa_tung_pkk' => $m['sisa_tung_pkk'], 'sisa_tung_bg' => $m['sisa_tung_bg'],
                'kolek_memburuk' => $m['kolek_memburuk'], 'masuk_npl' => $m['masuk_npl'],
            ];
        }
        $kolom = [
            ['h' => 'Minggu',               'k' => 'mgg',            'w' => 22],
            ['h' => 'Bayar pokok (Rp)',     'k' => 'bayar_pkk',      't' => 'rp',  'w' => 13],
            ['h' => 'Bayar bunga (Rp)',     'k' => 'bayar_bg',       't' => 'rp',  'w' => 13],
            ['h' => 'Total bayar (Rp)',     'k' => 'bayar_total',    't' => 'rp',  'w' => 14],
            ['h' => 'Tertagih',             'k' => 'pct',            'w' => 8],
            ['h' => 'Sudah bayar (rek)',    'k' => 'rek_bayar',      't' => 'int', 'w' => 8],
            ['h' => 'Lunas (rek)',          'k' => 'rek_lunas',      't' => 'int', 'w' => 7],
            ['h' => 'Masih belum (rek)',    'k' => 'rek_belum',      't' => 'int', 'w' => 8],
            ['h' => 'Tidak ada di posisi',  'k' => 'rek_hilang',     't' => 'int', 'w' => 8],
            ['h' => 'Sisa kewajiban (Rp)',  'k' => 'sisa_kw',        't' => 'rp',  'w' => 14],
        ];
        if ($tgOk) {
            $kolom[] = ['h' => 'Sisa tunggakan pokok (Rp)', 'k' => 'sisa_tung_pkk', 't' => 'rp', 'w' => 14];
            $kolom[] = ['h' => 'Sisa tunggakan bunga (Rp)', 'k' => 'sisa_tung_bg',  't' => 'rp', 'w' => 14];
        }
        $kolom[] = ['h' => 'Kolek memburuk', 'k' => 'kolek_memburuk', 't' => 'int', 'w' => 8];
        $kolom[] = ['h' => 'Masuk NPL',      'k' => 'masuk_npl',      't' => 'int', 'w' => 7];

        return [
            'berkas'  => 'progres_mingguan',
            'judul'   => 'Progres Mingguan Tunggakan dan Gagal Bayar',
            'meta'    => $meta,
            'kolom'   => $kolom,
            'rows'    => $rows,
            'total'   => ['bayar_pkk' => $sP, 'bayar_bg' => $sB, 'bayar_total' => $sP + $sB],
            'catatan' => $catatan,
        ];
    }

    // ---------------------------- Excel: rincian per debitur ----------------------------
    $terpotong = !empty($d['terpotong']);
    $rows = [];
    $sum  = ['tung_pkk' => 0.0, 'tung_bg' => 0.0, 'kw_pkk' => 0.0, 'kw_bg' => 0.0, 'bayar_total' => 0.0, 'tung_pkk_akhir' => 0.0, 'tung_bg_akhir' => 0.0];
    foreach ($mg as $i => $_) $sum['m' . ($i + 1)] = 0.0;

    foreach (($d['rows'] ?? []) as $x) {
        $row = [
            'nama' => trim((string)($x['nama'] ?? '')), 'norek' => (string)($x['norek'] ?? ''), 'cif' => (string)($x['cif'] ?? ''),
            'ao' => (string)($x['ao'] ?? ''), 'kolek' => $x['kolek'] ?? null, 'hari' => $x['hari'] ?? null,
            'tung_pkk' => $x['tung_pkk'] ?? null, 'tung_bg' => $x['tung_bg'] ?? null,
            'kw_pkk' => (float)($x['kw_pkk'] ?? 0), 'kw_bg' => (float)($x['kw_bg'] ?? 0),
            'bayar_total' => (float)($x['bayar_total'] ?? 0),
            'kolek_akhir' => $x['kolek_akhir'] ?? null, 'hari_akhir' => $x['hari_akhir'] ?? null,
            'tung_pkk_akhir' => $x['tung_pkk_akhir'] ?? null, 'tung_bg_akhir' => $x['tung_bg_akhir'] ?? null,
            'status' => (string)($x['status'] ?? ''),
        ];
        foreach ((array)($x['minggu'] ?? []) as $i => $w) $row['m' . ($i + 1)] = (float)($w['bayar'] ?? 0);
        foreach ($sum as $k => $_) $sum[$k] += (float)($row[$k] ?? 0);
        $rows[] = $row;
    }

    $kolom = [
        ['h' => 'No',            't' => 'no',   'w' => 5],
        ['h' => 'Nama debitur',  'k' => 'nama', 'w' => 24],
        ['h' => 'No. rekening',  'k' => 'norek','w' => 14],
        ['h' => 'CIF',           'k' => 'cif',  'w' => 12],
        ['h' => 'AO',            'k' => 'ao',   'w' => 16],
        ['h' => 'Kolek awal',    'k' => 'kolek','t' => 'int', 'w' => 7],
        ['h' => 'Hari tunggak awal', 'k' => 'hari', 't' => 'int', 'w' => 9],
    ];
    if ($tgOk) {
        $kolom[] = ['h' => 'Tunggakan pokok (Rp)', 'k' => 'tung_pkk', 't' => 'rp', 'w' => 15];
        $kolom[] = ['h' => 'Tunggakan bunga (Rp)', 'k' => 'tung_bg',  't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Kewajiban bulan ini pokok (Rp)', 'k' => 'kw_pkk', 't' => 'rp', 'w' => 16];
    $kolom[] = ['h' => 'Kewajiban bulan ini bunga (Rp)', 'k' => 'kw_bg',  't' => 'rp', 'w' => 16];
    foreach ($mg as $i => $m) {
        $kolom[] = ['h' => 'Bayar minggu ' . $m['no'] . ' (' . $pend($m['dari']) . '–' . $pend($m['sampai']) . ') (Rp)', 'k' => 'm' . ($i + 1), 't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Total bayar (Rp)', 'k' => 'bayar_total', 't' => 'rp', 'w' => 15];
    $kolom[] = ['h' => 'Kolek akhir',       'k' => 'kolek_akhir', 't' => 'int', 'w' => 7];
    $kolom[] = ['h' => 'Hari tunggak akhir','k' => 'hari_akhir',  't' => 'int', 'w' => 9];
    if ($tgOk) {
        $kolom[] = ['h' => 'Sisa tunggakan pokok (Rp)', 'k' => 'tung_pkk_akhir', 't' => 'rp', 'w' => 15];
        $kolom[] = ['h' => 'Sisa tunggakan bunga (Rp)', 'k' => 'tung_bg_akhir',  't' => 'rp', 'w' => 15];
    }
    $kolom[] = ['h' => 'Status akhir', 'k' => 'status', 'w' => 22];

    if ($terpotong) {
        array_unshift($catatan, 'Rincian dibatasi ' . rp_teks(count($rows)) . ' baris pertama dari ' . rp_teks($a['rek']) . ' rekening. Pilih satu AO untuk melihat seluruhnya. Baris JUMLAH tidak ditampilkan.');
    }
    if (!$tgOk) unset($sum['tung_pkk'], $sum['tung_bg'], $sum['tung_pkk_akhir'], $sum['tung_bg_akhir']);

    return [
        'berkas'  => 'progres_mingguan_rincian',
        'judul'   => 'Progres Mingguan Tunggakan dan Gagal Bayar (Rincian Debitur)',
        'meta'    => $meta,
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