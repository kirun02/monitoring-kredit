<?php
/**
 * RIPLAY Personal Deposito Artha Mas: baca isian dan susun dokumen.
 * Letak: sejajar export_lib.php. Dipakai api/export_riplay.php?produk=deposito. Butuh export_lib_riplay.php.
 *
 *   deposito_baca()   baca + validasi isian POST; tanggal jatuh tempo dihitung dari tanggal pembukaan + jangka waktu bila dikosongkan
 *   deposito_html()   susun isi dokumen (bagian 1 sampai 7)
 */

function deposito_baca(array $in): array
{
    $d = [];
    // 1. data nasabah
    $d['nama'] = riplay_in_teks($in, 'nama', 100);
    if ($d['nama'] === '') export_galat(422, 'Nama nasabah wajib diisi.');
    $d['identitas'] = riplay_in_teks($in, 'identitas', 40);          // KTP / SIM / Paspor: bentuknya bebas
    $d['alamat']    = riplay_in_teks($in, 'alamat', 250);
    $d['telepon']   = riplay_in_teks($in, 'telepon', 30);
    $d['cif']       = riplay_in_teks($in, 'cif', 40);

    // 2. data transaksi deposito
    $d['bilyet']    = riplay_in_teks($in, 'bilyet', 60);
    $d['nominal']   = riplay_in_angka($in, 'nominal');
    $d['tenor']     = (int)($in['tenor'] ?? 0);
    $d['tgl_buka']  = riplay_in_tgl($in, 'tgl_buka');
    $d['tgl_tempo'] = riplay_in_tgl($in, 'tgl_tempo');
    $d['bunga']     = riplay_in_desimal($in, 'bunga');
    $d['aro']       = riplay_in_pilih($in, 'aro', ['aro', 'non_aro']);
    $d['rek_sumber'] = riplay_in_teks($in, 'rek_sumber', 120);
    $d['jaminan']   = riplay_in_pilih($in, 'jaminan', ['ya', 'tidak']);
    $d['petugas']   = riplay_in_teks($in, 'petugas', 100);

    if ($d['nominal'] <= 0)                        export_galat(422, 'Nominal deposito wajib diisi.');
    if ($d['tenor'] < 1 || $d['tenor'] > 120)      export_galat(422, 'Jangka waktu harus 1 sampai 120 bulan.');
    if ($d['bunga'] < 0 || $d['bunga'] > 100)      export_galat(422, 'Suku bunga harus antara 0 dan 100 persen per tahun.');
    if ($d['tgl_tempo'] === '' && $d['tgl_buka'] !== '') $d['tgl_tempo'] = riplay_tambah_bulan($d['tgl_buka'], $d['tenor']);
    return $d;
}

