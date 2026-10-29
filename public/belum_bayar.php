<?php
// Debitur yang kewajiban bulan ini belum ada pembayarannya (pokok maupun bunga).
// Tidak menyentuh database: data lewat api/api_belum_bayar.php (proxy ke API), per halaman.
require __DIR__ . '/lib.php';

page_head('Belum bayar bulan ini', 'belum_bayar');
?>
<style>
    #tblBb td.wrap { white-space: normal; min-width: 220px; text-align: left; }
    #tblBb td.num, #tblBb th.num { text-align: right; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    a.btn { text-decoration: none; display: inline-block; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    @media print { .top, .no-print { display: none !important; } main { padding: 0; } .card { border: 0; padding: 0; } }
</style>

<div>
    <h1>Belum bayar bulan ini</h1>
    <div class="sub" id="posisiTeks">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fBb" class="card filter no-print" novalidate>
    <label class="f">Nama debitur<input class="fi" type="search" name="nama" maxlength="60" placeholder="Opsional, minimal 2 huruf" autocomplete="off"></label>
    <label class="f">Kolektibilitas
        <select name="kolek">
            <option value="">Semua</option>
            <option value="1">1 – Lancar</option>
            <option value="2">2 – Dalam perhatian khusus</option>
            <option value="3">3 – Kurang lancar</option>
            <option value="4">4 – Diragukan</option>
            <option value="5">5 – Macet</option>
        </select>
    </label>
    <label class="f">Per halaman
        <select name="per"><option>10</option><option selected>20</option><option>50</option></select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
</form>

