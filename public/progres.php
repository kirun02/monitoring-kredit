<?php
// Progres mingguan: rekening yang belum bayar di posisi tanggal awal, dilacak tiap minggu sampai tanggal akhir.
// Tidak menyentuh database: data lewat api/api_progres.php (proxy ke API).
require __DIR__ . '/lib.php';

page_head('Progres mingguan', 'progres');
?>
<style>
    #tblPg td.num, #tblPg th.num { text-align: right; }
    #tblPg td.mgg { white-space: normal; min-width: 170px; text-align: left; }
    #tblPg tfoot td { font-weight: 600; border-top: 2px solid var(--line); border-bottom: 0; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    a.btn { text-decoration: none; display: inline-block; }
    a.btn[aria-disabled="true"] { opacity: .45; pointer-events: none; }
    .pct { min-width: 110px; }
    @media print { .top, .no-print { display: none !important; } main { padding: 0; } .card { border: 0; padding: 0; } }
</style>

<div>
    <h1>Progres mingguan</h1>
    <div class="sub" id="subTeks">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fPg" class="card filter no-print" novalidate>
    <label class="f">Tanggal awal<input type="date" name="awal"></label>
    <label class="f">Tanggal akhir<input type="date" name="akhir"></label>
    <label class="f">AO
        <select name="ao"><option value="">Semua AO</option></select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
</form>

<div id="peringatan"></div>

