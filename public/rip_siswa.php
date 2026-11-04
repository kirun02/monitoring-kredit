<?php
// RIPLAY Personal Tabungan Siswa Arthamas. Menu: web_tab_siswa (grup web_grup_riplay).
// Isian diketik manual, dikirim lewat POST ke api/export_riplay.php?produk=siswa untuk dibuatkan PDF. Data TIDAK disimpan di server.
require __DIR__ . '/lib.php';
page_head('RIPLAY Tabungan Siswa Arthamas', 'tab_siswa');
?>
<link rel="stylesheet" href="assets/riplay.css">

<div>
    <h1>RIPLAY Personal: Tabungan Siswa Arthamas</h1>
</div>

<form id="fSiswa" class="rp-form" method="post" action="api/export_riplay.php?produk=siswa" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <h2 class="rp-judul">A. Data nasabah</h2>
        <div class="rp-grid">
            <label class="f">Nama calon nasabah *<input type="text" name="nama" maxlength="100" required></label>
            <label class="f">Nomor identitas<input type="text" name="identitas" maxlength="40"></label>
            <label class="f full">Alamat<textarea name="alamat" maxlength="250"></textarea></label>
            <label class="f">Nomor rekening<input type="text" name="rekening" maxlength="40" placeholder="kosongkan bila belum dibuka"></label>
            <label class="f">Tanggal pembukaan<input type="date" name="tgl_buka"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">B. Setoran awal nasabah</h2>
        <div class="rp-grid">
            <label class="f">Saldo/setoran awal nasabah (Rp)<input type="text" name="setoran" id="setoran" class="rp" inputmode="numeric" placeholder="minimal Rp 20.000"></label>
        </div>
        <div class="muted" style="margin-top:8px">Bagian lain ringkasan produk (setoran minimal Rp20.000, suku bunga 3%, bebas biaya administrasi, penggantian buku, dan penutupan) sudah tetap dan tercetak otomatis. Kosongkan setoran awal bila belum diketahui.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">E. Penandatanganan</h2>
        <div class="rp-grid">
            <label class="f">Nama petugas bank<input type="text" name="petugas" maxlength="100"></label>
        </div>
        <div class="muted" style="margin-top:6px">Tanggal pada kolom tanda tangan memakai tanggal pembukaan. Bagian yang dikosongkan dicetak sebagai garis isian untuk diisi tangan.</div>
    </div>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-prev" formaction="api/export_riplay.php?produk=siswa" formtarget="_blank" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</button>
            <button type="submit" class="bg-btn bg-pdf" formaction="api/export_riplay.php?produk=siswa&amp;unduh=1" formtarget="_blank" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</button>
        </div>
        <button type="reset" class="btn">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat PDF dan tidak disimpan di server.</span>
    </div>
</form>

<script src="assets/riplay.js"></script>
<script>
    window.RIPLAY.formatRupiah(document.getElementById('fSiswa'));
</script>
<?php page_foot();
