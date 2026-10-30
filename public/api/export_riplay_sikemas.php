<?php
/**
 * RIPLAY Personal SikEMAS (Simpanan Kepemilikan Emas): baca isian, hitung, dan susun dokumen.
 * Letak: sejajar export_lib.php. Dipakai api/export_riplay.php?produk=sikemas. Butuh export_lib_riplay.php.
 *
 *   sikemas_baca()   baca + validasi isian POST, hitung nilai pembiayaan, angsuran, biaya, jatuh tempo
 *   sikemas_html()   susun isi dokumen (bagian 1 sampai 8)
 */

/** Maksimal tenor (bulan) menurut berat emas: sampai 2 gram = 12, di atas 2 sampai 5 gram = 18, di atas 5 gram = 24. */
function sikemas_tenor_maks(float $gram): int
{
    return $gram > 5 ? 24 : ($gram > 2 ? 18 : 12);
}

/** Tanggal (Y-m-d) ditambah N bulan; bila harinya tidak ada di bulan tujuan, dipakai hari terakhir bulan itu. */
function sikemas_tambah_bulan(string $ymd, int $bulan): string
{
    $dt  = new DateTime($ymd);
    $hari = (int)$dt->format('j');
    $dt->modify('first day of +' . $bulan . ' months');
    $dt->setDate((int)$dt->format('Y'), (int)$dt->format('n'), min($hari, (int)$dt->format('t')));
    return $dt->format('Y-m-d');
}

function sikemas_baca(array $in): array
{
    $t = function (string $k, int $maks = 200) use ($in): string {
        return trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)($in[$k] ?? '')), 0, $maks));
    };
    $angka = function (string $k) use ($in): int {
        $s = preg_replace('/\D+/', '', (string)($in[$k] ?? ''));
        return $s === '' ? 0 : (int)substr($s, 0, 13);
    };
    $desimal = function (string $k) use ($in): float { return (float)str_replace(',', '.', (string)($in[$k] ?? '')); };
    $pilih = function (string $k, array $boleh, string $baku = '') use ($in): string {
        $v = (string)($in[$k] ?? '');
        return in_array($v, $boleh, true) ? $v : $baku;
    };

    $d = [];
    // 1. data nasabah
    $d['nama'] = $t('nama', 100);
    if ($d['nama'] === '') export_galat(422, 'Nama nasabah wajib diisi.');
    $d['nik'] = (string)preg_replace('/\D+/', '', (string)($in['nik'] ?? ''));
    if ($d['nik'] !== '' && strlen($d['nik']) !== 16) export_galat(422, 'Nomor KTP harus 16 digit.');
    $usia = (int)($in['usia'] ?? 0);
    $d['usia']    = ($usia >= 1 && $usia <= 120) ? $usia : 0;
    $d['alamat']  = $t('alamat', 250);
    $d['telepon'] = $t('telepon', 30);
    $d['cif']     = $t('cif', 40);

    // 2. data transaksi
    $d['no_perjanjian'] = $t('no_perjanjian', 60);
    $d['jenis']      = $t('jenis', 80);
    $d['berat']      = $desimal('berat');
    $d['harga_gram'] = $angka('harga_gram');
    $d['dp']         = $angka('dp');
    $d['nilai']      = $angka('nilai');                             // kosong = dihitung dari berat x harga - uang muka
    $d['bunga']      = $desimal('bunga');
    $d['satuan']     = $pilih('bunga_satuan', ['bulan', 'tahun'], 'bulan');
    $d['metode']     = $pilih('metode_bunga', ['flat', 'efektif', 'anuitas'], 'flat');
    $d['tenor']      = (int)($in['tenor'] ?? 0);
    $d['angsuran']   = $angka('angsuran');                          // kosong = dihitung dari simulasi
    $d['tgl_akad']   = trim((string)($in['tgl_akad'] ?? ''));
    $d['petugas']    = $t('petugas', 100);

    if ($d['berat'] <= 0 || $d['berat'] > 100000)  export_galat(422, 'Berat emas (gram) wajib diisi.');
    if ($d['tenor'] < 1 || $d['tenor'] > 24)       export_galat(422, 'Jangka waktu harus 1 sampai 24 bulan.');
    $maks = sikemas_tenor_maks($d['berat']);
    if ($d['tenor'] > $maks)                       export_galat(422, 'Untuk emas ' . rtrim(rtrim(number_format($d['berat'], 3, ',', '.'), '0'), ',') . ' gram, tenor maksimal ' . $maks . ' bulan.');
    if ($d['bunga'] < 0 || $d['bunga'] > 100)      export_galat(422, 'Suku bunga harus antara 0 dan 100 persen.');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d['tgl_akad']) || !checkdate((int)substr($d['tgl_akad'], 5, 2), (int)substr($d['tgl_akad'], 8, 2), (int)substr($d['tgl_akad'], 0, 4))) $d['tgl_akad'] = '';

    $d['total_emas'] = (int)round($d['berat'] * $d['harga_gram']);
    if ($d['nilai'] <= 0) $d['nilai'] = max(0, $d['total_emas'] - $d['dp']);
    if ($d['nilai'] <= 0) export_galat(422, 'Nilai pembiayaan wajib diisi (atau isi berat, harga emas per gram, dan uang muka).');

    $d['admin']   = (int)round($d['nilai'] * 0.005);                // biaya administrasi 0,5%
    $d['provisi'] = (int)round($d['nilai'] * 0.005);                // biaya provisi 0,5%
    $bungaTahun   = $d['satuan'] === 'bulan' ? $d['bunga'] * 12 : $d['bunga'];
    $d['sim']     = riplay_simulasi($d['nilai'], $d['tenor'], $bungaTahun, $d['metode'], 1);
    if ($d['angsuran'] <= 0) $d['angsuran'] = $d['sim']['angsuran1'];
    $d['tgl_tempo'] = $d['tgl_akad'] !== '' ? sikemas_tambah_bulan($d['tgl_akad'], $d['tenor']) : '';
    $d['tenor_maks'] = $maks;
    return $d;
}