<div class="row">
    <div class="card kpi"><div class="muted">Belum bayar di posisi awal</div><div class="v" id="kRek">–</div><div class="n" id="kRekN">rekening</div></div>
    <div class="card kpi"><div class="muted">Tunggakan pokok</div><div class="v" id="kTp">–</div><div class="n">Rp, kelompok awal</div></div>
    <div class="card kpi"><div class="muted">Tunggakan bunga</div><div class="v" id="kTb">–</div><div class="n">Rp, kelompok awal</div></div>
    <div class="card kpi"><div class="muted">Kewajiban bulan ini</div><div class="v" id="kKw">–</div><div class="n" id="kKwN">Rp, pokok + bunga</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <a class="btn" id="unduhXlsx" href="#" aria-disabled="true">Unduh Excel (rincian debitur)</a>
        <a class="btn" id="unduhPdf" href="#" target="_blank" rel="noopener" aria-disabled="true">Cetak PDF (ringkasan)</a>
    </div>
    <div class="scroll">
        <table id="tblPg" style="width:100%">
            <thead>
                <tr>
                    <th>Minggu</th>
                    <th class="num">Bayar pokok</th><th class="num">Bayar bunga</th><th class="num">Total bayar</th>
                    <th>Tertagih</th>
                    <th class="num">Sudah bayar</th><th class="num">Masih belum</th>
                    <th class="num">Sisa kewajiban</th><th class="num">Sisa tunggakan pokok</th><th class="num">Sisa tunggakan bunga</th>
                    <th class="num">Kolek memburuk</th><th class="num">Masuk NPL</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="12" class="muted">Memuat data…</td></tr></tbody>
            <tfoot></tfoot>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">
        Kelompok awal = rekening yang punya kewajiban bulan ini tetapi belum ada pembayaran pokok maupun bunga pada posisi tanggal awal.
        Tiap minggu dihitung dari posisi terakhir pada atau sebelum akhir minggu itu; pembayaran minggu itu adalah selisih pembayaran bulan ini
        dibanding posisi sebelumnya, hanya untuk rekening kelompok awal. "Tertagih" membandingkan pembayaran dengan kewajiban bulan ini
        (maksimal sebesar kewajiban tiap rekening). Rekening yang tidak ada lagi di posisi (lunas atau hapus buku) dihitung terpisah.
        Tanggal awal dan akhir harus dalam satu periode tagihan, maksimal 6 minggu.
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fPg'),
            f = form.elements,
            tbody = document.querySelector('#tblPg tbody'),
            tfoot = document.querySelector('#tblPg tfoot'),
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('tampil'),
            nf = new Intl.NumberFormat('id-ID'),
            seq = 0;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function rp(v) { return v == null ? '–' : nf.format(Math.round(Number(v) || 0)); }

        function tgl(s) { // 2026-10-21 -> 21/10/2026
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : (s || '');
        }

        function tglPendek(s) { // 2026-10-21 -> 21/10
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] : (s || '');
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
        }

        function atur(el, href) {
            el.href = href || '#';
            el.setAttribute('aria-disabled', href ? 'false' : 'true');
        }

        function muat(p) {
            var ini = ++seq, q = new URLSearchParams();
            if (p && p.awal) { q.set('awal', p.awal); q.set('akhir', p.akhir); }
            if (p && p.ao) q.set('ao', p.ao);

            msg(true, '');
            btn.disabled = true;
            btn.textContent = 'Memuat…';
            tbody.innerHTML = '<tr><td colspan="12" class="muted">Memuat data…</td></tr>';
            tfoot.innerHTML = '';
            atur($('unduhXlsx'), ''); atur($('unduhPdf'), '');

            fetch('api/api_progres.php?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                    return r.json().catch(function() { return {}; }).then(function(d) {
                        if (!r.ok) throw new Error(d.error || r.status);
                        return d;
                    });
                })
                .then(function(d) { if (ini === seq) gambar(d); })
                .catch(function(e) {
                    if (ini !== seq) return;
                    tbody.innerHTML = '<tr><td colspan="12" class="muted">Data belum bisa dimuat.</td></tr>';
                    $('subTeks').textContent = '';
                    msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.');
                })
                .then(function() {
                    if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; }
                });
        }

        function isiAo(daftar, pilih) {
            var h = '<option value="">Semua AO</option>';
            (daftar || []).forEach(function(a) {
                h += '<option value="' + esc(a.v) + '"' + (a.v === pilih ? ' selected' : '') + '>' + esc(a.l) + '</option>';
            });
            f.ao.innerHTML = h;
        }

        function gambar(d) {
            if (d.empty) {
                tbody.innerHTML = '<tr><td colspan="12" class="muted">' + esc(d.pesan || 'Belum ada data.') + '</td></tr>';
                $('subTeks').textContent = '';
                return;
            }
            var a = d.awal, mg = d.minggu || [], ada = d.tung_tersedia, jP = 0, jB = 0;

            f.awal.value = d.sel.awal;
            f.akhir.value = d.sel.akhir;
            isiAo(d.aos, d.sel.ao);

            $('subTeks').textContent = 'Periode tagihan ' + d.periode + ' · kelompok awal dari posisi ' + tgl(d.posisi_awal);
            $('kRek').textContent = nf.format(a.rek);
            $('kRekN').textContent = 'rekening · baki debet Rp ' + rp(a.baki);
            $('kTp').textContent = ada ? rp(a.tung_pkk) : '–';
            $('kTb').textContent = ada ? rp(a.tung_bg) : '–';
            $('kKw').textContent = rp(a.kw_pkk + a.kw_bg);
            $('kKwN').textContent = 'pokok Rp ' + rp(a.kw_pkk) + ' · bunga Rp ' + rp(a.kw_bg);

            $('peringatan').innerHTML = (d.peringatan || []).map(function(t) {
                return '<div class="flash info">' + esc(t) + '</div>';
            }).join('');

            tbody.innerHTML = mg.length ? mg.map(function(m) {
                jP += m.bayar_pkk; jB += m.bayar_bg;
                var pct = m.pct_tertagih == null ? null : Math.max(0, Math.min(100, m.pct_tertagih));
                return '<tr>' +
                    '<td class="mgg"><b>Minggu ' + esc(m.no) + '</b><br><span class="muted">' + tglPendek(m.dari) + '–' + tglPendek(m.sampai) +
                        ' · posisi ' + tglPendek(m.tgl_posisi) + '</span></td>' +
                    '<td class="num">' + rp(m.bayar_pkk) + '</td>' +
                    '<td class="num">' + rp(m.bayar_bg) + '</td>' +
                    '<td class="num"><b>' + rp(m.bayar_total) + '</b></td>' +
                    '<td class="pct">' + (pct == null ? '–' : '<b>' + pct.toFixed(1).replace('.', ',') + '%</b><div class="bar"><i style="width:' + pct + '%;background:var(--brand)"></i></div>') + '</td>' +
                    '<td class="num">' + nf.format(m.rek_bayar) + '<br><span class="muted">' + nf.format(m.rek_lunas) + ' lunas</span></td>' +
                    '<td class="num">' + nf.format(m.rek_belum) +
                        (m.rek_hilang ? '<br><span class="muted">+' + nf.format(m.rek_hilang) + ' tdk ada di posisi</span>' : '') + '</td>' +
                    '<td class="num">' + rp(m.sisa_kw) + '</td>' +
                    '<td class="num">' + (ada ? rp(m.sisa_tung_pkk) : '–') + '</td>' +
                    '<td class="num">' + (ada ? rp(m.sisa_tung_bg) : '–') + '</td>' +
                    '<td class="num ' + (m.kolek_memburuk ? 'bad' : '') + '">' + nf.format(m.kolek_memburuk) + '</td>' +
                    '<td class="num ' + (m.masuk_npl ? 'bad' : '') + '">' + nf.format(m.masuk_npl) + '</td></tr>';
            }).join('') : '<tr><td colspan="12" class="muted">Tidak ada minggu pada rentang ini.</td></tr>';

            tfoot.innerHTML = mg.length ? '<tr><td>Jumlah pembayaran</td><td class="num">' + rp(jP) + '</td><td class="num">' + rp(jB) +
                '</td><td class="num">' + rp(jP + jB) + '</td><td colspan="8"></td></tr>' : '';

            // tombol unduh mengikuti filter yang SEDANG tampil (d.sel dari server)
            var p = new URLSearchParams({ laporan: 'progres', awal: d.sel.awal, akhir: d.sel.akhir });
            if (d.sel.ao) p.set('ao', d.sel.ao);
            atur($('unduhXlsx'), 'api/export.php?' + p.toString() + '&format=xlsx');
            atur($('unduhPdf'), 'api/export.php?' + p.toString() + '&format=pdf');
        }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.awal.value || !f.akhir.value) { msg(false, 'Tanggal awal dan akhir wajib diisi.'); return; }
            if (f.akhir.value < f.awal.value) { msg(false, 'Tanggal akhir tidak boleh sebelum tanggal awal.'); return; }
            muat({ awal: f.awal.value, akhir: f.akhir.value, ao: f.ao.value });
        });

        muat(null);   // awal: tanggal 1 bulan posisi terakhir sampai posisi terakhir
    })();
</script>
<?php page_foot();
