<?php
/**
 * Pendukung RIPLAY Personal Kredit Arthamas. Letak: folder yang sama dengan export_lib.php.
 * Dipakai oleh api/export_riplay.php. Butuh export_lib.php (export_galat, export_siap, export_kop, export_logo, h_e).
 *
 *   riplay_simulasi()  hitung simulasi pembayaran
 *   riplay_baca()      baca + validasi isian POST, hitung simulasi
 *   riplay_html()      susun isi dokumen (bagian A sampai E)
 *   riplay_pdf()       cetak RIPLAY kredit
 *   riplay_cetak()     pembuat PDF umum untuk semua produk (A4 potret, kop BPR, footer), dipakai riplay_pdf() dan produk lain
 *   riplay_slug()      nama untuk nama berkas
 */

/** Simulasi pembayaran. Bunga per tahun (%), metode: flat | efektif | anuitas; $perBulan = jumlah bulan tiap periode angsuran. */
function riplay_simulasi(int $plafon, int $tenor, float $bunga, string $metode, int $perBulan): array
{
    $n    = max(1, (int)ceil($tenor / max(1, $perBulan)));
    $i    = ($bunga / 100) * $perBulan / 12;                       // bunga per periode
    $rows = [];
    $saldo = $plafon;
    $pokokDasar = intdiv($plafon, $n);

    if ($metode === 'flat') {                                       // bunga dari plafon awal
        $totalBunga = (int)round($plafon * ($bunga / 100) * ($tenor / 12));
        $bungaDasar = intdiv($totalBunga, $n);
        for ($k = 1; $k <= $n; $k++) {
            $pokok = $pokokDasar + ($k === $n ? $plafon - $pokokDasar * $n : 0);
            $bg    = $bungaDasar + ($k === $n ? $totalBunga - $bungaDasar * $n : 0);
            $saldo -= $pokok;
            $rows[] = ['no' => $k, 'pokok' => $pokok, 'bunga' => $bg, 'angsuran' => $pokok + $bg, 'saldo' => $saldo];
        }
    } elseif ($metode === 'efektif') {                              // pokok tetap, bunga dari sisa pokok
        for ($k = 1; $k <= $n; $k++) {
            $pokok = $pokokDasar + ($k === $n ? $plafon - $pokokDasar * $n : 0);
            $bg    = (int)round($saldo * $i);
            $saldo -= $pokok;
            $rows[] = ['no' => $k, 'pokok' => $pokok, 'bunga' => $bg, 'angsuran' => $pokok + $bg, 'saldo' => $saldo];
        }
    } else {                                                        // anuitas: angsuran tetap
        $a = $i > 0 ? (int)round($plafon * $i / (1 - pow(1 + $i, -$n))) : (int)round($plafon / $n);
        for ($k = 1; $k <= $n; $k++) {
            $bg    = (int)round($saldo * $i);
            $pokok = ($k === $n || $a - $bg > $saldo) ? $saldo : $a - $bg;
            $saldo -= $pokok;
            $rows[] = ['no' => $k, 'pokok' => $pokok, 'bunga' => $bg, 'angsuran' => $pokok + $bg, 'saldo' => $saldo];
        }
    }
    $tp = 0; $tb = 0;
    foreach ($rows as $r) { $tp += $r['pokok']; $tb += $r['bunga']; }
    return ['rows' => $rows, 'angsuran1' => $rows[0]['angsuran'], 'total_pokok' => $tp, 'total_bunga' => $tb, 'total_angsuran' => $tp + $tb, 'n' => $n];
}