function deposito_html(array $d): string
{
    $rp    = function (int $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); };
    $titik = '……………………………………………';
    $isi   = function (string $v) use ($titik): string { return $v === '' ? $titik : h_e($v); };
    $tgl   = function (string $ymd) use ($titik): string { return $ymd === '' ? $titik : date('d/m/Y', strtotime($ymd)); };
    $kotak = function (bool $on, string $label): string { return '<span class="kb">' . ($on ? '☒' : '☐') . '</span> ' . h_e($label); };
    $bunga = rtrim(rtrim(number_format($d['bunga'], 2, ',', '.'), '0'), ',');

    $h  = '<h1>RINGKASAN INFORMASI PRODUK DAN LAYANAN (RIPLAY) PERSONAL</h1>';
    $h .= '<div class="sb" style="font-size:14pt;font-weight:bold;font-style:normal;margin:1mm 0 1mm">DEPOSITO ARTHA MAS</div>';
    $h .= '<div class="ts">PT BPR Delta Artha Kencana – Berizin dan Diawasi oleh Otoritas Jasa Keuangan (OJK)</div>';
    $h .= '<div class="ts" style="font-size:8pt">Dokumen ini memuat informasi khusus mengenai transaksi Deposito Artha Mas yang dibuka oleh nasabah yang bersangkutan, dan merupakan pelengkap dari RIPLAY Umum yang telah diterima sebelumnya.</div>';

    $h .= '<h2>1. DATA NASABAH</h2><table class="f">'
        . '<tr><td class="l">Nama Lengkap Nasabah</td><td>' . $isi($d['nama']) . '</td></tr>'
        . '<tr><td class="l">Nomor Identitas (KTP/SIM/Paspor)</td><td>' . $isi($d['identitas']) . '</td></tr>'
        . '<tr><td class="l">Alamat</td><td>' . $isi($d['alamat']) . '</td></tr>'
        . '<tr><td class="l">Nomor Telepon</td><td>' . $isi($d['telepon']) . '</td></tr>'
        . '<tr><td class="l">Nomor CIF / Rekening</td><td>' . $isi($d['cif']) . '</td></tr></table>';

    $h .= '<h2>2. DATA TRANSAKSI DEPOSITO</h2><table class="f">'
        . '<tr><td class="l">Nomor Bilyet / Sertifikat Deposito</td><td>' . $isi($d['bilyet']) . '</td></tr>'
        . '<tr><td class="l">Nominal Deposito</td><td>' . $rp($d['nominal']) . '</td></tr>'
        . '<tr><td class="l">Jangka Waktu</td><td>' . $d['tenor'] . ' bulan</td></tr>'
        . '<tr><td class="l">Tanggal Pembukaan</td><td>' . $tgl($d['tgl_buka']) . '</td></tr>'
        . '<tr><td class="l">Tanggal Jatuh Tempo</td><td>' . $tgl($d['tgl_tempo']) . '</td></tr>'
        . '<tr><td class="l">Suku Bunga (% per tahun)</td><td>' . $bunga . ' % per tahun</td></tr>'
        . '<tr><td class="l">Status Perpanjangan (ARO / Non-ARO)</td><td>' . $kotak($d['aro'] === 'aro', 'ARO') . '&nbsp;&nbsp; ' . $kotak($d['aro'] === 'non_aro', 'Non-ARO') . '</td></tr>'
        . '<tr><td class="l">Rekening Sumber Dana / Pencairan Bunga</td><td>' . $isi($d['rek_sumber']) . '</td></tr>'
        . '<tr><td class="l">Digunakan Sebagai Jaminan Kredit (Ya/Tidak)</td><td>' . $kotak($d['jaminan'] === 'ya', 'Ya') . '&nbsp;&nbsp; ' . $kotak($d['jaminan'] === 'tidak', 'Tidak') . '</td></tr></table>';

    $h .= '<div class="jaga"><h2>3. MANFAAT YANG DIPEROLEH NASABAH</h2><ul>'
        . '<li>Bunga deposito sesuai suku bunga yang disepakati dan tercantum pada bilyet/sertifikat deposito.</li>'
        . '<li>Simpanan dijamin oleh Lembaga Penjamin Simpanan (LPS) sepanjang memenuhi ketentuan 3T yang berlaku.</li>'
        . '<li>Dapat dijadikan agunan/jaminan kredit di PT BPR Delta Artha Kencana sesuai kebijakan bank.</li>'
        . '<li>Special Rate (bila berlaku) sesuai kesepakatan pada saat pembukaan rekening, sebagaimana tercantum pada tabel Data Transaksi di atas.</li></ul></div>';

    $h .= '<div class="jaga"><h2>4. RISIKO YANG PERLU DIKETAHUI NASABAH</h2><ul>'
        . '<li>Pencairan sebelum tanggal jatuh tempo dapat dikenakan biaya/penalti sesuai kebijakan bank yang berlaku pada saat pembukaan rekening.</li>'
        . '<li>Apabila deposito diperpanjang otomatis (ARO), suku bunga yang berlaku mengikuti suku bunga bank pada tanggal perpanjangan, yang dapat berbeda dari suku bunga awal.</li>'
        . '<li>Nilai penjaminan LPS mengikuti plafon dan ketentuan LPS yang berlaku pada saat terjadinya klaim penjaminan.</li></ul></div>';

    $h .= '<div class="jaga"><h2>5. BIAYA YANG BERLAKU PADA TRANSAKSI INI</h2><ul>'
        . '<li>Biaya penalti pencairan sebelum jatuh tempo (bila dicairkan lebih awal): sesuai kebijakan bank yang berlaku saat pembukaan rekening.</li>'
        . '<li>Pajak Penghasilan (PPh Final) atas bunga deposito sesuai peraturan perpajakan yang berlaku.</li></ul></div>';

    $h .= '<div class="jaga"><h2>6. PERNYATAAN DAN PERSETUJUAN NASABAH</h2>'
        . '<p>Dengan menandatangani dokumen ini, saya selaku nasabah menyatakan bahwa saya telah membaca, menerima, dan memahami RIPLAY Umum dan RIPLAY Personal untuk produk Deposito Artha Mas, '
        . 'termasuk manfaat, risiko, biaya, persyaratan, dan tata cara yang berlaku, serta menyetujui data transaksi sebagaimana tercantum pada dokumen ini.</p>'
        . '<table class="tt"><tr><td>Malang, ' . ($d['tgl_buka'] !== '' ? $tgl($d['tgl_buka']) : '…………………………') . '<br>Nasabah,<br><br><br><br><br>(' . h_e($d['nama']) . ')</td>'
        . '<td><br>Petugas PT BPR Delta Artha Kencana,<br><br><br><br><br>(' . ($d['petugas'] !== '' ? h_e($d['petugas']) : '……………………………………') . ')</td></tr></table></div>';

    $h .= '<div class="jaga"><h2>7. LAYANAN DAN PENGADUAN NASABAH</h2><table class="f">'
        . '<tr><td class="l">Layanan #HaloDAK</td><td>0822 3288 7915 (Pesan &amp; Telepon)</td></tr>'
        . '<tr><td class="l">E-mail</td><td>info@bprdeltaarthakencana.com</td></tr>'
        . '<tr><td class="l">Telepon Kantor</td><td>(0341) 831831</td></tr>'
        . '<tr><td class="l">Alamat Kantor</td><td>Jalan Raya Kebonagung No. 23, Kecamatan Pakisaji, Kabupaten Malang</td></tr></table>'
        . '<div class="cat">Dokumen ini diterbitkan sesuai ketentuan Otoritas Jasa Keuangan mengenai Transparansi Informasi Produk dan Layanan bagi Lembaga Jasa Keuangan. '
        . 'Simpanan dijamin oleh Lembaga Penjamin Simpanan (LPS) sesuai ketentuan yang berlaku.</div></div>';

    return $h;
}
