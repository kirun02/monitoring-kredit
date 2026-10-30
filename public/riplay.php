<?php
// RIPLAY Personal Kredit Arthamas untuk calon debitur baru. Menu: web_kredit_arthamas (grup web_grup_riplay).
// Isian diketik manual, dikirim lewat POST ke api/export_riplay.php untuk dibuatkan PDF.
// Data TIDAK disimpan di server dan TIDAK masuk URL (POST), jadi tidak tercatat di log akses.
require __DIR__ . '/lib.php';
page_head('RIPLAY Kredit Arthamas', 'kredit_arthamas');
?>
<style>
    #fRiplay .rp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px 18px; }
    #fRiplay .rp-grid .full { grid-column: 1 / -1; }
    #fRiplay input[type=text], #fRiplay input[type=date], #fRiplay input[type=number], #fRiplay textarea {
        width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #c5d3d1; border-radius: 8px; font: inherit; background: #fff;
    }
    #fRiplay textarea { min-height: 64px; resize: vertical; }
    #fRiplay select { width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #c5d3d1; border-radius: 8px; font: inherit; background: #fff; }
    #fRiplay input:focus, #fRiplay textarea:focus, #fRiplay select:focus { outline: 3px solid rgba(15, 107, 99, .25); border-color: #0F6B63; }
    .rp-opsi { display: flex; flex-wrap: wrap; gap: 8px 18px; padding-top: 6px; }
    .rp-opsi label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .rp-judul { margin: 0 0 12px; font-size: 1.05rem; color: #0F6B63; }
    .rp-kpi { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin-bottom: 10px; }
    .rp-kpi div { background: #F3F7F6; border-radius: 10px; padding: 10px 12px; }
    .rp-kpi b { display: block; font-size: 1.05rem; }
    #tblSim { width: 100%; border-collapse: collapse; margin-top: 8px; }
    #tblSim th, #tblSim td { padding: 5px 8px; border-bottom: 1px solid #e1e8e7; text-align: right; }
    #tblSim th:first-child, #tblSim td:first-child { text-align: center; }
    #tblSim thead th { background: #0F6B63; color: #fff; }
    #angsLainBox:not([hidden]) { display: grid; gap: 8px; margin-top: 8px; }
    .rp-aksi { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
</style>

<div>
    <h1>RIPLAY Personal: Kredit Arthamas</h1>
    <div class="sub">Ringkasan Informasi Produk dan Layanan Kredit Arthamas untuk calon debitur baru. Isi manual, lalu cetak PDF.</div>
</div>

<form id="fRiplay" method="post" action="api/export_riplay.php?produk=kredit" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <div class="card">
        <h2 class="rp-judul">A. Data calon debitur</h2>
        <div class="rp-grid">
            <label class="f">Nama debitur *<input type="text" name="nama" maxlength="100" required></label>
            <label class="f">NIK (16 digit)<input type="text" name="nik" maxlength="16" inputmode="numeric" pattern="\d{16}" title="NIK terdiri dari 16 digit angka"></label>
            <label class="f">No. HP<input type="text" name="hp" maxlength="30" inputmode="tel"></label>
            <label class="f full">Alamat<textarea name="alamat" maxlength="250"></textarea></label>
            <div class="f">Status perkawinan
                <div class="rp-opsi">
                    <label><input type="radio" name="status" value="belum"> Belum Menikah</label>
                    <label><input type="radio" name="status" value="menikah"> Menikah</label>
                    <label><input type="radio" name="status" value="lainnya"> Lainnya</label>
                </div>
                <input type="text" name="status_lain" id="statusLain" maxlength="60" placeholder="Sebutkan" hidden style="margin-top:8px">
            </div>
            <label class="f">Pekerjaan/Usaha<input type="text" name="pekerjaan" maxlength="120"></label>
            <label class="f">Penghasilan (Rp)<input type="text" name="penghasilan" class="rp" inputmode="numeric"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">B. Informasi kredit yang ditawarkan</h2>
        <div class="rp-grid">
            <label class="f">Nama produk<input type="text" value="Kredit Arthamas" readonly></label>
            <div class="f">Jenis kredit
                <div class="rp-opsi">
                    <label><input type="radio" name="jenis" value="konsumtif"> Konsumtif</label>
                    <label><input type="radio" name="jenis" value="modal"> Modal Kerja/Usaha</label>
                    <label><input type="radio" name="jenis" value="investasi"> Investasi</label>
                </div>
            </div>
            <label class="f full">Tujuan kredit<input type="text" name="tujuan" maxlength="200"></label>
            <label class="f">Plafon (Rp) *<input type="text" name="plafon" id="plafon" class="rp" inputmode="numeric" required></label>
            <label class="f">Jangka waktu (bulan) *<input type="number" name="tenor" id="tenor" min="1" max="360" step="1" required></label>
            <label class="f">Suku bunga (% per tahun) *<input type="text" name="bunga" id="bunga" inputmode="decimal" placeholder="mis. 12 atau 12,5" required></label>
            <label class="f">Perhitungan bunga *
                <select name="metode_bunga" id="metode" required>
                    <option value="flat">Flat (bunga dari plafon awal)</option>
                    <option value="efektif">Efektif (bunga dari sisa pokok, pokok tetap)</option>
                    <option value="anuitas">Anuitas (angsuran tetap)</option>
                </select>
            </label>
            <div class="f">Metode angsuran
                <div class="rp-opsi">
                    <label><input type="radio" name="angs_metode" value="bulanan" checked> Bulanan</label>
                    <label><input type="radio" name="angs_metode" value="lainnya"> Lainnya</label>
                </div>
                <div id="angsLainBox" hidden>
                    <input type="text" name="angs_lain" maxlength="60" placeholder="Keterangan, mis. triwulan">
                    <input type="number" name="per_bulan" id="perBulan" min="1" max="12" step="1" value="3" title="Jumlah bulan tiap periode angsuran">
                    <span class="muted">Jumlah bulan tiap periode angsuran (1–12)</span>
                </div>
            </div>
            <label class="f full">Agunan<input type="text" name="agunan" maxlength="250"></label>
            <label class="f">Provisi/Admin (Rp)<input type="text" name="provisi" id="provisi" class="rp" inputmode="numeric"></label>
            <label class="f">Biaya lainnya (Rp)<input type="text" name="biaya_lain" id="biayaLain" class="rp" inputmode="numeric"></label>
            <label class="f">Keterangan biaya lainnya<input type="text" name="ket_biaya" maxlength="120" placeholder="opsional, mis. asuransi"></label>
        </div>
    </div>

    <div class="card">
        <h2 class="rp-judul">C. Simulasi pembayaran <span class="muted" style="font-weight:normal">(pratinjau; angka final ada di PDF)</span></h2>
        <div id="simKosong" class="muted">Isi plafon, jangka waktu, suku bunga, dan perhitungan bunga untuk melihat simulasi.</div>
        <div id="simIsi" hidden>
            <div class="rp-kpi">
                <div><span class="muted">Angsuran per periode</span><b id="kAngs">–</b></div>
                <div><span class="muted">Jumlah periode</span><b id="kN">–</b></div>
                <div><span class="muted">Total bunga</span><b id="kBunga">–</b></div>
                <div><span class="muted">Total angsuran (pokok + bunga)</span><b id="kTotAngs">–</b></div>
                <div><span class="muted">Total kewajiban pembayaran</span><b id="kTotal">–</b></div>
            </div>
            <div class="muted" id="kCatatan"></div>
            <details style="margin-top:8px">
                <summary>Lihat tabel simulasi</summary>
                <div class="scroll">
                    <table id="tblSim">
                        <thead><tr><th>Periode</th><th>Pokok</th><th>Bunga</th><th>Angsuran</th><th>Saldo</th></tr></thead>
                        <tbody></tbody>
                    </table>
                </div>
            </details>
        </div>
        <div class="muted" style="margin-top:8px">Total kewajiban pembayaran = jumlah seluruh angsuran + provisi/admin + biaya lainnya. Angka dibulatkan ke rupiah, selisih pembulatan ditampung di periode terakhir.</div>
    </div>

    <div class="card">
        <h2 class="rp-judul">E. Penandatanganan</h2>
        <div class="rp-grid">
            <label class="f">Nama petugas BPR<input type="text" name="petugas" maxlength="100"></label>
            <label class="f">Tanggal (kosongkan untuk diisi tangan)<input type="date" name="tgl"></label>
        </div>
    </div>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-prev" formaction="api/export_riplay.php?produk=kredit" formtarget="_blank" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</button>
            <button type="submit" class="bg-btn bg-pdf" formaction="api/export_riplay.php?produk=kredit&amp;unduh=1" formtarget="_blank" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</button>
        </div>
        <button type="reset" class="btn" id="fKosong">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat PDF dan tidak disimpan di server.</span>
    </div>
</form>

<script>
    (function() {
        var form = document.getElementById('fRiplay');
        var nf = new Intl.NumberFormat('id-ID');

        function $(id) { return document.getElementById(id); }
        function angka(v) { var s = String(v || '').replace(/\D+/g, ''); return s ? parseInt(s.slice(0, 13), 10) : 0; }
        function rp(v) { return 'Rp ' + nf.format(Math.round(v)); }

        // isian rupiah: tampil dengan titik ribuan
        Array.prototype.forEach.call(form.querySelectorAll('input.rp'), function(el) {
            el.addEventListener('input', function() {
                var n = angka(el.value);
                el.value = n ? nf.format(n) : '';
                hitung();
            });
        });

        // pilihan "Lainnya"
        function atur() {
            $('statusLain').hidden = form.elements['status'].value !== 'lainnya';
            $('angsLainBox').hidden = form.elements['angs_metode'].value !== 'lainnya';
            hitung();
        }
        Array.prototype.forEach.call(form.querySelectorAll('input[type=radio]'), function(el) { el.addEventListener('change', atur); });
        ['tenor', 'bunga', 'metode', 'perBulan'].forEach(function(id) { $(id).addEventListener('input', hitung); });
        $('fKosong').addEventListener('click', function() { setTimeout(atur, 0); });

        // simulasi: harus sama dengan riplay_simulasi() di export_lib_riplay.php (angka resmi dihitung server)
        function simulasi(P, tenor, bunga, metode, per) {
            var n = Math.max(1, Math.ceil(tenor / Math.max(1, per)));
            var i = (bunga / 100) * per / 12;
            var rows = [], saldo = P, pokokDasar = Math.floor(P / n), k, pokok, bg;
            if (metode === 'flat') {
                var totalBunga = Math.round(P * (bunga / 100) * (tenor / 12)), bungaDasar = Math.floor(totalBunga / n);
                for (k = 1; k <= n; k++) {
                    pokok = pokokDasar + (k === n ? P - pokokDasar * n : 0);
                    bg = bungaDasar + (k === n ? totalBunga - bungaDasar * n : 0);
                    saldo -= pokok;
                    rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
                }
            } else if (metode === 'efektif') {
                for (k = 1; k <= n; k++) {
                    pokok = pokokDasar + (k === n ? P - pokokDasar * n : 0);
                    bg = Math.round(saldo * i);
                    saldo -= pokok;
                    rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
                }
            } else {
                var a = i > 0 ? Math.round(P * i / (1 - Math.pow(1 + i, -n))) : Math.round(P / n);
                for (k = 1; k <= n; k++) {
                    bg = Math.round(saldo * i);
                    pokok = (k === n || a - bg > saldo) ? saldo : a - bg;
                    saldo -= pokok;
                    rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
                }
            }
            var tp = 0, tb = 0;
            rows.forEach(function(r) { tp += r.pokok; tb += r.bunga; });
            return { rows: rows, n: n, angs1: rows[0].angsuran, tp: tp, tb: tb, ta: tp + tb };
        }

        function hitung() {
            var P = angka($('plafon').value), tenor = parseInt($('tenor').value, 10) || 0;
            var bunga = parseFloat(String($('bunga').value).replace(',', '.'));
            var per = form.elements['angs_metode'].value === 'bulanan' ? 1 : Math.max(1, Math.min(12, parseInt($('perBulan').value, 10) || 1));
            var ok = P > 0 && tenor >= 1 && tenor <= 360 && isFinite(bunga) && bunga >= 0 && bunga <= 100;
            $('simKosong').hidden = ok;
            $('simIsi').hidden = !ok;
            if (!ok) return;

            var metode = $('metode').value, s = simulasi(P, tenor, bunga, metode, per);
            var prov = angka($('provisi').value), lain = angka($('biayaLain').value);
            $('kAngs').textContent = rp(s.angs1) + (metode === 'efektif' ? ' (periode 1)' : '');
            $('kN').textContent = s.n + ' periode';
            $('kBunga').textContent = rp(s.tb);
            $('kTotAngs').textContent = rp(s.ta);
            $('kTotal').textContent = rp(s.ta + prov + lain);
            $('kCatatan').textContent = 'Total kewajiban = ' + rp(s.ta) + ' (angsuran) + ' + rp(prov) + ' (provisi/admin) + ' + rp(lain) + ' (biaya lainnya).';

            var tb = document.querySelector('#tblSim tbody');
            tb.innerHTML = s.rows.map(function(r) {
                return '<tr><td>' + r.no + '</td><td>' + nf.format(r.pokok) + '</td><td>' + nf.format(r.bunga) + '</td><td>' + nf.format(r.angsuran) + '</td><td>' + nf.format(r.saldo) + '</td></tr>';
            }).join('');
        }

        atur();
    })();
</script>
<?php page_foot();