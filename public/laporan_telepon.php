<?php
// Laporan telepon nasabah (digabung dengan data kredit) untuk semua user atau satu user. Menu: web_laporan_telepon.
// Data lewat api/api_telepon.php; cetak lewat api/export_telepon.php.
require __DIR__ . '/lib.php';
page_head('Laporan telepon', 'laptelepon');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">

<div>
    <h1>Laporan telepon</h1>
    <div class="sub">Telepon penagihan ke nasabah pada rentang tanggal, lengkap dengan data kredit terbaru dan janji bayar.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fLap" class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
    <label class="f">Dari tanggal<input type="date" name="tgl1"></label>
    <label class="f">Sampai tanggal<input type="date" name="tgl2"></label>
    <label class="f">User / petugas<select name="user"><option value="">Semua user</option></select></label>
    <button type="submit" class="btn primary" id="btnTampil">Tampilkan</button>
    <div class="bg" style="margin-left:auto">
        <a class="bg-btn bg-prev" id="lihatPdf" href="#" target="_blank" rel="noopener" aria-disabled="true" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-cari"/></svg>Preview</a>
        <a class="bg-btn bg-xls" id="unduhXlsx" href="#" aria-disabled="true" title="Unduh Excel"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-xls"/></svg>Excel</a>
        <a class="bg-btn bg-pdf" id="unduhPdf" href="#" aria-disabled="true" title="Unduh PDF"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</a>
    </div>
</form>

<div class="card">
    <div id="ringkas" class="muted" style="margin-bottom:8px">Memuat…</div>
    <div class="scroll">
        <table id="tblLap" style="width:100%">
            <thead>
                <tr>
                    <th>Tanggal</th><th>Nasabah</th><th>Alamat</th><th class="num">Kolek</th><th class="num">Baki debet</th>
                    <th class="num">Tunggakan</th><th class="num">Bayar bln ini</th><th>Petugas</th><th>Keterangan</th><th>Janji bayar</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Data kredit diambil dari nominatif terbaru, bukan posisi pada tanggal telepon. Nasabah yang tidak ada di nominatif tetap tampil dengan data yang dicatat saat telepon. Pokok dan bunga ditampilkan terpisah. Semua angka dalam Rupiah.</div>
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
        function rp(v) { return v == null || v === '' ? '–' : nf.format(Math.round(Number(v) || 0)); }
        function tgl(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : (s && s !== '-' ? s : '–'); }
        function ymd(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }
        function pb(p, b) { return '<span class="muted">Pokok</span> ' + rp(p) + '<br><span class="muted">Bunga</span> ' + rp(b); }
        function atur(el, href) { if (href) { el.href = href; el.removeAttribute('aria-disabled'); } else { el.href = '#'; el.setAttribute('aria-disabled', 'true'); } }
        function jfetch(url) {
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } }).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok || d.error) throw new Error(d.error || 'Gagal (kode ' + r.status + ').'); return d; });
            });
        }

        var now = new Date();
        f.tgl1.value = ymd(new Date(now.getFullYear(), now.getMonth(), 1));
        f.tgl2.value = ymd(now);

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblLap').DataTable({
            data: [], order: [[0, 'desc']], pageLength: 25, lengthMenu: [25, 50, 100, 250], language: window.DT_ID,
            columns: [
                { data: 'tanggal', render: function(d, t) { return t === 'display' ? esc(tgl(d)) : d; } },
                { data: 'nama', render: function(d, t, r) {
                    if (t !== 'display') return (r.nama + ' ' + r.rekening).trim();
                    return '<b>' + esc(r.nama || '(tanpa nama)') + '</b><br><span class="muted">' + esc(r.rekening) + '</span>';
                } },
                { data: 'alamat', render: function(d) { return d ? esc(d) : '<span class="muted">–</span>'; } },
                { data: 'kolek', className: 'num', render: function(d) { return d == null ? '<span class="muted">–</span>' : esc(d); } },
                { data: 'baki', className: 'num', render: function(d, t) { return t === 'display' ? rp(d) : Number(d) || 0; } },
                { data: 'tungPokok', className: 'num', render: function(d, t, r) { return t === 'display' ? pb(r.tungPokok, r.tungBung) : (Number(r.tungPokok) || 0) + (Number(r.tungBung) || 0); } },
                { data: 'byr_pokok', className: 'num', render: function(d, t, r) { return t === 'display' ? pb(r.byr_pokok, r.byr_bunga) : (Number(r.byr_pokok) || 0) + (Number(r.byr_bunga) || 0); } },
                { data: 'nmuser', render: function(d) { return esc(d); } },
                { data: 'keterangan', render: function(d) { return '<div style="white-space:normal;min-width:220px">' + esc(d) + '</div>'; } },
                { data: 'tglKomit', render: function(d) { return esc(tgl(d)); } }
            ]
        });

        function muat() {
            var ini = ++seq, q = new URLSearchParams({ tgl1: f.tgl1.value, tgl2: f.tgl2.value });
            if (f.user.value) q.set('user', f.user.value);
            msg(true, ''); $$('ringkas').textContent = 'Memuat…';
            atur($$('lihatPdf'), ''); atur($$('unduhXlsx'), ''); atur($$('unduhPdf'), '');
            jfetch('api/api_telepon.php?aksi=laporan&' + q.toString())
                .then(function(d) {
                    if (ini !== seq) return;
                    var rows = d.rows || [];
                    table.clear().rows.add(rows).draw();
                    $$('ringkas').innerHTML = '<b>' + nf.format(rows.length) + ' telepon</b> · ' + esc(tgl(f.tgl1.value)) + ' s/d ' + esc(tgl(f.tgl2.value));
                    var u = 'api/export_telepon.php?' + q.toString();
                    atur($$('lihatPdf'), u + '&format=pdf'); atur($$('unduhXlsx'), u + '&format=xlsx'); atur($$('unduhPdf'), u + '&format=pdf&unduh=1');
                })
                .catch(function(e) { if (ini === seq && e.message !== '401') { $$('ringkas').textContent = ''; msg(false, e.message); } });
        }

        // daftar user untuk filter (gagal = tetap bisa lihat semua user)
        jfetch('api/api_telepon.php?aksi=daftar_user').then(function(d) {
            var h = '<option value="">Semua user</option>';
            (d.data || []).forEach(function(u) { h += '<option value="' + esc(u.id) + '">' + esc(u.nama) + (u.role ? ' (' + esc(u.role) + ')' : '') + '</option>'; });
            f.user.innerHTML = h;
        }).catch(function() {});

        form.addEventListener('submit', function(e) { e.preventDefault(); muat(); });
        muat();
    });
</script>
<?php page_foot();
