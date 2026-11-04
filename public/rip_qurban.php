<?php
// RIPLAY Personal Tabungan Pejuang Qurban (TPQ). Menu: web_tab_qurban (grup web_grup_riplay).
// Isian diketik manual, dikirim lewat POST ke api/export_riplay.php?produk=qurban untuk dibuatkan PDF. Data TIDAK disimpan di server.
require __DIR__ . '/lib.php';
page_head('RIPLAY Tabungan Pejuang Qurban', 'tab_qurban');
?>
<link rel="stylesheet" href="assets/riplay.css">

<div>
    <h1>RIPLAY Personal: Tabungan Pejuang Qurban (TPQ)</h1>
</div>

<form id="fQurban" class="rp-form" method="post" action="api/export_riplay.php?produk=qurban" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <h2 class="rp-judul">1. Data nasabah</h2>
        <div class="rp-grid">
            <label class="f">Nama nasabah *<input type="text" name="nama" maxlength="100" required></label>
            <label class="f">No. identitas (KTP)<input type="text" name="identitas" maxlength="40"></label>
            <label class="f">No. telepon / HP<input type="text" name="telepon" maxlength="30" inputmode="tel"></label>
            <label class="f full">Alamat<textarea name="alamat" maxlength="250"></textarea></label>
            <label class="f">Nomor rekening TPQ<input type="text" name="rekening" maxlength="40" placeholder="kosongkan bila belum dibuka"></label>
            <label class="f">Tanggal pembukaan<input type="date" name="tgl_buka"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">2. Detail produk yang dipilih</h2>
        <div class="rp-grid">
            <div class="f">Paket yang dipilih
                <div class="rp-opsi">
                    <label><input type="radio" name="paket" value="1"> Paket 1</label>
                    <label><input type="radio" name="paket" value="2"> Paket 2</label>
                    <label><input type="radio" name="paket" value="3"> Paket 3</label>
                    <label><input type="radio" name="paket" value="4"> Paket 4</label>
                    <label><input type="radio" name="paket" value="5"> Paket 5</label>
                    <label><input type="radio" name="paket" value="6"> Paket 6</label>
                </div>
            </div>
            <label class="f">Nominal setoran per bulan (Rp) *<input type="text" name="nominal" id="nominal" class="rp" inputmode="numeric" required></label>
            <label class="f">Tanggal setoran pertama<input type="date" name="tgl_setor" id="tglSetor"></label>
            <label class="f">Estimasi tanggal berakhir program<input type="date" name="tgl_selesai" id="tglSelesai"></label>
            <label class="f">Estimasi tanggal pencairan (1 bulan sebelum Idul Adha)<input type="date" name="tgl_cair"></label>
            <div class="f">Metode pembayaran
                <div class="rp-opsi">
                    <label><input type="radio" name="metode" value="tunai"> Tunai di Kantor Bank</label>
                    <label><input type="radio" name="metode" value="transfer"> Transfer ke Rek. PT BPR Delta Artha Kencana</label>
                </div>
            </div>
        </div>
        <div class="muted" style="margin-top:10px">Jangka waktu program tetap 10 bulan. Tanggal berakhir terisi otomatis dari tanggal setoran pertama + 10 bulan, tetapi boleh diubah. Kosongkan lagi untuk kembali otomatis.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">3. Simulasi manfaat</h2>
        <div class="rp-grid">
            <label class="f">Bonus tabungan peralatan ibadah (Rp)<input type="text" name="bonus" id="bonus" class="rp" inputmode="numeric" placeholder="sesuai paket; isi 0 bila tidak ada"></label>
        </div>
        <div class="rp-kpi" style="margin-top:14px">
            <div><span class="muted">Total setoran pokok (10 bulan)</span><b id="kTotal">–</b></div>
            <div><span class="muted">Perkiraan dana diterima saat pencairan</span><b id="kTerima">–</b></div>
        </div>
        <div class="muted">Perkiraan dana diterima = total setoran pokok + bonus. Bila bonus dikosongkan, dicetak garis isian.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">5. Penandatanganan</h2>
        <div class="rp-grid">
            <label class="f">Nama petugas BPR<input type="text" name="petugas" maxlength="100"></label>
        </div>
        <div class="muted" style="margin-top:6px">Tanggal pada kolom tanda tangan memakai tanggal pembukaan. Bagian yang dikosongkan dicetak sebagai garis isian untuk diisi tangan.</div>
    </div>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-prev" formaction="api/export_riplay.php?produk=qurban" formtarget="_blank" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</button>
            <button type="submit" class="bg-btn bg-pdf" formaction="api/export_riplay.php?produk=qurban&amp;unduh=1" formtarget="_blank" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</button>
        </div>
        <button type="reset" class="btn" id="fKosong">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat PDF dan tidak disimpan di server.</span>
    </div>
</form>

<script src="assets/riplay.js"></script>
<script>
    (function() {
        var R = window.RIPLAY, form = document.getElementById('fQurban'), TENOR = 10;   // sama dengan QURBAN_TENOR di export_riplay_qurban.php
        function $(id) { return document.getElementById(id); }
        var manualSelesai = false;

        // tanda "diisi manual" dipasang lebih dulu daripada hitung()
        $('tglSelesai').addEventListener('input', function() { manualSelesai = $('tglSelesai').value !== ''; });
        R.formatRupiah(form, hitung);
        $('tglSetor').addEventListener('input', hitung);
        $('fKosong').addEventListener('click', function() { manualSelesai = false; setTimeout(hitung, 0); });

        function hitung() {
            var nominal = R.angka($('nominal').value), total = nominal * TENOR, bonusIsi = $('bonus').value !== '';
            $('kTotal').textContent = total > 0 ? R.rp(total) : '–';
            $('kTerima').textContent = (total > 0 && bonusIsi) ? R.rp(total + R.angka($('bonus').value)) : '–';
            if (!manualSelesai) $('tglSelesai').value = $('tglSetor').value ? R.tambahBulanIso($('tglSetor').value, TENOR) : '';
        }
        hitung();
    })();
</script>
<?php page_foot();