/** Baca dan validasi isian formulir ($_POST). Mengembalikan data siap cetak; salah isian = export_galat(422). */
function riplay_baca(array $in): array
{
    $t = function (string $k, int $maks = 200) use ($in): string {
        return trim(mb_substr((string)preg_replace('/\s+/u', ' ', (string)($in[$k] ?? '')), 0, $maks));
    };
    $angka = function (string $k) use ($in): int {
        $s = preg_replace('/\D+/', '', (string)($in[$k] ?? ''));
        return $s === '' ? 0 : (int)substr($s, 0, 13);
    };
    $pilih = function (string $k, array $boleh, string $baku = '') use ($in): string {
        $v = (string)($in[$k] ?? '');
        return in_array($v, $boleh, true) ? $v : $baku;
    };

    $d = [];
    // A. data calon debitur
    $d['nama'] = $t('nama', 100);
    if ($d['nama'] === '') export_galat(422, 'Nama calon debitur wajib diisi.');
    $d['nik'] = (string)preg_replace('/\D+/', '', (string)($in['nik'] ?? ''));
    if ($d['nik'] !== '' && strlen($d['nik']) !== 16) export_galat(422, 'NIK harus 16 digit.');
    $d['alamat']      = $t('alamat', 250);
    $d['hp']          = $t('hp', 30);
    $d['status']      = $pilih('status', ['belum', 'menikah', 'lainnya']);
    $d['status_lain'] = $t('status_lain', 60);
    $d['pekerjaan']   = $t('pekerjaan', 120);
    $d['penghasilan'] = $angka('penghasilan');

    // B. informasi kredit
    $d['jenis']       = $pilih('jenis', ['konsumtif', 'modal', 'investasi']);
    $d['tujuan']      = $t('tujuan', 200);
    $d['plafon']      = $angka('plafon');
    $d['tenor']       = (int)($in['tenor'] ?? 0);
    $d['bunga']       = (float)str_replace(',', '.', (string)($in['bunga'] ?? ''));
    $d['metode']      = $pilih('metode_bunga', ['flat', 'efektif', 'anuitas']);
    $d['angs_metode'] = $pilih('angs_metode', ['bulanan', 'lainnya'], 'bulanan');
    $d['angs_lain']   = $t('angs_lain', 60);
    $d['per_bulan']   = $d['angs_metode'] === 'bulanan' ? 1 : max(1, min(12, (int)($in['per_bulan'] ?? 1)));
    $d['agunan']      = $t('agunan', 250);
    $d['provisi']     = $angka('provisi');
    $d['biaya_lain']  = $angka('biaya_lain');
    $d['ket_biaya']   = $t('ket_biaya', 120);
    if ($d['plafon'] <= 0)                          export_galat(422, 'Plafon wajib diisi.');
    if ($d['tenor'] < 1 || $d['tenor'] > 360)       export_galat(422, 'Jangka waktu harus 1 sampai 360 bulan.');
    if ($d['bunga'] < 0 || $d['bunga'] > 100)       export_galat(422, 'Suku bunga harus antara 0 dan 100 persen per tahun.');
    if ($d['metode'] === '')                        export_galat(422, 'Pilih metode perhitungan bunga.');

    // E. penandatanganan
    $d['petugas'] = $t('petugas', 100);
    $d['tgl']     = '____/____/______';
    $tglIn = trim((string)($in['tgl'] ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tglIn, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1])) $d['tgl'] = "$m[3]/$m[2]/$m[1]";

    $d['sim'] = riplay_simulasi($d['plafon'], $d['tenor'], $d['bunga'], $d['metode'], $d['per_bulan']);
    // total kewajiban = seluruh angsuran (pokok + bunga) + provisi/admin + biaya lainnya
    $d['total_kewajiban'] = $d['sim']['total_angsuran'] + $d['provisi'] + $d['biaya_lain'];
    return $d;
}

