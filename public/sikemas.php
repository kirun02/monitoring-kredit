<?php
// RIPLAY Personal SikEMAS (Simpanan Kepemilikan Emas). Menu: web_sikemas (grup web_grup_riplay).
// Isian diketik manual, dikirim lewat POST ke api/export_riplay.php?produk=sikemas untuk dibuatkan PDF. Data TIDAK disimpan di server.
require __DIR__ . '/lib.php';
page_head('RIPLAY SikEMAS', 'sikemas');
?>
<link rel="stylesheet" href="assets/riplay.css">

<div>
    <h1>RIPLAY Personal: SikEMAS</h1>
    <div class="sub">Simpanan Kepemilikan Emas (sinergi bersama Galeri24 Pegadaian). Isi manual, lalu cetak PDF.</div>
</div>

<form id="fSik" class="rp-form" method="post" action="api/export_riplay.php?produk=sikemas" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <h2 class="rp-judul">1. Data nasabah</h2>
        <div class="rp-grid">
            <label class="f">Nama lengkap nasabah *<input type="text" name="nama" maxlength="100" required></label>
            <label class="f">Nomor identitas (KTP, 16 digit)<input type="text" name="nik" maxlength="16" inputmode="numeric" pattern="\d{16}" title="KTP terdiri dari 16 digit angka"></label>
            <label class="f">Usia (tahun)<input type="number" name="usia" min="1" max="120" step="1"></label>
            <label class="f full">Alamat<textarea name="alamat" maxlength="250"></textarea></label>
            <label class="f">Nomor telepon<input type="text" name="telepon" maxlength="30" inputmode="tel"></label>
            <label class="f">Nomor CIF / rekening<input type="text" name="cif" maxlength="40" placeholder="kosongkan bila nasabah baru (belum ada)"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">2. Data transaksi pembiayaan emas</h2>
        <div class="rp-grid">
            <label class="f">Nomor perjanjian pembiayaan<input type="text" name="no_perjanjian" maxlength="60" placeholder="kosongkan bila belum akad"></label>
            <label class="f">Jenis emas<input type="text" name="jenis" maxlength="80" value="Logam Mulia"></label>
            <label class="f">Berat emas (gram) *<input type="text" name="berat" id="berat" inputmode="decimal" placeholder="mis. 2 atau 2,5" required></label>
            <label class="f">Harga emas saat akad (Rp per gram) *<input type="text" name="harga_gram" id="hargaGram" class="rp" inputmode="numeric" required></label>
            <label class="f">Uang muka / DP (Rp)<input type="text" name="dp" id="dp" class="rp" inputmode="numeric"></label>
            <label class="f">Nilai pembiayaan / pokok (Rp)<input type="text" name="nilai" id="nilai" class="rp" inputmode="numeric" placeholder="otomatis: harga emas total dikurangi DP"></label>
            <label class="f">Suku bunga (%) *<input type="text" name="bunga" id="bunga" inputmode="decimal" placeholder="mis. 1 atau 1,2" required></label>
            <label class="f">Satuan bunga
                <select name="bunga_satuan" id="satuan">
                    <option value="bulan">per bulan</option>
                    <option value="tahun">per tahun</option>
                </select>
            </label>
            <label class="f">Perhitungan bunga
                <select name="metode_bunga" id="metode">
                    <option value="flat">Flat (bunga dari pokok awal)</option>
                    <option value="efektif">Efektif (bunga dari sisa pokok, pokok tetap)</option>
                    <option value="anuitas">Anuitas (angsuran tetap)</option>
                </select>
            </label>
            <label class="f">Jangka waktu / tenor (bulan) *<input type="number" name="tenor" id="tenor" min="1" max="24" step="1" required></label>
            <label class="f">Besar angsuran per bulan (Rp)<input type="text" name="angsuran" id="angsuran" class="rp" inputmode="numeric" placeholder="otomatis dari simulasi"></label>
            <label class="f">Tanggal akad<input type="date" name="tgl_akad" id="tglAkad"></label>
        </div>
        <div class="rp-kpi" style="margin-top:14px">
            <div><span class="muted">Harga emas total</span><b id="kTotal">–</b></div>
            <div><span class="muted">Biaya administrasi (0,5%)</span><b id="kAdmin">–</b></div>
            <div><span class="muted">Biaya provisi (0,5%)</span><b id="kProvisi">–</b></div>
            <div><span class="muted">Tanggal jatuh tempo / lunas</span><b id="kTempo">–</b></div>
        </div>
        <div class="muted" id="kTenor"></div>
        <div class="muted" style="margin-top:6px">Nilai pembiayaan dan angsuran terisi otomatis, tetapi boleh diubah. Kosongkan lagi untuk kembali otomatis. Angka final yang dicetak ada di PDF.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">7. Penandatanganan</h2>
        <div class="rp-grid">
            <label class="f">Nama petugas BPR<input type="text" name="petugas" maxlength="100"></label>
        </div>
        <div class="muted" style="margin-top:6px">Tanggal pada kolom tanda tangan memakai tanggal akad. Bagian yang dikosongkan (CIF, nomor perjanjian, tanggal akad) dicetak sebagai garis isian untuk diisi tangan setelah pengajuan disetujui.</div>
    </div>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-prev" formaction="api/export_riplay.php?produk=sikemas" formtarget="_blank" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</button>
            <button type="submit" class="bg-btn bg-pdf" formaction="api/export_riplay.php?produk=sikemas&amp;unduh=1" formtarget="_blank" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</button>
        </div>
        <button type="reset" class="btn" id="fKosong">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat PDF dan tidak disimpan di server.</span>
    </div>