function sikemas_html(array $d): string
{
    $rp   = function (int $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); };
    $titik = '……………………………………………';
    $isi  = function (string $v) use ($titik): string { return $v === '' ? $titik : h_e($v); };
    $tgl  = function (string $ymd) use ($titik): string { return $ymd === '' ? $titik : date('d/m/Y', strtotime($ymd)); };
    $desimal = function (float $v, int $dec = 3): string { return rtrim(rtrim(number_format($v, $dec, ',', '.'), '0'), ','); };

    $metode = ['flat' => 'flat', 'efektif' => 'efektif (bunga dari sisa pokok)', 'anuitas' => 'anuitas'][$d['metode']];
    $bunga  = $desimal($d['bunga'], 2) . ' % per ' . ($d['satuan'] === 'bulan' ? 'bulan' : 'tahun') . ', ' . $metode;
    $angs   = $rp($d['angsuran']) . ($d['metode'] === 'efektif' ? ' (bulan pertama; menurun tiap bulan)' : '');
    $jenisBerat = ($d['jenis'] !== '' ? h_e($d['jenis']) . ', ' : '') . $desimal($d['berat']) . ' gram';
    $hargaEmas  = $d['harga_gram'] > 0 ? $rp($d['harga_gram']) . ' per gram (total ' . $rp($d['total_emas']) . ')' : $titik;

    $h  = '<h1>RINGKASAN INFORMASI PRODUK DAN LAYANAN (RIPLAY) PERSONAL</h1>';
    $h .= '<div class="sb" style="font-size:14pt;font-weight:bold;font-style:normal;margin:1mm 0 0">SikEMAS</div>';
    $h .= '<div class="sb" style="font-size:11pt;font-style:normal;margin:0 0 1mm">Simpanan Kepemilikan Emas</div>';
    $h .= '<div class="ts">PT BPR Delta Artha Kencana – Sinergi Bersama Galeri24 Pegadaian – Berizin dan Diawasi oleh Otoritas Jasa Keuangan (OJK)</div>';
    $h .= '<div class="ts" style="font-size:8pt">Dokumen ini memuat informasi khusus mengenai transaksi pembiayaan SikEMAS yang diajukan oleh nasabah yang bersangkutan, dan merupakan pelengkap dari RIPLAY Umum yang telah diterima sebelumnya.</div>';

    $h .= '<h2>1. DATA NASABAH</h2><table class="f">'
        . '<tr><td class="l">Nama Lengkap Nasabah</td><td>' . $isi($d['nama']) . '</td></tr>'
        . '<tr><td class="l">Nomor Identitas (KTP)</td><td>' . $isi($d['nik']) . '</td></tr>'
        . '<tr><td class="l">Usia</td><td>' . ($d['usia'] > 0 ? $d['usia'] . ' tahun' : $titik) . '</td></tr>'
        . '<tr><td class="l">Alamat</td><td>' . $isi($d['alamat']) . '</td></tr>'
        . '<tr><td class="l">Nomor Telepon</td><td>' . $isi($d['telepon']) . '</td></tr>'
        . '<tr><td class="l">Nomor CIF / Rekening</td><td>' . $isi($d['cif']) . '</td></tr></table>';

    $h .= '<h2>2. DATA TRANSAKSI PEMBIAYAAN EMAS</h2><table class="f">'
        . '<tr><td class="l">Nomor Perjanjian Pembiayaan</td><td>' . $isi($d['no_perjanjian']) . '</td></tr>'
        . '<tr><td class="l">Jenis &amp; Berat Emas (gram)</td><td>' . $jenisBerat . '</td></tr>'
        . '<tr><td class="l">Harga Emas Saat Akad</td><td>' . $hargaEmas . '</td></tr>'
        . '<tr><td class="l">Uang Muka (DP)</td><td>' . $rp($d['dp']) . '</td></tr>'
        . '<tr><td class="l">Nilai Pembiayaan (Pokok)</td><td>' . $rp($d['nilai']) . '</td></tr>'
        . '<tr><td class="l">Suku Bunga (%)</td><td>' . h_e($bunga) . '</td></tr>'
        . '<tr><td class="l">Biaya Administrasi (0,5%)</td><td>' . $rp($d['admin']) . '</td></tr>'
        . '<tr><td class="l">Biaya Provisi (0,5%)</td><td>' . $rp($d['provisi']) . '</td></tr>'
        . '<tr><td class="l">Jangka Waktu (Tenor)</td><td>' . $d['tenor'] . ' bulan</td></tr>'
        . '<tr><td class="l">Besar Angsuran per Bulan</td><td>' . h_e($angs) . '</td></tr>'
        . '<tr><td class="l">Tanggal Akad</td><td>' . $tgl($d['tgl_akad']) . '</td></tr>'
        . '<tr><td class="l">Tanggal Jatuh Tempo / Lunas</td><td>' . $tgl($d['tgl_tempo']) . '</td></tr></table>';

    $baris = [['1 gram – 2 gram', '12 bulan', 12], ['> 2 gram – 5 gram', '18 bulan', 18], ['> 5 gram', '24 bulan', 24]];
    $h .= '<div class="jaga"><h2>3. REFERENSI TENOR SESUAI BERAT EMAS</h2><table class="f"><tr><th>Gram Emas</th><th>Maksimal Tenor</th></tr>';
    foreach ($baris as $b) $h .= '<tr' . ($b[2] === $d['tenor_maks'] ? ' class="on"' : '') . '><td>' . $b[0] . '</td><td>' . $b[1] . '</td></tr>';
    $h .= '</table><div class="cat">Baris yang ditebalkan berlaku untuk emas ' . $desimal($d['berat']) . ' gram pada transaksi ini.</div></div>';

    $h .= '<div class="jaga"><h2>4. MANFAAT YANG DIPEROLEH NASABAH</h2><ul>'
        . '<li>Kepemilikan emas fisik (Logam Mulia/Lantakan) melalui skema cicilan yang ringan.</li>'
        . '<li>Potensi keuntungan investasi seiring pertumbuhan nilai emas dari waktu ke waktu.</li>'
        . '<li>Emas yang dimiliki dapat dijadikan jaminan pinjaman/kredit di PT BPR Delta Artha Kencana sesuai kebijakan bank.</li>'
        . '<li>Kualitas dan keaslian emas terjamin melalui kerja sama dengan Galeri24 Pegadaian.</li></ul></div>';

    $h .= '<div class="jaga"><h2>5. RISIKO YANG PERLU DIKETAHUI NASABAH</h2><ul>'
        . '<li>Nilai emas dapat berfluktuasi mengikuti harga pasar; kenaikan atau penurunan harga emas dapat memengaruhi nilai kepemilikan nasabah.</li>'
        . '<li>Apabila nasabah tidak dapat melunasi angsuran sesuai jadwal (wanprestasi), emas yang dibiayai dapat dikenakan tindakan sesuai perjanjian pembiayaan yang berlaku.</li>'
        . '<li>Keterlambatan pembayaran angsuran dapat dikenakan biaya tambahan sesuai kebijakan bank.</li>'
        . '<li>Emas fisik tetap berada dalam penguasaan/jaminan bank hingga pembiayaan dilunasi sepenuhnya.</li></ul></div>';

    $h .= '<div class="jaga"><h2>6. BIAYA YANG BERLAKU PADA TRANSAKSI INI</h2><ul>'
        . '<li>Suku bunga pembiayaan: sesuai angka yang tercantum pada tabel Data Transaksi di atas (mulai 1%, syarat dan ketentuan berlaku).</li>'
        . '<li>Biaya Administrasi: 0,5% dari nilai pembiayaan, dibayarkan di awal.</li>'
        . '<li>Biaya Provisi: 0,5% dari nilai pembiayaan, dibayarkan di awal.</li>'
        . '<li>Biaya keterlambatan angsuran (bila ada): sesuai kebijakan bank yang berlaku saat akad.</li></ul></div>';

    $h .= '<div class="jaga"><h2>7. PERNYATAAN DAN PERSETUJUAN NASABAH</h2>'
        . '<p>Dengan menandatangani dokumen ini, saya selaku nasabah menyatakan bahwa saya telah membaca, menerima, dan memahami RIPLAY Umum dan RIPLAY Personal untuk produk SikEMAS, '
        . 'termasuk manfaat, risiko, biaya, persyaratan, dan tata cara yang berlaku, serta menyetujui data transaksi sebagaimana tercantum pada dokumen ini.</p>'
        . '<table class="tt"><tr><td>Malang, ' . ($d['tgl_akad'] !== '' ? $tgl($d['tgl_akad']) : '…………………………') . '<br>Nasabah,<br><br><br><br><br>(' . h_e($d['nama']) . ')</td>'
        . '<td><br>Petugas PT BPR Delta Artha Kencana,<br><br><br><br><br>(' . ($d['petugas'] !== '' ? h_e($d['petugas']) : '……………………………………') . ')</td></tr></table></div>';

    $h .= '<div class="jaga"><h2>8. LAYANAN DAN PENGADUAN NASABAH</h2><table class="f">'
        . '<tr><td class="l">Layanan #HaloDAK</td><td>0822 3288 7915 (Pesan &amp; Telepon)</td></tr>'
        . '<tr><td class="l">E-mail</td><td>info@bprdeltaarthakencana.com</td></tr>'
        . '<tr><td class="l">Telepon Kantor</td><td>(0341) 831831</td></tr>'
        . '<tr><td class="l">Alamat Kantor</td><td>Jl. Raya Kebonagung No. 23, Kelurahan Pakisaji, Kabupaten Malang, Provinsi Jawa Timur</td></tr></table>'
        . '<div class="cat">Dokumen ini diterbitkan sesuai ketentuan Otoritas Jasa Keuangan mengenai Transparansi Informasi Produk dan Layanan bagi Lembaga Jasa Keuangan. '
        . 'PT BPR Delta Artha Kencana merupakan Bank Peserta Penjaminan pada Lembaga Penjamin Simpanan (LPS) sesuai ketentuan yang berlaku.</div></div>';

    return $h;
}
