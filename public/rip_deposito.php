<?php
// RIPLAY Personal Deposito Artha Mas. Menu: web_depo_arthamas (grup web_grup_riplay).
// Isian diketik manual, dikirim lewat POST ke api/export_riplay.php?produk=deposito untuk dibuatkan PDF. Data TIDAK disimpan di server.
require __DIR__ . '/lib.php';
page_head('RIPLAY Deposito Artha Mas', 'depo_arthamas');
?>
<link rel="stylesheet" href="assets/riplay.css">

<div>
    <h1>RIPLAY Personal: Deposito Artha Mas</h1>
    <div class="sub">Isi manual, lalu cetak PDF. Nomor CIF/rekening dan nomor bilyet boleh dikosongkan bila belum ada.</div>
</div>

<form id="fDepo" class="rp-form" method="post" action="api/export_riplay.php?produk=deposito" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <h2 class="rp-judul">1. Data nasabah</h2>
        <div class="rp-grid">
            <label class="f">Nama lengkap nasabah *<input type="text" name="nama" maxlength="100" required></label>
            <label class="f">Nomor identitas (KTP/SIM/Paspor)<input type="text" name="identitas" maxlength="40"></label>
            <label class="f">Nomor telepon<input type="text" name="telepon" maxlength="30" inputmode="tel"></label>
            <label class="f full">Alamat<textarea name="alamat" maxlength="250"></textarea></label>
            <label class="f">Nomor CIF / rekening<input type="text" name="cif" maxlength="40" placeholder="kosongkan bila nasabah baru (belum ada)"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">2. Data transaksi deposito</h2>
        <div class="rp-grid">
            <label class="f">Nomor bilyet / sertifikat deposito<input type="text" name="bilyet" maxlength="60" placeholder="kosongkan bila belum terbit"></label>
            <label class="f">Nominal deposito (Rp) *<input type="text" name="nominal" class="rp" inputmode="numeric" required></label>
            <label class="f">Jangka waktu (bulan) *<input type="number" name="tenor" id="tenor" min="1" max="120" step="1" list="daftarTenor" required>
                <datalist id="daftarTenor"><option value="1"><option value="3"><option value="6"><option value="12"><option value="24"></datalist>
            </label>
            <label class="f">Tanggal pembukaan<input type="date" name="tgl_buka" id="tglBuka"></label>
            <label class="f">Tanggal jatuh tempo<input type="date" name="tgl_tempo" id="tglTempo"></label>
            <label class="f">Suku bunga (% per tahun) *<input type="text" name="bunga" inputmode="decimal" placeholder="mis. 6 atau 6,25" required></label>
            <div class="f">Status perpanjangan
                <div class="rp-opsi">
                    <label><input type="radio" name="aro" value="aro"> ARO</label>
                    <label><input type="radio" name="aro" value="non_aro"> Non-ARO</label>
                </div>
            </div>
            <label class="f">Rekening sumber dana / pencairan bunga<input type="text" name="rek_sumber" maxlength="120"></label>
            <div class="f">Digunakan sebagai jaminan kredit
                <div class="rp-opsi">
                    <label><input type="radio" name="jaminan" value="ya"> Ya</label>
                    <label><input type="radio" name="jaminan" value="tidak"> Tidak</label>
                </div>
            </div>
        </div>
        <div class="muted" style="margin-top:10px">Tanggal jatuh tempo terisi otomatis dari tanggal pembukaan + jangka waktu, tetapi boleh diubah. Kosongkan lagi untuk kembali otomatis.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">6. Penandatanganan</h2>
        <div class="rp-grid">
            <label class="f">Nama petugas BPR<input type="text" name="petugas" maxlength="100"></label>
        </div>
        <div class="muted" style="margin-top:6px">Tanggal pada kolom tanda tangan memakai tanggal pembukaan. Bagian yang dikosongkan dicetak sebagai garis isian untuk diisi tangan.</div>
    </div>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-prev" formaction="api/export_riplay.php?produk=deposito" formtarget="_blank" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</button>
            <button type="submit" class="bg-btn bg-pdf" formaction="api/export_riplay.php?produk=deposito&amp;unduh=1" formtarget="_blank" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</button>
        </div>
        <button type="reset" class="btn" id="fKosong">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat PDF dan tidak disimpan di server.</span>
    </div>
</form>

<script src="assets/riplay.js"></script>
<script>
    (function() {
        var R = window.RIPLAY, form = document.getElementById('fDepo');
        function $(id) { return document.getElementById(id); }
        var manualTempo = false;

        // tanda "diisi manual" dipasang lebih dulu daripada hitung()
        $('tglTempo').addEventListener('input', function() { manualTempo = $('tglTempo').value !== ''; });
        R.formatRupiah(form);
        ['tglBuka', 'tenor'].forEach(function(id) { $(id).addEventListener('input', hitung); });
        $('fKosong').addEventListener('click', function() { manualTempo = false; });

        function hitung() {
            var tenor = parseInt($('tenor').value, 10) || 0;
            if (!manualTempo) $('tglTempo').value = ($('tglBuka').value && tenor >= 1) ? R.tambahBulanIso($('tglBuka').value, tenor) : '';
        }
    })();
</script>
<?php page_foot();
