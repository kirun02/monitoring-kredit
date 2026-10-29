<?php
// Laporan absensi karyawan: nama, waktu, lokasi, status (masuk/pulang). Tanpa foto.
// Tidak menyentuh database: data lewat api/api_lap_absensi.php (proxy ke API), per halaman.
require __DIR__ . '/lib.php';
date_default_timezone_set('Asia/Jakarta');

page_head('Laporan absensi', 'lap_absensi');
?>
<style>
    #tblLa td.wrap { white-space: normal; min-width: 180px; text-align: left; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    a.btn { text-decoration: none; display: inline-block; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    @media print { .top, .no-print { display: none !important; } main { padding: 0; } .card { border: 0; padding: 0; } }
</style>

<div>
    <h1>Laporan absensi</h1>
    <div class="sub" id="periodeTeks">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fLa" class="card filter no-print" novalidate>
    <label class="f">Dari tanggal<input type="date" name="dari" value="<?= h(date('Y-m-01')) ?>" required></label>
    <label class="f">Sampai tanggal<input type="date" name="sampai" value="<?= h(date('Y-m-d')) ?>" required></label>
    <label class="f">Karyawan
        <select name="karyawan"><option value="">Semua karyawan</option></select>
    </label>
    <label class="f">Status
        <select name="status">
            <option value="">Masuk dan pulang</option>
            <option value="masuk">Masuk</option>
            <option value="pulang">Pulang</option>
        </select>
    </label>
    <label class="f">Per halaman
        <select name="per"><option>10</option><option selected>20</option><option>50</option></select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
</form>

