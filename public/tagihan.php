<?php
// Tagihan AO: debitur yang perlu ditagih pada rentang TANGGAL PINJAM (hari dalam bulan), lengkap dengan kunjungan dan telepon.
// Data inti (daftar, kewajiban, tunggakan awal) dari BASELINE periode; pembayaran dan sisa tunggakan dari nominatif harian terakhir.
// Menu: web_tagihan. Tidak menyentuh database: data lewat api/api_tagihan.php (proxy ke API); cetak lewat api/export_tagihan.php.
require __DIR__ . '/lib.php';
page_head('Tagihan AO', 'tagihan');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<style>
    #tblTg td.deb { white-space: normal; min-width: 220px; }
    #tblTg td.num, #tblTg th.num { text-align: right; }
    #tblTg td.kj { white-space: normal; min-width: 190px; }
    .tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #E9F1EF; color: var(--ink); font-weight: 600; }
    .tag.good { background: #E4F2EC; color: var(--good); }
    .tag.bad { background: #FBE9E9; color: var(--bad); }
    .tag.warn { background: #FFF4DC; color: var(--warn); }
</style>

<div>
    <h1 id="judul">Tagihan AO</h1>
    <div class="sub" id="subTeks">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>
<div id="peringatan"></div>

<form id="fTg" class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end" novalidate>
    <label class="f" id="wAo" hidden>AO<select name="ao"><option value="">Semua AO</option></select></label>
    <label class="f">Tanggal awal<input type="date" name="awal"></label>
    <label class="f">Tanggal akhir<input type="date" name="akhir"></label>
    <label class="f">Urutkan
        <select name="urut">
            <option value="tgl">Tanggal pinjam (1–31)</option>
            <option value="ao">Per AO, lalu tanggal pinjam</option>
        </select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
    <div class="bg" style="margin-left:auto">
        <a class="bg-btn bg-prev" id="lihatPdf" href="#" target="_blank" rel="noopener" aria-disabled="true" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</a>
        <a class="bg-btn bg-xls" id="unduhXlsx" href="#" aria-disabled="true" title="Unduh Excel"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-xls"/></svg>Excel</a>
        <a class="bg-btn bg-pdf" id="unduhPdf" href="#" aria-disabled="true" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</a>
    </div>
</form>

<div class="row" id="kpi"></div>

<div class="card">
    <div class="scroll">
        <table id="tblTg" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th>
                    <th>AO</th>
                    <th class="num">Tgl pinjam</th>
                    <th class="num">Kewajiban bln ini</th>
                    <th class="num">Sudah bayar</th>
                    <th class="num">Kurang kewajiban</th>
                    <th class="num">Tunggakan awal</th>
                    <th class="num">Sisa tunggakan</th>
                    <th>Kunjungan</th>
                    <th>Telepon</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">
        Rentang tanggal memilih debitur menurut <b>hari pada tanggal pinjam</b> (mis. 01 sampai 07), dalam satu bulan. Daftar debitur, kewajiban bulan ini, dan tunggakan awal diambil dari <b>baseline</b> bulan itu;
        pembayaran, sisa tunggakan, dan kolek dari <b>nominatif harian terakhir</b>. Debitur tampil bila kewajiban bulan ini belum terbayar penuh atau masih ada sisa tunggakan,
        dan hilang bila keduanya sudah terpenuhi atau rekening sudah lunas (baki debet nol, atau sudah tidak ada di nominatif). Kunjungan dan telepon tidak menghilangkan debitur, hanya mengubah labelnya (dari catatan kunjungan dan catatan telepon bulan itu).
        Pokok dan bunga ditampilkan terpisah, tidak dijumlahkan. Semua angka dalam Rupiah.
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    $(function() {
        var form = document.getElementById('fTg'), f = form.elements, msgBox = document.getElementById('msg'), btn = document.getElementById('tampil'),
            nf = new Intl.NumberFormat('id-ID'), seq = 0, kunci = true, ADAKJ = true, ADATP = true;

        function $$(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function rp(v) { return v == null ? '–' : nf.format(Math.round(Number(v) || 0)); }
        function tgl(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : (s || ''); }
        function tglP(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] : (s || ''); }
        function pb(p, b, merah) {
            return '<span class="muted">Pokok</span> ' + (merah && p ? '<span class="bad">' + p + '</span>' : p) + '<br><span class="muted">Bunga</span> ' + (merah && b ? '<span class="bad">' + b + '</span>' : b);
        }
        function msg(ok, t) { msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : ''; }
        function atur(el, href) { if (href) { el.href = href; el.removeAttribute('aria-disabled'); } else { el.href = '#'; el.setAttribute('aria-disabled', 'true'); } }

        // kolom Kunjungan / Telepon (bentuk datanya sama)
        function aktivitas(d, t, kosong, sudah, tersedia) {
            if (!tersedia) return t === 'display' ? '<span class="muted">–</span>' : '';
            if (t !== 'display') return d ? d.terakhir : '';
            if (!d) return '<span class="tag bad">' + kosong + '</span>';
            var h = '<span class="tag good">' + sudah + ' ' + esc(d.n) + 'x</span> <span class="muted">terakhir ' + esc(tglP(d.terakhir)) + '</span>';
            if (d.komit) h += '<br><span class="tag ' + (d.komit_lewat ? 'bad' : 'warn') + '">' + (d.komit_lewat ? 'Janji lewat ' : 'Janji bayar ') + esc(tglP(d.komit)) + '</span>';
            if (d.ket) h += '<br><span class="muted">' + esc(d.ket) + '</span>';
            return h;
        }

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblTg').DataTable({
            data: [], order: [], pageLength: 25, lengthMenu: [25, 50, 100, 250], language: window.DT_ID,
            columns: [
                { data: 'nama', render: function(d, t, r) {
                    if (t !== 'display') return (r.nama + ' ' + r.norek + ' ' + r.alamat).trim();
                    return '<b>' + esc(r.nama || '(tanpa nama)') + '</b><br><span class="muted">' + esc(r.norek) + ' · CIF ' + esc(r.cif) + '</span>' +
                        '<br><span class="muted">kolek ' + esc(r.kolek == null ? '–' : r.kolek) + (r.xnunggak == null ? '' : ' · ' + esc(r.xnunggak) + 'x') + '</span>' +
                        (r.alamat ? '<br><span class="muted">' + esc(r.alamat) + '</span>' : '');
                }, className: 'deb' },
                { data: 'ao', render: function(d) { return esc(d); } },
                { data: 'tgl_jt', className: 'num' },
                { data: 'kw_pkk', className: 'num', render: function(d, t, r) { return t === 'display' ? pb(rp(r.kw_pkk), rp(r.kw_bg)) : r.kw_pkk + r.kw_bg; } },
                { data: 'byr_pkk', className: 'num', render: function(d, t, r) { return t === 'display' ? pb(rp(r.byr_pkk), rp(r.byr_bg)) : r.byr_pkk + r.byr_bg; } },
                { data: 'kurang_pkk', className: 'num', render: function(d, t, r) {
                    return t === 'display' ? (r.kurang_pkk == null ? '<span class="muted">–</span>' : pb(rp(r.kurang_pkk), rp(r.kurang_bg), true)) : (r.kurang_pkk || 0) + (r.kurang_bg || 0);
                } },
                { data: 'tung_awal_pkk', className: 'num', render: function(d, t, r) {
                    return t === 'display' ? (r.tung_awal_pkk == null ? '<span class="muted">–</span>' : pb(rp(r.tung_awal_pkk), rp(r.tung_awal_bg))) : (r.tung_awal_pkk || 0) + (r.tung_awal_bg || 0);
                } },
                { data: 'tung_sisa_pkk', className: 'num', render: function(d, t, r) {
                    return t === 'display' ? (r.tung_sisa_pkk == null ? '<span class="muted">–</span>' : pb(rp(r.tung_sisa_pkk), rp(r.tung_sisa_bg), true)) : (r.tung_sisa_pkk || 0) + (r.tung_sisa_bg || 0);
                } },
                { data: 'kunjungan', className: 'kj', render: function(d, t) { return aktivitas(d, t, 'Belum dikunjungi', 'Dikunjungi', ADAKJ); } },
                { data: 'telepon', className: 'kj', render: function(d, t) { return aktivitas(d, t, 'Belum ditelepon', 'Ditelepon', ADATP); } }
            ]
        });

        function kpi(l, v, c, n) { return '<div class="card kpi"><div class="muted">' + l + '</div><div class="v ' + (c || '') + '">' + v + '</div>' + (n ? '<div class="n">' + n + '</div>' : '') + '</div>'; }

        function query() {
            var q = new URLSearchParams();
            if (f.awal.value && f.akhir.value) { q.set('awal', f.awal.value); q.set('akhir', f.akhir.value); }
            if (!kunci && f.ao.value) q.set('ao', f.ao.value);
            if (f.urut.value) q.set('urut', f.urut.value);
            return q;
        }

        function muat() {
            var ini = ++seq;
            msg(true, ''); btn.disabled = true; btn.textContent = 'Memuat…';
            atur($$('lihatPdf'), ''); atur($$('unduhXlsx'), ''); atur($$('unduhPdf'), '');
            fetch('api/api_tagihan.php?' + query().toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                    return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok) throw new Error(d.error || r.status); return d; });
                })
                .then(function(d) { if (ini === seq) gambar(d); })
                .catch(function(e) { if (ini === seq) msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.'); })
                .then(function() { if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; } });
        }

        function gambar(d) {
            if (d.empty) { $$('subTeks').textContent = d.pesan || 'Belum ada data.'; table.clear().draw(); $$('kpi').innerHTML = ''; return; }
            kunci = !!d.kunci_ao;
            ADAKJ = d.kunjungan_tersedia !== false; ADATP = d.telepon_tersedia !== false;

            // akun bukan AO: boleh memilih AO, termasuk "Semua AO"
            $$('wAo').hidden = kunci;
            if (!kunci) {
                var h = '<option value="">Semua AO</option>';
                (d.aos || []).forEach(function(a) { h += '<option value="' + esc(a.v) + '"' + (a.v === d.sel.ao ? ' selected' : '') + '>' + esc(a.l) + '</option>'; });
                f.ao.innerHTML = h;
            }
            f.awal.value = d.sel.awal; f.akhir.value = d.sel.akhir; f.urut.value = d.sel.urut;
            table.column(1).visible(!kunci && !d.sel.ao);                 // kolom AO hanya berguna bila daftar memuat lebih dari satu AO

            $$('judul').textContent = kunci ? 'Tagihan saya' : 'Tagihan AO';
            $$('subTeks').textContent = d.ao.nama + ' · tanggal pinjam ' + d.hari_dari + '–' + d.hari_sampai + ' · baseline ' + tgl(d.posisi_baseline) + ' · pembayaran sampai posisi ' + tgl(d.posisi_akhir);
            $$('peringatan').innerHTML = (d.peringatan || []).map(function(t) { return '<div class="flash info">' + esc(t) + '</div>'; }).join('');

            var r = d.ringkas;
            $$('kpi').innerHTML =
                kpi('Perlu ditagih', nf.format(r.n), '', 'dari ' + nf.format(r.dinilai) + ' debitur pada rentang · lunas ' + nf.format(r.lunas_keluar)) +
                kpi('Belum bayar sama sekali', nf.format(r.belum_bayar), r.belum_bayar ? 'bad' : '', 'sebagian ' + nf.format(r.sebagian) + ' · kewajiban lunas ' + nf.format(r.kewajiban_ok)) +
                kpi('Belum dikunjungi', ADAKJ ? nf.format(r.belum_kunjung) : '–', ADAKJ && r.belum_kunjung ? 'bad' : 'good', ADAKJ ? 'sudah dikunjungi ' + nf.format(r.sudah_kunjung) : 'data kunjungan belum terbaca') +
                kpi('Belum ditelepon', ADATP ? nf.format(r.belum_telepon) : '–', ADATP && r.belum_telepon ? 'bad' : 'good', ADATP ? 'sudah ditelepon ' + nf.format(r.sudah_telepon) : 'data telepon belum terbaca') +
                kpi('Janji bayar', nf.format(r.janji), '', 'janji lewat ' + nf.format(r.janji_lewat)) +
                kpi('Kurang kewajiban', 'Pokok ' + rp(r.kurang_pkk), 'bad', 'Bunga ' + rp(r.kurang_bg)) +
                kpi('Sisa tunggakan', 'Pokok ' + rp(r.tung_sisa_pkk), '', 'Bunga ' + rp(r.tung_sisa_bg) + ' · awal ' + rp(r.tung_awal_pkk) + ' / ' + rp(r.tung_awal_bg));
            table.clear().rows.add(d.rows || []).draw();

            // tombol cetak mengikuti filter yang SEDANG tampil (dari server)
            var p = new URLSearchParams({ awal: d.sel.awal, akhir: d.sel.akhir, urut: d.sel.urut });
            if (!kunci && d.sel.ao) p.set('ao', d.sel.ao);
            var u = 'api/export_tagihan.php?' + p.toString();
            atur($$('lihatPdf'), u + '&format=pdf');
            atur($$('unduhXlsx'), u + '&format=xlsx');
            atur($$('unduhPdf'), u + '&format=pdf&unduh=1');
        }

        form.addEventListener('submit', function(e) { e.preventDefault(); muat(); });
        muat();
    });
</script>
<?php page_foot();