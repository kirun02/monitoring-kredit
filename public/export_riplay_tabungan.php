<?php
/**
 * RIPLAY Personal Tabungan Arthamas: baca isian dan susun dokumen.
 * Letak: sejajar export_lib.php. Dipakai api/export_riplay.php?produk=tabungan. Butuh export_lib_riplay.php.
 *
 *   tabungan_profil()  teks tetap produk (ringkasan produk, biaya, setoran minimal); ubah di sini bila ketentuan produk berubah
 *   tabungan_baca()    baca + validasi isian POST
 *   tabungan_html()    susun isi dokumen (bagian A sampai E, peringatan, pengaduan, pernyataan)
 */

function tabungan_profil(): array
{
    return [
        'nama'        => 'Tabungan Arthamas',
        'judul'       => 'PRODUK TABUNGAN ARTHAMAS',
        'setoran_min' => 50000,
        // ringkasan produk (bagian B): [label, isi]; isi null = diisi dari isian formulir (setoran awal nasabah)
        'ringkasan'   => [
            ['Nama Penerbit', 'PT BPR Delta Artha Kencana'],
            ['Jenis Produk', 'Tabungan'],
            ['Mata Uang', 'Rupiah (IDR)'],
            ['Setoran Awal', 'Tabungan Arthamas minimal Rp50.000,'],
            ['Biaya Administrasi', 'Biaya administrasi akan dikenakan sebesar 2% jika sudah memasuki masa Dormant sesuai informasi produk dan ketentuan yang berlaku'],
            ['Suku Bunga', '3%'],
            ['Saldo/Setoran Awal Nasabah', null],
            ['Biaya Penggantian Buku', 'Rp. 15.000,-'],
            ['Biaya Penutupan', '3% dari Saldo Minimum'],
        ],
    ];
}

function tabungan_baca(array $in): array
{
    $p = tabungan_profil();
    $d = ['profil' => $p];
    $d['nama']      = riplay_in_teks($in, 'nama', 100);
    if ($d['nama'] === '') export_galat(422, 'Nama calon nasabah wajib diisi.');
    $d['identitas'] = riplay_in_teks($in, 'identitas', 40);
    $d['alamat']    = riplay_in_teks($in, 'alamat', 250);
    $d['rekening']  = riplay_in_teks($in, 'rekening', 40);
    $d['tgl_buka']  = riplay_in_tgl($in, 'tgl_buka');
    $d['setoran']   = riplay_in_angka($in, 'setoran');
    $d['petugas']   = riplay_in_teks($in, 'petugas', 100);
    if ($d['setoran'] > 0 && $d['setoran'] < $p['setoran_min']) {
        export_galat(422, 'Setoran awal ' . $p['nama'] . ' minimal Rp' . number_format($p['setoran_min'], 0, ',', '.') . '.');
    }
    return $d;
}