/** Isi dokumen (tanpa kop dan footer). */
function riplay_html(array $d): string
{
    $sim = $d['sim'];
    $rp  = function (int $v): string { return 'Rp ' . number_format($v, 0, ',', '.'); };
    $e   = function ($v): string { return h_e($v); };
    $kotak = function (bool $on, string $label): string { return '<span class="kb">' . ($on ? '☒' : '☐') . '</span> ' . h_e($label); };
    $isi   = function (string $v): string { return $v === '' ? '&nbsp;' : h_e($v); };

    $labelMetode = ['flat' => 'flat', 'efektif' => 'efektif (bunga dihitung dari sisa pokok)', 'anuitas' => 'anuitas (angsuran tetap)'][$d['metode']];
    $angsText = $rp($sim['angsuran1']) . ($d['metode'] === 'efektif' ? ' (periode pertama; menurun tiap periode)' : '');
    $perText  = $d['angs_metode'] === 'bulanan' ? 'bulanan' : ($d['angs_lain'] !== '' ? $d['angs_lain'] : 'tiap ' . $d['per_bulan'] . ' bulan');
    $bungaTeks = rtrim(rtrim(number_format($d['bunga'], 2, ',', '.'), '0'), ',');

    $h  = '<h1>RINGKASAN INFORMASI PRODUK DAN LAYANAN (RIPLAY)</h1>';
    $h .= '<div class="sb">KREDIT ARTHAMAS — VERSI PERSONAL</div>';

    // A
    $h .= '<h2>A. Data Calon Debitur</h2><table class="f">'
        . '<tr><td class="l">Nama Debitur</td><td>' . $isi($d['nama']) . '</td></tr>'
        . '<tr><td class="l">NIK</td><td>' . $isi($d['nik']) . '</td></tr>'
        . '<tr><td class="l">Alamat</td><td>' . $isi($d['alamat']) . '</td></tr>'
        . '<tr><td class="l">No. HP</td><td>' . $isi($d['hp']) . '</td></tr>'
        . '<tr><td class="l">Status Perkawinan</td><td>' . $kotak($d['status'] === 'belum', 'Belum Menikah') . '&nbsp;&nbsp; ' . $kotak($d['status'] === 'menikah', 'Menikah')
            . '&nbsp;&nbsp; ' . $kotak($d['status'] === 'lainnya', 'Lainnya') . ($d['status'] === 'lainnya' && $d['status_lain'] !== '' ? ': ' . $e($d['status_lain']) : '') . '</td></tr>'
        . '<tr><td class="l">Pekerjaan/Usaha</td><td>' . $isi($d['pekerjaan']) . '</td></tr>'
        . '<tr><td class="l">Penghasilan</td><td>' . ($d['penghasilan'] > 0 ? $rp($d['penghasilan']) : 'Rp') . '</td></tr></table>';

    // B
    $h .= '<h2>B. Informasi Kredit yang Ditawarkan</h2><table class="f">'
        . '<tr><th>Komponen</th><th>Penawaran Personal</th></tr>'
        . '<tr><td class="l">Nama Produk</td><td>Kredit Arthamas</td></tr>'
        . '<tr><td class="l">Jenis Kredit</td><td>' . $kotak($d['jenis'] === 'konsumtif', 'Konsumtif') . '&nbsp;&nbsp; ' . $kotak($d['jenis'] === 'modal', 'Modal Kerja/Usaha')
            . '&nbsp;&nbsp; ' . $kotak($d['jenis'] === 'investasi', 'Investasi') . '</td></tr>'
        . '<tr><td class="l">Tujuan Kredit</td><td>' . $isi($d['tujuan']) . '</td></tr>'
        . '<tr><td class="l">Plafon</td><td>' . $rp($d['plafon']) . '</td></tr>'
        . '<tr><td class="l">Jangka Waktu</td><td>' . $d['tenor'] . ' bulan</td></tr>'
        . '<tr><td class="l">Suku Bunga</td><td>' . $bungaTeks . ' % per tahun, ' . $e($labelMetode) . '</td></tr>'
        . '<tr><td class="l">Metode Angsuran</td><td>' . $kotak($d['angs_metode'] === 'bulanan', 'Bulanan') . '&nbsp;&nbsp; ' . $kotak($d['angs_metode'] === 'lainnya', 'Lainnya')
            . ($d['angs_metode'] === 'lainnya' ? ': ' . $e($perText) : ':') . '</td></tr>'
        . '<tr><td class="l">Angsuran per Periode</td><td>' . $e($angsText) . '</td></tr>'
        . '<tr><td class="l">Total Kewajiban Pembayaran</td><td>' . $rp($d['total_kewajiban']) . '</td></tr>'
        . '<tr><td class="l">Agunan</td><td>' . $isi($d['agunan']) . '</td></tr>'
        . '<tr><td class="l">Provisi/Admin</td><td>' . ($d['provisi'] > 0 ? $rp($d['provisi']) : 'Rp') . '</td></tr>'
        . '<tr><td class="l">Biaya Lainnya</td><td>' . ($d['biaya_lain'] > 0 ? $rp($d['biaya_lain']) : 'Rp') . ($d['ket_biaya'] !== '' ? ' (' . $e($d['ket_biaya']) . ')' : '') . '</td></tr></table>'
        . '<div class="cat">Total kewajiban pembayaran = jumlah seluruh angsuran (pokok + bunga) ' . $rp($sim['total_angsuran']) . ' + provisi/admin ' . $rp($d['provisi'])
        . ' + biaya lainnya ' . $rp($d['biaya_lain']) . '.</div>';

    // C
    $h .= '<h2>C. Simulasi Pembayaran</h2><table class="s"><thead><tr><th style="width:12%">Periode</th><th>Pokok</th><th>Bunga</th><th>Angsuran</th><th>Saldo</th></tr></thead><tbody>';
    foreach ($sim['rows'] as $r) {
        $h .= '<tr><td class="c">' . $r['no'] . '</td><td class="r">' . $rp($r['pokok']) . '</td><td class="r">' . $rp($r['bunga'])
            . '</td><td class="r">' . $rp($r['angsuran']) . '</td><td class="r">' . $rp($r['saldo']) . '</td></tr>';
    }
    $h .= '<tr class="j"><td class="c">Jumlah</td><td class="r">' . $rp($sim['total_pokok']) . '</td><td class="r">' . $rp($sim['total_bunga'])
        . '</td><td class="r">' . $rp($sim['total_angsuran']) . '</td><td></td></tr></tbody></table>'
        . '<div class="cat">Simulasi dihitung dengan metode ' . $e($labelMetode) . ', ' . $sim['n'] . ' periode. Angka dibulatkan ke rupiah; selisih pembulatan ditampung pada periode terakhir. Hanya ilustrasi, jadwal resmi mengikuti perjanjian kredit.</div>';

    // D
    $h .= '<div class="jaga"><h2>D. Manfaat dan Risiko Khusus</h2><ul>'
        . '<li>Manfaat: pembiayaan untuk tujuan yang tercantum dalam penawaran kredit.</li>'
        . '<li>Risiko: kewajiban pembayaran sesuai jadwal; keterlambatan dapat menimbulkan konsekuensi sesuai perjanjian.</li>'
        . '<li>Agunan: apabila terdapat agunan, pengikatan dan penyelesaiannya mengikuti perjanjian dan ketentuan yang berlaku.</li></ul></div>';

    // E
    $h .= '<div class="jaga"><h2>E. Pernyataan Calon Debitur</h2>'
        . '<p>Saya telah menerima dan membaca RIPLAY Personal Kredit Arthamas serta memperoleh penjelasan mengenai fitur, manfaat, risiko, persyaratan, biaya, dan simulasi pembayaran. '
        . '<b>Saya memahami bahwa RIPLAY ini bukan perjanjian kredit dan keputusan kredit tetap berdasarkan hasil analisis serta persetujuan BPR.</b></p>'
        . '<table class="f"><tr><th style="width:24%">Pihak</th><th>Nama / Tanda Tangan</th><th style="width:24%">Tanggal</th></tr>'
        . '<tr><td class="l">Calon Debitur</td><td style="height:24mm;vertical-align:bottom">' . $e($d['nama']) . '</td><td style="vertical-align:bottom">' . $e($d['tgl']) . '</td></tr>'
        . '<tr><td class="l">Petugas BPR</td><td style="height:24mm;vertical-align:bottom">' . $e($d['petugas']) . '</td><td style="vertical-align:bottom">' . $e($d['tgl']) . '</td></tr></table></div>';

    return $h;
}

