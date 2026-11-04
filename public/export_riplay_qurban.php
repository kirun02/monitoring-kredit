<?php
/**
 * RIPLAY Personal Tabungan Pejuang Qurban (TPQ): baca isian dan susun dokumen.
 * Letak: sejajar export_lib.php. Dipakai api/export_riplay.php?produk=qurban. Butuh export_lib_riplay.php.
 *
 *   qurban_baca()   baca + validasi isian POST; total setoran, tanggal berakhir, dan perkiraan dana dihitung di sini
 *   qurban_html()   susun isi dokumen (bagian 1 sampai 5)
 */

const QURBAN_TENOR = 10;          // jangka waktu program (bulan)

function qurban_baca(array $in): array
{
    $d = [];
    $d['nama']      = riplay_in_teks($in, 'nama', 100);
    if ($d['nama'] === '') export_galat(422, 'Nama nasabah wajib diisi.');
    $d['identitas'] = riplay_in_teks($in, 'identitas', 40);
    $d['alamat']    = riplay_in_teks($in, 'alamat', 250);
    $d['telepon']   = riplay_in_teks($in, 'telepon', 30);
    $d['rekening']  = riplay_in_teks($in, 'rekening', 40);
    $d['tgl_buka']  = riplay_in_tgl($in, 'tgl_buka');

    $d['paket']     = riplay_in_pilih($in, 'paket', ['1', '2', '3', '4', '5', '6']);
    $d['nominal']   = riplay_in_angka($in, 'nominal');
    $d['tgl_setor'] = riplay_in_tgl($in, 'tgl_setor');
    $d['tgl_selesai'] = riplay_in_tgl($in, 'tgl_selesai');
    $d['tgl_cair']  = riplay_in_tgl($in, 'tgl_cair');                 // 1 bulan sebelum Idul Adha: diisi manual
    $d['metode']    = riplay_in_pilih($in, 'metode', ['tunai', 'transfer']);
    $d['bonus_ada'] = trim((string)($in['bonus'] ?? '')) !== '';      // kosong = belum diketahui (dicetak garis isian), 0 = tidak ada bonus
    $d['bonus']     = riplay_in_angka($in, 'bonus');
    $d['petugas']   = riplay_in_teks($in, 'petugas', 100);

    if ($d['nominal'] <= 0) export_galat(422, 'Nominal setoran per bulan wajib diisi.');
    if ($d['tgl_selesai'] === '' && $d['tgl_setor'] !== '') $d['tgl_selesai'] = riplay_tambah_bulan($d['tgl_setor'], QURBAN_TENOR);
    $d['total']    = $d['nominal'] * QURBAN_TENOR;
    $d['diterima'] = $d['bonus_ada'] ? $d['total'] + $d['bonus'] : null;
    return $d;
}