function tabungan_html(array $d): string
{
    $p     = $d['profil'];
    $titik = '________________________________';
    $isi   = function (string $v) use ($titik): string { return $v === '' ? $titik : h_e($v); };
    $tgl   = $d['tgl_buka'] !== '' ? date('d / m / Y', strtotime($d['tgl_buka'])) : '____ / ____ / __________';

    $h  = '<h1>RINGKASAN INFORMASI PRODUK DAN LAYANAN (RIPLAY) PERSONAL</h1>';
    $h .= '<div class="sb" style="font-size:13pt;font-weight:bold;font-style:normal;margin:1mm 0 0">' . h_e($p['judul']) . '</div>';
    $h .= '<div class="sb" style="font-size:10pt;font-style:normal;font-weight:bold;margin:0 0 1mm">PT BPR Delta Artha Kencana</div>';

    $h .= '<h2>A. DATA NASABAH</h2><table class="f">'
        . '<tr><td class="l">Nama Calon Nasabah</td><td>' . $isi($d['nama']) . '</td></tr>'
        . '<tr><td class="l">Nomor Identitas</td><td>' . $isi($d['identitas']) . '</td></tr>'
        . '<tr><td class="l">Alamat</td><td>' . $isi($d['alamat']) . '</td></tr>'
        . '<tr><td class="l">Nomor Rekening</td><td>' . $isi($d['rekening']) . '</td></tr>'
        . '<tr><td class="l">Produk yang Dipilih</td><td><span class="kb">☒</span> ' . h_e($p['nama']) . '</td></tr>'
        . '<tr><td class="l">Tanggal Pembukaan</td><td>' . $tgl . '</td></tr></table>';

    $h .= '<h2>B. RINGKASAN PRODUK YANG DIPILIH</h2><table class="f">';
    foreach ($p['ringkasan'] as $r) {
        $nilai = $r[1] === null ? ($d['setoran'] > 0 ? 'Rp ' . number_format($d['setoran'], 0, ',', '.') : 'Rp ____________________') : h_e($r[1]);
        $h .= '<tr><td class="l">' . h_e($r[0]) . '</td><td>' . $nilai . '</td></tr>';
    }
    $h .= '</table>';

    $h .= '<div class="jaga"><h2>C. MANFAAT DAN RISIKO YANG TELAH DIJELASKAN</h2><ul>'
        . '<li>Manfaat utama produk telah dijelaskan kepada calon nasabah, termasuk fungsi produk sebagai sarana simpanan.</li>'
        . '<li>Calon nasabah telah mendapatkan penjelasan mengenai ketentuan setoran awal dan bebas biaya administrasi sesuai produk yang dipilih.</li>'
        . '<li>Calon nasabah memahami bahwa suku bunga dan ketentuan produk mengikuti kebijakan Bank dan ketentuan yang berlaku.</li>'
        . '<li>Calon nasabah memahami risiko terkait transaksi rekening, keamanan data, dan persyaratan penjaminan LPS.</li></ul></div>';

    $h .= '<div class="jaga"><h2>D. BIAYA DAN KETENTUAN</h2>'
        . '<p>Biaya administrasi: Biaya sesuai informasi produk. Biaya atau ketentuan lain yang belum tercantum dalam dokumen ini akan mengikuti ketentuan resmi PT BPR Delta Artha Kencana yang berlaku dan akan diinformasikan kepada nasabah sesuai ketentuan.</p></div>';

    $h .= '<div class="jaga"><h2>E. PERNYATAAN PEMAHAMAN NASABAH</h2>'
        . '<p>Saya menyatakan telah menerima, membaca, dan memahami Ringkasan Informasi Produk dan Layanan (RIPLAY) serta telah memperoleh kesempatan untuk meminta penjelasan mengenai produk Tabungan yang saya pilih. '
        . 'Saya memahami manfaat, risiko, persyaratan, tata cara, biaya, dan ketentuan penting produk.</p>'
        . '<table class="f"><tr><th style="width:50%">Calon Nasabah</th><th>Petugas Bank</th></tr>'
        . '<tr><td style="height:22mm">Tanda Tangan:</td><td>Tanda Tangan:</td></tr>'
        . '<tr><td>Nama: ' . ($d['nama'] !== '' ? h_e($d['nama']) : $titik) . '</td><td>Nama: ' . ($d['petugas'] !== '' ? h_e($d['petugas']) : $titik) . '</td></tr>'
        . '<tr><td>Tanggal: ' . $tgl . '</td><td>Tanggal: ' . $tgl . '</td></tr></table></div>';

    $h .= '<div class="jaga"><h2>PERINGATAN PENTING</h2><ul>'
        . '<li>Nasabah wajib membaca dan memahami seluruh syarat dan ketentuan produk sebelum membuka rekening.</li>'
        . '<li>Suku bunga tabungan dan ketentuan lainnya dapat berubah sesuai kebijakan PT BPR Delta Artha Kencana dan ketentuan yang berlaku.</li>'
        . '<li>Simpanan dijamin oleh Lembaga Penjamin Simpanan (LPS) sesuai persyaratan dan batas maksimum penjaminan yang berlaku.</li>'
        . '<li>Produk ini tidak dimaksudkan sebagai produk investasi.</li>'
        . '<li>Apabila terdapat perbedaan antara informasi dalam dokumen ini dengan perjanjian/ketentuan produk, maka ketentuan dalam perjanjian dan ketentuan produk yang berlaku menjadi acuan.</li></ul></div>';

    $h .= '<div class="jaga"><h2>LAYANAN PENGADUAN</h2>'
        . '<p>Nasabah dapat menyampaikan pengaduan melalui kantor PT BPR Delta Artha Kencana atau sarana pengaduan resmi Bank. '
        . 'Informasi kontak pengaduan dan prosedur penanganan pengaduan mengikuti ketentuan yang berlaku di PT BPR Delta Artha Kencana.</p></div>';

    $h .= '<div class="jaga"><h2>PERNYATAAN</h2>'
        . '<p>Dokumen ini merupakan ringkasan informasi produk dan layanan dan bukan merupakan perjanjian pembukaan rekening. '
        . 'Ketentuan lengkap mengacu pada syarat dan ketentuan yang berlaku pada PT BPR Delta Artha Kencana.</p>'
        . '<p class="ct"><b>PT BPR Delta Artha Kencana</b><br>Penerbit Produk dan Layanan</p></div>';

    return $h;
}