</form>

<script src="assets/riplay.js"></script>
<script>
    (function() {
        var R = window.RIPLAY, form = document.getElementById('fSik');
        function $(id) { return document.getElementById(id); }
        var manual = { nilai: false, angs: false };

        // tanda "diisi manual" dipasang LEBIH DULU daripada pemformat rupiah, supaya hitung() tidak menimpa isian yang sedang diketik
        $('nilai').addEventListener('input', function() { manual.nilai = $('nilai').value !== ''; });
        $('angsuran').addEventListener('input', function() { manual.angs = $('angsuran').value !== ''; });
        R.formatRupiah(form, hitung);
        ['berat', 'bunga', 'satuan', 'metode', 'tenor', 'tglAkad'].forEach(function(id) { $(id).addEventListener('input', hitung); });
        $('fKosong').addEventListener('click', function() { manual.nilai = manual.angs = false; setTimeout(hitung, 0); });

        function tenorMaks(g) { return g > 5 ? 24 : (g > 2 ? 18 : 12); }   // sama dengan sikemas_tenor_maks()

        function hitung() {
            var berat = R.desimal($('berat').value), harga = R.angka($('hargaGram').value), dp = R.angka($('dp').value);
            var total = Math.round(berat * harga);
            $('kTotal').textContent = total > 0 ? R.rp(total) : '–';

            if (!manual.nilai) $('nilai').value = (total - dp) > 0 ? R.nf.format(total - dp) : '';
            var nilai = R.angka($('nilai').value);

            if (berat > 0) {
                var mk = tenorMaks(berat);
                $('tenor').max = mk;
                $('kTenor').textContent = 'Tenor maksimal untuk emas ' + String(berat).replace('.', ',') + ' gram: ' + mk + ' bulan.';
            } else {
                $('tenor').max = 24;
                $('kTenor').textContent = '';
            }

            $('kAdmin').textContent = nilai > 0 ? R.rp(nilai * 0.005) : '–';
            $('kProvisi').textContent = nilai > 0 ? R.rp(nilai * 0.005) : '–';

            var tenor = parseInt($('tenor').value, 10) || 0, bunga = R.desimal($('bunga').value);
            if (nilai > 0 && tenor >= 1 && tenor <= 24 && bunga >= 0 && bunga <= 100 && !manual.angs) {
                var tahun = $('satuan').value === 'bulan' ? bunga * 12 : bunga;
                $('angsuran').value = R.nf.format(R.simulasi(nilai, tenor, tahun, $('metode').value, 1).angs1);
            } else if (!manual.angs) {
                $('angsuran').value = '';
            }
            $('kTempo').textContent = ($('tglAkad').value && tenor >= 1) ? R.tambahBulan($('tglAkad').value, tenor) : '–';
        }
        hitung();
    })();
</script>
<?php page_foot();