function riplay_slug(string $s): string { return trim((string)preg_replace('/[^A-Za-z0-9]+/', '_', $s), '_'); }

/** Cetak RIPLAY kredit. */
function riplay_pdf(array $d, bool $unduh): void
{
    riplay_cetak(riplay_html($d), 'PT BPR DELTA ARTHA KENCANA | Jl. Raya Kebonagung No. 23, Kecamatan Pakisaji, Kabupaten Malang 65162',
                 'RIPLAY_Personal_' . riplay_slug($d['nama']), $unduh);
}

/**
 * Cetak isi dokumen RIPLAY (HTML) ke PDF A4 potret dengan kop BPR, lalu kirim ke browser.
 * $footer = teks footer di atas "Halaman x dari y"; $namaBerkas tanpa tanggal dan ekstensi; $unduh false = dibuka di tab (Preview), true = diunduh.
 */
function riplay_cetak(string $isi, string $footer, string $namaBerkas, bool $unduh): void
{
    export_siap();
    @set_time_limit(120);
    @ini_set('memory_limit', '256M');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $css = 'body{font-family:dejavusanscondensed;font-size:9pt;color:#14232B}
        table.kop{width:100%;border-collapse:collapse} td.kl{width:24mm;vertical-align:middle} td.kt{text-align:center;vertical-align:middle}
        .k1{font-size:14pt;font-weight:bold} .k2{font-size:8pt;color:#4A5961} .kgaris{border-top:1.2mm solid #0F6B63;margin:2mm 0 3mm}
        h1{font-size:12.5pt;text-align:center;margin:2mm 0 0} .sb{text-align:center;font-size:10pt;font-style:italic;margin:0 0 2mm}
        h2{font-size:9.5pt;background-color:#0F6B63;color:#FFFFFF;padding:1.2mm 2mm;margin:4mm 0 1.5mm}
        table.f{width:100%;border-collapse:collapse}
        table.f td,table.f th{border:0.2mm solid #9FB0B3;padding:1.4mm 2mm;vertical-align:top;text-align:left}
        table.f th{background-color:#E7EFEE} table.f td.l{width:34%;background-color:#F3F7F6;font-weight:bold}
        table.s{width:100%;border-collapse:collapse}
        table.s th{background-color:#0F6B63;color:#FFFFFF;border:0.2mm solid #9FB0B3;padding:1.2mm;font-size:8pt}
        table.s td{border:0.2mm solid #9FB0B3;padding:0.9mm 1.4mm;font-size:8pt}
        .r{text-align:right} .c{text-align:center} tr.j td{font-weight:bold;background-color:#E7EFEE}
        .cat{font-size:7.5pt;font-style:italic;color:#4A5961;margin-top:1mm} .kb{font-family:dejavusans}
        ul{margin:0 0 0 4mm;padding:0} li{margin-bottom:1mm} p{margin:0 0 2mm;text-align:justify} .jaga{page-break-inside:avoid}
        .ts{font-size:9pt;font-style:italic;text-align:center;margin:0 0 1mm} tr.on td{font-weight:bold;background-color:#E7EFEE}
        table.tt{width:100%;border-collapse:collapse} table.tt td{width:50%;border:0.2mm solid #9FB0B3;padding:2mm;vertical-align:top}';

    $b = '';
    $kop = export_kop();
    if ($kop) {                                                    // kop BPR yang sama dengan laporan lain
        $logo = export_logo($kop);
        $b .= '<table class="kop"><tr><td class="kl">' . ($logo ? '<img src="' . $logo['uri'] . '" style="height:16mm">' : '') . '</td>'
            . '<td class="kt"><div class="k1">' . h_e($kop['nama']) . '</div>';
        foreach (array_slice((array)($kop['baris'] ?? []), 0, 3) as $t) $b .= '<div class="k2">' . h_e($t) . '</div>';
        $b .= '</td><td class="kl"></td></tr></table><div class="kgaris"></div>';
    }
    $b .= $isi;

    $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'mpdf';
    if (!is_dir($tmp)) @mkdir($tmp, 0775, true);

    $pdf = new \Mpdf\Mpdf([
        'mode' => 'utf-8', 'format' => 'A4',
        'margin_left' => 15, 'margin_right' => 15, 'margin_top' => 10, 'margin_bottom' => 18,
        'tempDir' => $tmp,
    ]);
    $pdf->SetTitle('RIPLAY Personal Kredit Arthamas');
    $pdf->SetAuthor('PT BPR Delta Artha Kencana');
    $pdf->SetHTMLFooter('<div style="text-align:center;font-size:7pt;color:#4A5961;border-top:0.2mm solid #B9C5C8;padding-top:1mm">'
        . h_e($footer) . '<br>Halaman {PAGENO} dari {nbpg}</div>');
    $pdf->WriteHTML($css, 1);                                      // 1 = CSS
    $pdf->WriteHTML($b, 2);                                        // 2 = isi

    $nama = $namaBerkas . '_' . date('Y-m-d') . '.pdf';
    while (ob_get_level() > 0) ob_end_clean();
    header('Cache-Control: no-store, max-age=0');                  // dokumen berisi data pribadi: jangan disimpan cache
    $pdf->Output($nama, $unduh ? 'D' : 'I');
}