function qurban_html(array $d): string
{
    $rp    = function (int $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); };
    $titik = '…………………………………………………';
    $isi   = function (string $v) use ($titik): string { return $v === '' ? $titik : h_e($v); };
    $tgl   = function (string $ymd) use ($titik): string { return $ymd === '' ? $titik : date('d/m/Y', strtotime($ymd)); };
    $kotak = function (bool $on, string $label): string { return '<span class="kb">' . ($on ? '☒' : '☐') . '</span> ' . h_e($label); };

    $paket = '';
    for ($i = 1; $i <= 6; $i++) $paket .= $kotak($d['paket'] === (string)$i, 'Paket ' . $i) . '&nbsp;&nbsp; ';

    $h  = '<h1>RINGKASAN INFORMASI PRODUK DAN LAYANAN (RIPLAY) PERSONAL</h1>';
    $h .= '<div class="sb" style="font-size:14pt;font-weight:bold;font-style:normal;margin:1mm 0 1mm">Tabungan Pejuang Qurban (TPQ)</div>';
    $h .= '<div class="ts" style="font-size:8pt">Dokumen ini memuat informasi produk yang bersifat spesifik dan telah disesuaikan dengan pilihan serta data Nasabah yang bersangkutan, '
        . 'sebagai pelengkap RIPLAY Umum yang telah diterima dan dipahami oleh Nasabah sebelum menandatangani dokumen ini.</div>';

    $h .= '<h2>1. Data Nasabah</h2><table class="f">'
        . '<tr><td class="l">Nama Nasabah</td><td>' . $isi($d['nama']) . '</td></tr>'
        . '<tr><td class="l">No. Identitas (KTP)</td><td>' . $isi($d['identitas']) . '</td></tr>'
        . '<tr><td class="l">Alamat</td><td>' . $isi($d['alamat']) . '</td></tr>'
        . '<tr><td class="l">No. Telepon / HP</td><td>' . $isi($d['telepon']) . '</td></tr>'
        . '<tr><td class="l">Nomor Rekening TPQ</td><td>' . $isi($d['rekening']) . '</td></tr>'
        . '<tr><td class="l">Tanggal Pembukaan</td><td>' . $tgl($d['tgl_buka']) . '</td></tr></table>';

    $h .= '<h2>2. Detail Produk yang Dipilih Nasabah</h2><table class="f">'
        . '<tr><td class="l">Nama Produk</td><td>Tabungan Pejuang Qurban (TPQ)</td></tr>'
        . '<tr><td class="l">Paket yang Dipilih</td><td>' . $paket . '</td></tr>'
        . '<tr><td class="l">Nominal Setoran per Bulan</td><td>' . $rp($d['nominal']) . '</td></tr>'
        . '<tr><td class="l">Jangka Waktu</td><td>10 (sepuluh) bulan</td></tr>'
        . '<tr><td class="l">Total Setoran Pokok (10 Bulan)</td><td>' . $rp($d['total']) . '</td></tr>'
        . '<tr><td class="l">Tanggal Setoran Pertama</td><td>' . $tgl($d['tgl_setor']) . '</td></tr>'
        . '<tr><td class="l">Estimasi Tanggal Berakhir Program</td><td>' . $tgl($d['tgl_selesai']) . '</td></tr>'
        . '<tr><td class="l">Estimasi Tanggal Pencairan (1 bulan sebelum Idul Adha)</td><td>' . $tgl($d['tgl_cair']) . '</td></tr>'
        . '<tr><td class="l">Metode Pembayaran</td><td>' . $kotak($d['metode'] === 'tunai', 'Tunai di Kantor Bank') . '&nbsp;&nbsp;&nbsp; '
            . $kotak($d['metode'] === 'transfer', 'Transfer ke Rek. PT BPR Delta Artha Kencana') . '</td></tr></table>';

    $h .= '<div class="jaga"><h2>3. Simulasi Manfaat</h2>'
        . '<p>Berdasarkan paket yang dipilih, ilustrasi dana yang terkumpul pada akhir program adalah sebagai berikut (diisi sesuai paket):</p>'
        . '<table class="f"><tr><th>Uraian</th><th>Nilai</th></tr>'
        . '<tr><td>Total setoran pokok (10 bulan)</td><td>' . $rp($d['total']) . '</td></tr>'
        . '<tr><td>Bonus tabungan peralatan ibadah</td><td>' . ($d['bonus_ada'] ? $rp($d['bonus']) : $titik) . '</td></tr>'
        . '<tr><td>Total biaya yang dikenakan</td><td>Tidak ada</td></tr>'
        . '<tr class="on"><td>Perkiraan dana diterima saat pencairan</td><td>' . ($d['diterima'] !== null ? $rp($d['diterima']) : $titik) . '</td></tr></table>'
        . '<div class="cat">Catatan: nilai bonus dan hak pencairan penuh berlaku sepanjang Nasabah tidak mengalami keterlambatan setoran lebih dari 2 (dua) bulan sesuai syarat dan ketentuan program.</div></div>';

    $h .= '<div class="jaga"><h2>4. Risiko yang Melekat pada Pilihan Nasabah</h2><ul>'
        . '<li>Dana pada paket yang dipilih hanya dapat dicairkan pada akhir program (1 bulan sebelum Hari Raya Idul Adha), bukan sewaktu-waktu.</li>'
        . '<li>Apabila Nasabah terlambat menyetor lebih dari 2 (dua) bulan, Nasabah didiskualifikasi dari program berjalan sehingga tidak menerima bonus dan tidak dapat melanjutkan program pada periode ini.</li>'
        . '<li>Nasabah bertanggung jawab untuk melakukan setoran rutin sesuai nominal dan jadwal pada paket yang telah dipilih di atas.</li></ul></div>';

    $tglTtd = $d['tgl_buka'] !== '' ? $tgl($d['tgl_buka']) : $titik;
    $h .= '<div class="jaga"><h2>5. Pernyataan Nasabah</h2>'
        . '<p>Dengan menandatangani dokumen ini, saya selaku Nasabah menyatakan bahwa:</p><ul>'
        . '<li>Telah menerima, membaca, dan memahami isi RIPLAY Umum dan RIPLAY Personal produk Tabungan Pejuang Qurban (TPQ).</li>'
        . '<li>Telah memahami manfaat, risiko, biaya, syarat dan ketentuan, serta hak dan kewajiban saya sebagai Nasabah.</li>'
        . '<li>Data yang saya berikan pada dokumen ini adalah benar dan dapat dipertanggungjawabkan.</li>'
        . '<li>Bersedia mematuhi syarat dan ketentuan program TPQ yang berlaku di PT BPR Delta Artha Kencana.</li></ul>'
        . '<table class="tt"><tr><td>Nasabah,<br><i>( Nama &amp; Tanda Tangan )</i><br><br><br><br>(' . h_e($d['nama']) . ')<br>Tanggal: ' . $tglTtd . '</td>'
        . '<td>Petugas PT BPR Delta Artha Kencana,<br><i>( Nama, Tanda Tangan &amp; Stempel )</i><br><br><br><br>(' . ($d['petugas'] !== '' ? h_e($d['petugas']) : '……………………………………') . ')<br>Tanggal: ' . $tglTtd . '</td></tr></table>'
        . '<div class="cat">PT BPR Delta Artha Kencana — Berizin dan diawasi oleh Otoritas Jasa Keuangan. Bank merupakan peserta penjamin simpanan di LPS. '
        . 'Pelayanan &amp; Pengaduan: 0822 3288 7915 | info@bprdeltaarthakencana.com | www.bprdeltaarthakencana.com</div></div>';

    return $h;
}