<div class="row">
    <div class="card kpi"><div class="muted">Total absen</div><div class="v" id="kTotal">–</div><div class="n">sesuai filter di atas</div></div>
    <div class="card kpi"><div class="muted">Absen masuk</div><div class="v" id="kMasuk">–</div><div class="n">kali</div></div>
    <div class="card kpi"><div class="muted">Absen pulang</div><div class="v" id="kPulang">–</div><div class="n">kali</div></div>
    <div class="card kpi"><div class="muted">Karyawan</div><div class="v" id="kOrang">–</div><div class="n">yang tercatat absen</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <a class="btn" id="unduhXlsx" href="#">Unduh Excel</a>
        <a class="btn" id="unduhPdf" href="#" target="_blank" rel="noopener">Cetak PDF</a>
    </div>
    <div class="scroll">
        <table id="tblLa" class="tl" style="width:100%">
            <thead>
                <tr><th>Karyawan</th><th>Waktu</th><th>Status</th><th>Lokasi</th></tr>
            </thead>
            <tbody><tr><td colspan="4" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
    <div class="pager no-print" id="pager" style="display:none">
        <button type="button" class="btn" id="pgPrev">Sebelumnya</button>
        <span class="muted" id="pgInfo"></span>
        <button type="button" class="btn" id="pgNext">Berikutnya</button>
    </div>
    <div class="muted" style="margin-top:8px">
        Catatan absen karyawan pada periode yang dipilih. Di layar urutan terbaru di atas; di Excel dan PDF urutan waktu terlama dulu.
        Lokasi berupa koordinat GPS saat absen dikirim. Foto absen tidak disertakan.
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fLa'),
            f = form.elements,
            tbody = document.querySelector('#tblLa tbody'),
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('tampil'),
            nf = new Intl.NumberFormat('id-ID'),
            st = { page: 1, total_page: 1, f: null },   // st.f = filter pencarian terakhir
            seq = 0;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function tgl(s) { // 2026-10-21 -> 21/10/2026
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : (s || '');
        }

        function waktu(s) { // 2026-10-21 08:03:12 -> 21/10/2026 08:03
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : (s || '');
        }

        function lokasi(s) { // koordinat valid -> tautan ke peta; selain itu teks biasa
            if (!s) return '<span class="muted">–</span>';
            if (/^-?\d{1,3}\.\d+,\s*-?\d{1,3}\.\d+$/.test(s)) {
                return '<a href="https://www.google.com/maps?q=' + encodeURIComponent(s.replace(/\s+/g, '')) +
                    '" target="_blank" rel="noopener">' + esc(s) + '</a>';
            }
            return esc(s);
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
        }

        function ambil(url) {
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                return r.json().catch(function() { return {}; }).then(function(d) {
                    if (!r.ok) throw new Error(d.error || r.status);
                    return d;
                });
            });
        }

        function muat(hal) {
            var ini = ++seq, p = new URLSearchParams({ dari: st.f.dari, sampai: st.f.sampai, page: hal, per: st.f.per });
            if (st.f.karyawan) p.set('karyawan', st.f.karyawan);
            if (st.f.status) p.set('status', st.f.status);

            msg(true, '');
            btn.disabled = true;
            btn.textContent = 'Memuat…';
            tbody.innerHTML = '<tr><td colspan="4" class="muted">Memuat data…</td></tr>';

            ambil('api/api_lap_absensi.php?' + p.toString())
                .then(function(d) { if (ini === seq) gambar(d); })
                .catch(function(e) {
                    if (ini !== seq) return;
                    tbody.innerHTML = '<tr><td colspan="4" class="muted">Data belum bisa dimuat.</td></tr>';
                    $('pager').style.display = 'none';
                    msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.');
                })
                .then(function() {
                    if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; }
                });
        }

        function gambar(d) {
            var rows = d.rows || [], r = d.ringkas || {};
            st.page = d.page || 1;
            st.total_page = d.total_page || 1;

            $('periodeTeks').textContent = 'Periode ' + tgl(d.dari) + ' s/d ' + tgl(d.sampai);
            $('kTotal').textContent = nf.format(r.total || 0);
            $('kMasuk').textContent = nf.format(r.masuk || 0);
            $('kPulang').textContent = nf.format(r.pulang || 0);
            $('kOrang').textContent = nf.format(r.orang || 0);

            tbody.innerHTML = rows.length ? rows.map(function(x) {
                return '<tr><td class="wrap"><b>' + esc(x.nama || '(tanpa nama)') + '</b>' +
                    (x.bagian ? '<br><span class="muted">' + esc(x.bagian) + '</span>' : '') + '</td>' +
                    '<td>' + esc(waktu(x.waktu)) + '</td>' +
                    '<td><span class="' + (x.status === 'masuk' ? 'good' : '') + '">' + (x.status === 'masuk' ? 'Masuk' : (x.status === 'pulang' ? 'Pulang' : esc(x.status))) + '</span></td>' +
                    '<td>' + lokasi(x.lokasi) + '</td></tr>';
            }).join('') : '<tr><td colspan="4" class="muted">Tidak ada catatan absen untuk filter ini.</td></tr>';

            $('pgInfo').textContent = 'Halaman ' + st.page + ' dari ' + st.total_page;
            $('pgPrev').disabled = st.page <= 1;
            $('pgNext').disabled = st.page >= st.total_page;
            $('pager').style.display = st.total_page > 1 ? 'flex' : 'none';
        }

        // tombol unduh memakai filter pencarian terakhir (bukan isi form yang mungkin belum ditampilkan)
        function aturUnduh() {
            var p = new URLSearchParams({ laporan: 'lap_absensi', dari: st.f.dari, sampai: st.f.sampai });
            if (st.f.karyawan) p.set('karyawan', st.f.karyawan);
            if (st.f.status) p.set('status', st.f.status);
            $('unduhXlsx').href = 'api/export.php?' + p.toString() + '&format=xlsx';
            $('unduhPdf').href  = 'api/export.php?' + p.toString() + '&format=pdf';
        }

        function bacaForm() { return { dari: f.dari.value, sampai: f.sampai.value, karyawan: f.karyawan.value, status: f.status.value, per: f.per.value }; }

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var v = bacaForm();
            if (!v.dari || !v.sampai) { msg(false, 'Tanggal awal dan akhir wajib diisi.'); return; }
            if (v.sampai < v.dari) { msg(false, 'Tanggal akhir tidak boleh sebelum tanggal awal.'); return; }
            st.f = v;
            aturUnduh();
            muat(1);
        });
        $('pgPrev').addEventListener('click', function() { if (st.page > 1) muat(st.page - 1); });
        $('pgNext').addEventListener('click', function() { if (st.page < st.total_page) muat(st.page + 1); });

        // isi dropdown karyawan (gagal = tetap bisa pakai "Semua karyawan")
        ambil('api/api_lap_absensi.php?opsi=1').then(function(d) {
            (d.karyawan || []).forEach(function(k) {
                var o = document.createElement('option');
                o.value = k.id;
                o.textContent = k.nama || ('Karyawan ' + k.id);
                f.karyawan.appendChild(o);
            });
        }).catch(function() {});

        st.f = bacaForm();
        aturUnduh();
        muat(1);
    })();
</script>
<?php page_foot();
