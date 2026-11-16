<?php
// Laporan promosi / survey untuk semua pemakai atau satu pemakai, lengkap dengan rekap dan foto. Menu: web_lap_promosi.
// Data lewat api/api_promosi.php; cetak lewat api/export_promosi.php.
require __DIR__ . '/lib.php';
page_head('Laporan survey / promosi', 'lappromosi');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<style>
    .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .kpis { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 16px; }
    .kp { display: flex; flex-direction: column; gap: 12px; }
    .kp .jud { font-weight: 600; color: var(--ink); }
    .kp .isi { display: flex; align-items: center; gap: 14px; }
    .kp .bul { width: 48px; height: 48px; border-radius: 50%; display: grid; place-items: center; flex: none; }
    .kp .bul svg { width: 24px; height: 24px; stroke: currentColor; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .kp .v { font-size: 26px; font-weight: 600; }
    .kp.biru .bul { background: #E8EDFF; color: #3B5BDB; }
    .kp.hijau .bul { background: #E4F5EA; color: #2B9348; }
    .kp.oranye .bul { background: #FDEBDD; color: #E8590C; }
    .kp.kuning { border-left: 4px solid #F59E0B; }
    .kp.kuning .bul { background: #FFF4D6; color: #B7791F; }
    #tblLap td.num, #tblLap th.num { text-align: right; }
    #tblLap td.wrap { white-space: normal; }
    a.btn { text-decoration: none; display: inline-block; }
    a.bg-btn[aria-disabled="true"] { opacity: .45; pointer-events: none; }
    @media (max-width: 900px) { .kpis { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
    @media (max-width: 600px) { .dua { grid-template-columns: 1fr; } .kpis { grid-template-columns: 1fr; } }
</style>

<div>
    <h1>Laporan</h1>
    <div class="sub">Form ini digunakan untuk menampilkan data survey / promosi.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fLap" class="card" novalidate>
    <b style="display:block;margin-bottom:12px">Laporan Survey/Promosi</b>
    <div class="dua">
        <label class="f">Tanggal Awal<input type="date" name="tgl1"></label>
        <label class="f">Tanggal Akhir<input type="date" name="tgl2"></label>
        <label class="f">Pemakai<select name="user"><option value="">Semua AO</option></select></label>
        <label class="f">Jenis Kegiatan
            <select name="jenis"><option value="">- Semua Jenis -</option><option value="promosi">Promosi</option><option value="survey">Survey</option></select>
        </label>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-top:16px">
        <button type="submit" class="btn primary" id="btnTampil">Tampilkan</button>
        <div class="bg">
            <a class="bg-btn bg-xls" id="unduhXlsx" href="#" aria-disabled="true" title="Unduh Excel"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-xls"/></svg>Excel</a>
            <a class="bg-btn bg-pdf" id="unduhPdf" href="#" aria-disabled="true" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</a>
        </div>
    </div>
</form>

<div class="kpis">
    <div class="card kp biru"><div class="jud">Total Kegiatan</div>
        <div class="isi"><span class="bul"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4"/></svg></span><span class="v" id="kTotal">0</span></div></div>
    <div class="card kp hijau"><div class="jud">Total Prospek</div>
        <div class="isi"><span class="bul"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg></span><span class="v" id="kProspek">0</span></div></div>
    <div class="card kp oranye"><div class="jud">Jenis Kegiatan</div>
        <div class="isi"><span class="bul"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"/><circle cx="7" cy="7" r="1.5"/></svg></span><span class="v" id="kJenis">0</span></div></div>
    <div class="card kp kuning"><div class="jud">Rata-rata Prospek</div>
        <div class="isi"><span class="bul"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></svg></span><span class="v" id="kRata">0</span></div></div>
</div>

<div class="card">
    <b style="display:block;margin-bottom:8px">Daftar Survey Promosi</b>
    <div class="scroll">
        <table id="tblLap" style="width:100%">
            <thead>
                <tr><th>No</th><th>Petugas</th><th>Jenis Kegiatan</th><th>Waktu Kegiatan</th><th>Produk</th><th class="num">Jumlah Prospek</th><th>Alamat Kegiatan</th><th>Keterangan</th><th>Foto</th><th>Dibuat</th></tr>
            </thead>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    $(function() {
        var form = document.getElementById('fLap'), f = form.elements, msgBox = document.getElementById('msg'), nf = new Intl.NumberFormat('id-ID'), seq = 0;
        function $$(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function msg(ok, t) { msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : ''; }
        function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
        function atur(el, href) { if (href) { el.href = href; el.removeAttribute('aria-disabled'); } else { el.href = '#'; el.setAttribute('aria-disabled', 'true'); } }
        // waktu dari API berbentuk 'DD-MM-YYYY HH:MM' (atau ISO): tampil DD/MM/YYYY HH:MM, kunci urut YYYYMMDDHHMM
        function pecah(s) {
            var m = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2})/.exec(s || ''), y, mo, d, h, mi;
            if (m) { d = m[1]; mo = m[2]; y = m[3]; h = m[4]; mi = m[5]; }
            else { m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || ''); if (!m) return { t: s || '', k: '' }; y = m[1]; mo = m[2]; d = m[3]; h = m[4]; mi = m[5]; }
            return { t: d + '/' + mo + '/' + y + ' ' + h + ':' + mi, k: y + mo + d + h + mi };
        }
        function waktuCol(d, t) { var p = pecah(d); return t === 'display' ? esc(p.t) : p.k; }
        function jfetch(url) {
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok || d.error) throw new Error(d.error || 'Gagal (kode ' + r.status + ').'); return d; });
            });
        }

        var now = new Date();
        f.tgl1.value = ymd(now);                                   // seperti gambar: awal dan akhir = hari ini
        f.tgl2.value = ymd(now);

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblLap').DataTable({
            data: [], order: [[3, 'desc']], pageLength: 10, lengthMenu: [10, 25, 50, 100], language: window.DT_ID,
            columns: [
                { data: null, orderable: false, searchable: false, defaultContent: '' },
                { data: 'nmuser', render: function(d) { return esc(d); } },
                { data: 'jenis_kegiatan', render: function(d) { return esc(d); } },
                { data: 'waktu_kegiatan', render: waktuCol },
                { data: 'produk', className: 'wrap', render: function(d) { return '<div style="min-width:120px">' + esc(d) + '</div>'; } },
                { data: 'jumlah_prospek', className: 'num', render: function(d, t) { return t === 'display' ? nf.format(Number(d) || 0) : Number(d) || 0; } },
                { data: 'alamat_kegiatan', className: 'wrap', render: function(d, t, r) {
                    if (t !== 'display') return d || '';
                    var gps = r.lokasi_gps ? '<a href="https://www.google.com/maps?q=' + encodeURIComponent(String(r.lokasi_gps).replace(/\s+/g, '')) + '" target="_blank" rel="noopener">Lihat peta</a>' : '';
                    return '<div style="min-width:150px">' + esc(d) + (d && gps ? '<br>' : '') + gps + (!d && !gps ? '<span class="muted">–</span>' : '') + '</div>';
                } },
                { data: 'keterangan', className: 'wrap', render: function(d) { return '<div style="min-width:200px">' + esc(d) + '</div>'; } },
                { data: 'foto', orderable: false, searchable: false, render: function(d, t) {
                    if (t !== 'display') return d || '';
                    return d ? '<a href="' + esc(d) + '" target="_blank" rel="noopener"><img src="' + esc(d) + '" alt="foto" style="width:56px;height:42px;object-fit:cover;border-radius:6px"></a>' : '<span class="muted">–</span>';
                } },
                { data: 'created_at', render: waktuCol }
            ]
        });
        table.on('draw.dt', function() {                          // nomor urut mengikuti urutan dan pencarian yang sedang tampil
            table.column(0, { search: 'applied', order: 'applied' }).nodes().each(function(c, i) { c.textContent = i + 1; });
        });

        function muat() {
            var ini = ++seq, q = new URLSearchParams({ tgl1: f.tgl1.value, tgl2: f.tgl2.value, user: f.user.value });   // user selalu dikirim (kosong = semua)
            if (f.jenis.value) q.set('jenis', f.jenis.value);
            msg(true, '');
            atur($$('unduhXlsx'), ''); atur($$('unduhPdf'), '');
            Promise.all([jfetch('api/api_promosi.php?aksi=preview&' + q.toString()), jfetch('api/api_promosi.php?aksi=read&' + q.toString())])
                .then(function(h) {
                    if (ini !== seq) return;
                    var k = h[1] || {};
                    $$('kTotal').textContent = nf.format(k.total || 0);
                    $$('kProspek').textContent = nf.format(k.total_prospek || 0);
                    $$('kJenis').textContent = nf.format(k.total_jenis || 0);
                    $$('kRata').textContent = nf.format(k.rata_prospek || 0);
                    table.clear().rows.add(h[0].rows || []).draw();
                    var ex = new URLSearchParams({ tgl1: f.tgl1.value, tgl2: f.tgl2.value });
                    if (f.user.value) ex.set('user', f.user.value);
                    if (f.jenis.value) ex.set('jenis', f.jenis.value);
                    atur($$('unduhXlsx'), 'api/export_promosi.php?' + ex.toString() + '&format=xlsx');
                    atur($$('unduhPdf'), 'api/export_promosi.php?' + ex.toString() + '&format=pdf&unduh=1');
                })
                .catch(function(e) { if (ini === seq && e.message !== '401') msg(false, e.message); });
        }

        jfetch('api/api_promosi.php?aksi=daftar_user').then(function(d) {
            var h = '<option value="">Semua AO</option>';
            (d.data || []).forEach(function(u) { h += '<option value="' + esc(u.id) + '">' + esc(u.nmuser) + (u.role ? ' (' + esc(u.role) + ')' : '') + '</option>'; });
            f.user.innerHTML = h;
        }).catch(function() {});

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.tgl1.value || !f.tgl2.value) { msg(false, 'Tanggal awal dan akhir wajib diisi.'); return; }
            if (f.tgl2.value < f.tgl1.value) { msg(false, 'Tanggal akhir tidak boleh sebelum tanggal awal.'); return; }
            muat();
        });
        muat();
    });
</script>
<?php page_foot();