<div class="row">
    <div class="card kpi"><div class="muted">Rekening belum bayar</div><div class="v" id="kJumlah">–</div><div class="n">sesuai filter di atas</div></div>
    <div class="card kpi"><div class="muted">Kewajiban bulan ini</div><div class="v" id="kKew">–</div><div class="n">Rp, pokok + bunga</div></div>
    <div class="card kpi"><div class="muted">Baki debet</div><div class="v" id="kBaki">–</div><div class="n">Rp</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <a class="btn" id="unduhXlsx" href="#">Unduh Excel</a>
        <a class="btn" id="unduhPdf" href="#" target="_blank" rel="noopener">Cetak PDF</a>
    </div>
    <div class="scroll">
        <table id="tblBb" class="tl" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th><th>Kolek</th><th class="num">Hari tunggak</th>
                    <th class="num">Baki debet</th><th class="num">Kewajiban bulan ini</th><th>Jatuh tempo</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="6" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
    <div class="pager no-print" id="pager" style="display:none">
        <button type="button" class="btn" id="pgPrev">Sebelumnya</button>
        <span class="muted" id="pgInfo"></span>
        <button type="button" class="btn" id="pgNext">Berikutnya</button>
    </div>
    <div class="muted" style="margin-top:8px">
        Daftar rekening yang punya kewajiban bulan ini (pokok + bunga) tetapi belum ada pembayaran pokok maupun bunga,
        menurut data posisi terakhir. Diurutkan dari yang paling lama menunggak, lalu baki debet terbesar.
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fBb'),
            f = form.elements,
            tbody = document.querySelector('#tblBb tbody'),
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('tampil'),
            rpFmt = new Intl.NumberFormat('id-ID'),
            st = { page: 1, total_page: 1, f: null },   // st.f = filter pencarian terakhir
            seq = 0;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function rp(v) { return rpFmt.format(Math.round(Number(v) || 0)); }

        function tgl(s) { // 2026-10-21 -> 21/10/2026
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : (s || '');
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
        }

        function muat(hal) {
            var ini = ++seq, p = new URLSearchParams({ page: hal, per: st.f.per });
            if (st.f.nama) p.set('nama', st.f.nama);
            if (st.f.kolek) p.set('kolek', st.f.kolek);

            msg(true, '');
            btn.disabled = true;
            btn.textContent = 'Memuat…';
            tbody.innerHTML = '<tr><td colspan="6" class="muted">Memuat data…</td></tr>';

            fetch('api/api_belum_bayar.php?' + p.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                    return r.json().catch(function() { return {}; }).then(function(d) {
                        if (!r.ok) throw new Error(d.error || r.status);
                        return d;
                    });
                })
                .then(function(d) {
                    if (ini !== seq) return;
                    gambar(d);
                })
                .catch(function(e) {
                    if (ini !== seq) return;
                    tbody.innerHTML = '<tr><td colspan="6" class="muted">Data belum bisa dimuat.</td></tr>';
                    $('pager').style.display = 'none';
                    msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.');
                })
                .then(function() {
                    if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; }
                });
        }

        function gambar(d) {
            var rows = d.rows || [];
            st.page = d.page || 1;
            st.total_page = d.total_page || 1;

            $('posisiTeks').textContent = d.posisi ? 'Posisi data ' + tgl(d.posisi) : '';
            $('kJumlah').textContent = rpFmt.format(d.total || 0);
            $('kKew').textContent = rp(d.total_kewajiban);
            $('kBaki').textContent = rp(d.total_baki);

            tbody.innerHTML = rows.length ? rows.map(function(x) {
                return '<tr><td class="wrap"><b>' + esc(x.nama || '(tanpa nama)') + '</b><br><span class="muted">' + esc(x.norek) + '</span>' +
                    (x.alamat ? '<br><span class="muted">' + esc(x.alamat) + '</span>' : '') + '</td>' +
                    '<td><span class="' + (Number(x.kolek) >= 3 ? 'bad' : '') + '">' + esc(x.kolek) + '</span></td>' +
                    '<td class="num">' + (x.hari == null ? '–' : esc(x.hari)) + '</td>' +
                    '<td class="num">' + rp(x.baki) + '</td>' +
                    '<td class="num">' + rp(x.kewajiban) + '</td>' +
                    '<td>' + (x.tempo ? esc(tgl(x.tempo)) : '<span class="muted">–</span>') + '</td></tr>';
            }).join('') : '<tr><td colspan="6" class="muted">Tidak ada rekening yang belum bayar untuk filter ini.</td></tr>';

            $('pgInfo').textContent = 'Halaman ' + st.page + ' dari ' + st.total_page;
            $('pgPrev').disabled = st.page <= 1;
            $('pgNext').disabled = st.page >= st.total_page;
            $('pager').style.display = st.total_page > 1 ? 'flex' : 'none';
        }

        // tombol unduh memakai filter pencarian terakhir (bukan isi form yang mungkin belum ditampilkan)
        function aturUnduh() {
            var p = new URLSearchParams({ laporan: 'belum_bayar' });
            if (st.f.nama) p.set('nama', st.f.nama);
            if (st.f.kolek) p.set('kolek', st.f.kolek);
            $('unduhXlsx').href = 'api/export.php?' + p.toString() + '&format=xlsx';
            $('unduhPdf').href  = 'api/export.php?' + p.toString() + '&format=pdf';
        }

        // pencarian hanya dijalankan saat tombol Tampilkan ditekan atau Enter (bukan setiap ketikan)
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var nama = f.nama.value.trim();
            if (nama !== '' && nama.length < 2) { msg(false, 'Nama minimal 2 huruf, atau kosongkan.'); f.nama.focus(); return; }
            st.f = { nama: nama, kolek: f.kolek.value, per: f.per.value };
            aturUnduh();
            muat(1);
        });
        $('pgPrev').addEventListener('click', function() { if (st.page > 1) muat(st.page - 1); });
        $('pgNext').addEventListener('click', function() { if (st.page < st.total_page) muat(st.page + 1); });

        st.f = { nama: '', kolek: '', per: f.per.value };
        aturUnduh();
        muat(1);
    })();
</script>
<?php page_foot();