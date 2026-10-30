<?php
// Progres mingguan: rekening di kelompok awal (menurut "dasar") dilacak tiap minggu sampai tanggal akhir.
// Tidak menyentuh database: data lewat api/api_progres.php (proxy ke API). Pokok dan bunga selalu ditampilkan terpisah.
require __DIR__ . '/lib.php';

page_head('Progres mingguan', 'progres');
?>

<style>
    #tblPg td.num, #tblPg th.num, #tblDb td.num, #tblDb th.num { text-align: right; }
    #tblPg td.mgg { white-space: normal; min-width: 170px; text-align: left; }
    #tblDb td.deb { white-space: normal; min-width: 210px; text-align: left; }
    #tblPg tfoot td { font-weight: 600; border-top: 2px solid var(--line); border-bottom: 0; }
    #tblDb .bayar { color: var(--good); font-weight: 600; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    a.btn { text-decoration: none; display: inline-block; }
    a.btn[aria-disabled="true"] { opacity: .45; pointer-events: none; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    .tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 11px; background: #E9F1EF; color: var(--ink); margin-right: 4px; }
    .tag.bad { background: #FBE9E9; color: var(--bad); }
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
    <label class="f">Dasar kelompok
        <select name="dasar">
            <option value="keduanya">Keduanya (kewajiban atau tunggakan)</option>
            <option value="kewajiban">Kewajiban bulan ini (belum bayar)</option>
            <option value="tunggakan">Total tunggakan</option>
        </select>
    </label>
    <label class="f">AO
        <select name="ao"><option value="">Semua AO</option></select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
</form>

<div id="peringatan"></div>

<div class="row">
    <div class="card kpi"><div class="muted">Rekening di kelompok awal</div><div class="v" id="kRek">–</div><div class="n" id="kRekN">rekening</div></div>
    <div class="card kpi"><div class="muted">Total tunggakan pokok</div><div class="v" id="kTp">–</div><div class="n">Rp, kelompok awal</div></div>
    <div class="card kpi"><div class="muted">Total tunggakan bunga</div><div class="v" id="kTb">–</div><div class="n">Rp, kelompok awal</div></div>
    <div class="card kpi"><div class="muted">Kewajiban bln ini pokok</div><div class="v" id="kKp">–</div><div class="n">Rp, jadwal angsuran bulan ini</div></div>
    <div class="card kpi"><div class="muted">Kewajiban bln ini bunga</div><div class="v" id="kKb">–</div><div class="n">Rp, jadwal angsuran bulan ini</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <span class="bg"><a class="bg-btn bg-xls" id="unduhXlsx" href="#" aria-disabled="true"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-xls"/></svg>Unduh Excel (rincian debitur)</a></span>
    </div>
    <div class="scroll">
        <table id="tblPg" style="width:100%">
            <thead>
                <tr>
                    <th>Minggu</th>
                    <th class="num">Bayar minggu ini</th>
                    <th class="num">Tertagih<br><span class="muted">dari kewajiban bln ini</span></th>
                    <th class="num">Sudah ada bayar</th>
                    <th class="num">Belum ada bayar</th>
                    <th class="num">Kredit lunas<br><span class="muted">keluar dari nominatif</span></th>
                    <th class="num">Kurang kewajiban<br><span class="muted">bln ini</span></th>
                    <th class="num">Sisa total tunggakan</th>
                    <th class="num">Perubahan tunggakan<br><span class="muted">jumlah rekening</span></th>
                    <th class="num">Kolek memburuk</th><th class="num">Masuk NPL</th>
                    <th class="no-print">Cetak PDF</th>
                </tr>
            </thead>
            <tbody><tr><td colspan="12" class="muted">Memuat data…</td></tr></tbody>
            <tfoot></tfoot>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">
        <b>Dasar kelompok.</b> "Kewajiban bulan ini" = rekening yang punya kewajiban bulan ini tetapi belum ada pembayaran pokok maupun bunga pada posisi tanggal awal.
        "Total tunggakan" = rekening dengan total tunggakan pokok atau bunga di atas nol, termasuk kredit yang sudah jatuh tempo.
        "Keduanya" = gabungan (otomatis dipilih bila data tunggakan tersedia). Tiap rekening diberi label sesuai kenyataannya.
        Kewajiban bulan ini dan tunggakan tidak pernah dijumlahkan: keduanya tampil di kolom sendiri, pokok dan bunga terpisah.<br>
        <b>Cara hitung.</b> Tiap minggu memakai posisi terakhir pada atau sebelum akhir minggu itu. Pembayaran minggu itu = selisih pembayaran bulan ini dibanding posisi sebelumnya.
        "Tertagih" membandingkan pembayaran dengan kewajiban bulan ini (maksimal sebesar kewajiban), pokok dan bunga sendiri-sendiri. "Kurang kewajiban" = kewajiban bulan ini dikurangi pembayaran.
        "Perubahan tunggakan" membandingkan total tunggakan tiap rekening dengan posisi awal: lunas (nol), turun, tetap, atau naik.<br>
        <b>Kredit lunas.</b> Nominatif hanya memuat debitur yang masih punya baki, jadi rekening yang ada di posisi awal tetapi tidak muncul lagi di nominatif berikutnya dianggap lunas.
        Pengecekannya per tanggal untuk tiap rekening, dan tanggal lunas = tanggal pertama rekening tidak muncul (hapus buku juga terhitung lunas).
        Tanggal awal dan akhir harus dalam satu periode tagihan, maksimal 6 minggu.
        Tombol Preview (lihat di tab baru) dan PDF (unduh) di tiap minggu berisi: ringkasan minggu itu, rincian yang bayar di minggu itu, yang belum bayar sampai akhir minggu itu, dan kredit yang lunas di minggu itu.
    </div>
</div>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;justify-content:space-between;margin-bottom:12px" class="no-print">
        <div><b>Daftar debitur kelompok awal</b> <span class="muted" id="infoDaftar"></span></div>
        <form id="fDb" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end" novalidate>
            <label class="f">Cari nama / no. rekening<input class="fi" type="search" name="nama" maxlength="40" placeholder="Minimal 2 huruf" autocomplete="off"></label>
            <label class="f">Status akhir
                <select name="status">
                    <option value="">Semua</option>
                    <optgroup label="Kewajiban bulan ini">
                        <option value="belum">Belum bayar</option>
                        <option value="sebagian">Sebagian</option>
                        <option value="sudah">Sudah bayar</option>
                        <option value="tanpa">Tanpa kewajiban bln ini</option>
                    </optgroup>
                    <optgroup label="Total tunggakan">
                        <option value="tlunas">Lunas</option>
                        <option value="tturun">Turun</option>
                        <option value="ttetap">Tetap</option>
                        <option value="tnaik">Naik</option>
                    </optgroup>
                    <option value="hilang">Kredit lunas</option>
                </select>
            </label>
            <label class="f">Urutkan
                <select name="urut">
                    <option value="">Hari tunggak terbanyak</option>
                    <option value="tgl">Tanggal jatuh tempo (1–31)</option>
                </select>
            </label>
            <label class="f">Per halaman
                <select name="per"><option>10</option><option selected>20</option><option>50</option></select>
            </label>
            <button type="submit" class="btn" id="cariDb">Cari</button>
            <span class="bg">
                <a class="bg-btn bg-pdf" id="lihatDaftar" href="#" target="_blank" rel="noopener" aria-disabled="true" title="Lihat PDF di tab baru"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>PDF</a>
            </span>
        </form>
    </div>
    <div class="scroll">
        <table id="tblDb" class="tl" style="width:100%">
            <thead id="headDb"></thead>
            <tbody><tr><td class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
    <div class="pager no-print" id="pager" style="display:none">
        <button type="button" class="btn" id="pgPrev">Sebelumnya</button>
        <span class="muted" id="pgInfo"></span>
        <button type="button" class="btn" id="pgNext">Berikutnya</button>
    </div>
    <div class="muted" style="margin-top:8px">
        Diurutkan dari yang paling lama menunggak, atau menurut tanggal jatuh tempo (hanya harinya, 1–31) bila dipilih di Urutkan; pengurutan berlaku untuk seluruh daftar, bukan per halaman.
        Panah menunjukkan kondisi di posisi awal lalu di posisi terakhir. Kolom minggu = pembayaran yang masuk pada minggu itu.
        Tombol Preview dan PDF mencetak seluruh daftar (bukan hanya halaman ini) sesuai pencarian, status, dan urutan yang sedang dipakai. Untuk seluruh daftar beserta jumlahnya dalam bentuk Excel, unduh Excel di atas.
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fPg'),
            f = form.elements,
            fdb = document.getElementById('fDb'),
            fd = fdb.elements,
            tbody = document.querySelector('#tblPg tbody'),
            tfoot = document.querySelector('#tblPg tfoot'),
            tbDb = document.querySelector('#tblDb tbody'),
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('tampil'),
            nf = new Intl.NumberFormat('id-ID'),
            ALASAN = { kewajiban: 'Belum bayar bln ini', tunggakan: 'Menunggak', keduanya: 'Belum bayar + menunggak' },
            st = { awal: '', akhir: '', ao: '', dasar: '', nama: '', status: '', urut: '', per: '20', page: 1, total_page: 1 },   // filter yang SEDANG berlaku
            seq = 0;

        function $(id) { return document.getElementById(id); }

        function ik(n) { return '<svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-' + n + '"/></svg>'; }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function rp(v) { return v == null ? '–' : nf.format(Math.round(Number(v) || 0)); }
        function pct(v) { return v == null ? '–' : Number(v).toFixed(1).replace('.', ',') + '%'; }

        // pokok di atas, bunga di bawah; tidak pernah dijumlahkan
        function pb(p, b) { return '<span class="muted">Pokok</span> ' + p + '<br><span class="muted">Bunga</span> ' + b; }

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

        // segarkanForm = true: isi ulang tanggal/dasar/AO dari yang dipakai server (muat pertama atau setelah menekan Tampilkan)
        function muat(segarkanForm) {
            var ini = ++seq, q = new URLSearchParams({ hal: st.page, per: st.per });
            if (st.awal) { q.set('awal', st.awal); q.set('akhir', st.akhir); }
            if (st.ao) q.set('ao', st.ao);
            if (st.dasar) q.set('dasar', st.dasar);          // kosong = backend memilih (keduanya bila data tunggakan ada)
            if (st.nama) q.set('nama', st.nama);
            if (st.status) q.set('status', st.status);
            if (st.urut) q.set('urut', st.urut);

            msg(true, '');
            btn.disabled = true;
            btn.textContent = 'Memuat…';
            $('cariDb').disabled = true;
            if (segarkanForm) {
                tbody.innerHTML = '<tr><td colspan="12" class="muted">Memuat data…</td></tr>';
                tfoot.innerHTML = '';
                atur($('unduhXlsx'), '');
            }
            tbDb.innerHTML = '<tr><td class="muted">Memuat data…</td></tr>';
            atur($('lihatDaftar'), '');

            fetch('api/api_progres.php?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                    return r.json().catch(function() { return {}; }).then(function(d) {
                        if (!r.ok) throw new Error(d.error || r.status);
                        return d;
                    });
                })
                .then(function(d) { if (ini === seq) gambar(d, segarkanForm); })
                .catch(function(e) {
                    if (ini !== seq) return;
                    tbody.innerHTML = '<tr><td colspan="12" class="muted">Data belum bisa dimuat.</td></tr>';
                    tbDb.innerHTML = '<tr><td class="muted">Data belum bisa dimuat.</td></tr>';
                    $('pager').style.display = 'none';
                    $('subTeks').textContent = '';
                    msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.');
                })
                .then(function() {
                    if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; $('cariDb').disabled = false; }
                });
        }

        function isiAo(daftar, pilih) {
            var h = '<option value="">Semua AO</option>';
            (daftar || []).forEach(function(a) {
                h += '<option value="' + esc(a.v) + '"' + (a.v === pilih ? ' selected' : '') + '>' + esc(a.l) + '</option>';
            });
            f.ao.innerHTML = h;
        }

        function badge(s, jenis) {
            if (s == null) return '<span class="muted">–</span>';
            var baik = jenis === 'tung' ? { 'Lunas': 1, 'Turun': 1 } : { 'Sudah bayar': 1, 'Kredit lunas': 1 },
                buruk = jenis === 'tung' ? { 'Naik': 1 } : { 'Belum bayar': 1 };
            return '<span class="' + (baik[s] ? 'good' : (buruk[s] ? 'bad' : 'muted')) + '"><b>' + esc(s) + '</b></span>';
        }

        function gambar(d, segarkanForm) {
            if (d.empty) {
                tbody.innerHTML = '<tr><td colspan="12" class="muted">' + esc(d.pesan || 'Belum ada data.') + '</td></tr>';
                tbDb.innerHTML = '<tr><td class="muted">' + esc(d.pesan || 'Belum ada data.') + '</td></tr>';
                $('subTeks').textContent = '';
                return;
            }
            var a = d.awal, mg = d.minggu || [], ada = d.tung_tersedia, jP = 0, jB = 0;

            if (segarkanForm) {
                f.awal.value = d.sel.awal;
                f.akhir.value = d.sel.akhir;
                f.dasar.value = d.sel.dasar;
                isiAo(d.aos, d.sel.ao);
                st.awal = d.sel.awal; st.akhir = d.sel.akhir; st.ao = d.sel.ao; st.dasar = d.sel.dasar;
            }

            $('subTeks').textContent = 'Periode tagihan ' + d.periode + ' · kelompok awal dari posisi ' + tgl(d.posisi_awal);
            $('kRek').textContent = nf.format(a.rek);
            $('kRekN').textContent = 'belum bayar ' + nf.format(a.rek_kewajiban) + ' · menunggak ' + nf.format(a.rek_tunggakan) + ' · keduanya ' + nf.format(a.rek_keduanya);
            $('kTp').textContent = ada ? rp(a.tung_pkk) : '–';
            $('kTb').textContent = ada ? rp(a.tung_bg) : '–';
            $('kKp').textContent = rp(a.kw_pkk);
            $('kKb').textContent = rp(a.kw_bg);

            $('peringatan').innerHTML = (d.peringatan || []).map(function(t) {
                return '<div class="flash info">' + esc(t) + '</div>';
            }).join('');

            var pl = new URLSearchParams({ laporan: 'progres', awal: d.sel.awal, akhir: d.sel.akhir, dasar: d.sel.dasar });
            if (d.sel.ao) pl.set('ao', d.sel.ao);
            var urlLap = 'api/export.php?' + pl.toString();   // dipakai tombol Excel dan tombol PDF tiap minggu

            tbody.innerHTML = mg.length ? mg.map(function(m) {
                var u = urlLap + '&format=pdf&minggu=' + m.no;
                jP += m.bayar_pkk; jB += m.bayar_bg;
                return '<tr>' +
                    '<td class="mgg"><b>Minggu ' + esc(m.no) + '</b><br><span class="muted">' + tglPendek(m.dari) + '–' + tglPendek(m.sampai) +
                        ' · posisi ' + tglPendek(m.tgl_posisi) + '</span></td>' +
                    '<td class="num">' + pb(rp(m.bayar_pkk), rp(m.bayar_bg)) + '</td>' +
                    '<td class="num">' + pb(pct(m.pct_pkk), pct(m.pct_bg)) + '</td>' +
                    '<td class="num">' + nf.format(m.rek_bayar) + '<br><span class="muted">' + nf.format(m.rek_lunas) + ' terpenuhi</span></td>' +
                    '<td class="num">' + nf.format(m.rek_belum) + '</td>' +
                    '<td class="num ' + (m.rek_hilang ? 'good' : '') + '">' + nf.format(m.rek_hilang) + '</td>' +
                    '<td class="num ' + ((m.kurang_pkk + m.kurang_bg) > 0 ? 'bad' : '') + '">' + pb(rp(m.kurang_pkk), rp(m.kurang_bg)) + '</td>' +
                    '<td class="num">' + (ada ? pb(rp(m.sisa_tung_pkk), rp(m.sisa_tung_bg)) : '–') + '</td>' +
                    '<td class="num">' + (ada ? 'Lunas <b>' + nf.format(m.rek_tung_nol) + '</b> · Turun <b>' + nf.format(m.rek_tung_turun) + '</b><br>Tetap <b>' +
                        nf.format(m.rek_tung_tetap) + '</b> · <span class="' + (m.rek_tung_naik ? 'bad' : '') + '">Naik <b>' + nf.format(m.rek_tung_naik) + '</b></span>' : '–') + '</td>' +
                    '<td class="num ' + (m.kolek_memburuk ? 'bad' : '') + '">' + nf.format(m.kolek_memburuk) + '</td>' +
                    '<td class="num ' + (m.masuk_npl ? 'bad' : '') + '">' + nf.format(m.masuk_npl) + '</td>' +
                    '<td class="no-print"><span class="bg bg-sm"><a class="bg-btn bg-pdf" href="' + esc(u) + '" target="_blank" rel="noopener" title="Lihat PDF minggu ' + m.no + ' di tab baru">' + ik('cari') + 'pdf</a></span></td></tr>';
            }).join('') : '<tr><td colspan="12" class="muted">Tidak ada minggu pada rentang ini.</td></tr>';

            tfoot.innerHTML = mg.length ? '<tr><td>Jumlah pembayaran</td><td class="num">' + pb(rp(jP), rp(jB)) + '</td><td colspan="10"></td></tr>' : '';

            gambarDaftar(d, mg, ada);

            // tombol unduh mengikuti rentang, dasar, dan AO yang SEDANG berlaku (d.sel dari server)
            atur($('unduhXlsx'), urlLap + '&format=xlsx');
        }

        function gambarDaftar(d, mg, ada) {
            var dl = d.daftar || { rows: [], total: 0, total_page: 1, page: 1 }, rows = dl.rows || [], kolSpan, head;
            st.page = dl.page || 1;
            st.total_page = dl.total_page || 1;

            head = '<tr><th>Debitur</th><th>Kolek</th><th class="num">Hari tunggak</th><th class="num">Nunggak (x)</th><th class="num">Tgl<br>jatuh tempo</th>' +
                (ada ? '<th class="num">Total tunggakan<br>posisi awal</th>' : '') +
                '<th class="num">Kewajiban<br>bulan ini</th>';
            mg.forEach(function(m) {
                head += '<th class="num">Bayar minggu ' + esc(m.no) + '<br><span class="muted">' + tglPendek(m.dari) + '–' + tglPendek(m.sampai) + '</span></th>';
            });
            head += '<th class="num">Kurang kewajiban<br>posisi akhir</th>' + (ada ? '<th class="num">Total tunggakan<br>posisi akhir</th>' : '') +
                '<th>Status<br>kewajiban</th>' + (ada ? '<th>Status<br>tunggakan</th>' : '') + '</tr>';
            $('headDb').innerHTML = head;
            kolSpan = 5 + (ada ? 1 : 0) + 1 + mg.length + 1 + (ada ? 1 : 0) + 1 + (ada ? 1 : 0);

            $('infoDaftar').textContent = '· ' + nf.format(dl.total) + ' debitur' + ((st.nama || st.status) ? ' (sesuai pencarian)' : '');

            // Preview/PDF: seluruh daftar (bukan hanya halaman ini), sesuai rentang, dasar, AO, pencarian, status, dan urutan yang sedang berlaku
            var pc = new URLSearchParams({ laporan: 'progres', awal: d.sel.awal, akhir: d.sel.akhir, dasar: d.sel.dasar, format: 'pdf', daftar: '1' });
            if (d.sel.ao) pc.set('ao', d.sel.ao);
            if (st.nama) pc.set('nama', st.nama);
            if (st.status) pc.set('status', st.status);
            if (st.urut) pc.set('urut', st.urut);
            atur($('lihatDaftar'), 'api/export.php?' + pc.toString());

            tbDb.innerHTML = rows.length ? rows.map(function(x) {
                var h = '<tr><td class="deb"><b>' + esc(x.nama || '(tanpa nama)') + '</b><br><span class="muted">' + esc(x.norek) + (x.ao ? ' · ' + esc(x.ao) : '') + '</span><br>' +
                        '<span class="tag">' + esc(ALASAN[x.alasan] || x.alasan) + '</span>' +
                        (x.lewat_tempo ? '<span class="tag bad">Jatuh tempo ' + esc(tgl(x.tempo)) + '</span>' : '') +
                        (x.tanpa_kw && x.status !== 'Kredit lunas' ? '<span class="tag">Tanpa kewajiban bln ini</span>' : '') + '</td>' +
                    '<td>' + esc(x.kolek) + (x.kolek_akhir == null ? '' : ' → <span class="' + (Number(x.kolek_akhir) >= 3 ? 'bad' : '') + '">' + esc(x.kolek_akhir) + '</span>') + '</td>' +
                    '<td class="num">' + esc(x.hari) + (x.hari_akhir == null ? '' : ' → ' + esc(x.hari_akhir)) + '</td>' +
                    '<td class="num">' + (x.xnunggak == null ? '<span class="muted">–</span>' : esc(x.xnunggak)) + '</td>' +
                    '<td class="num">' + (x.tgl_jt == null ? '<span class="muted">–</span>' : '<span title="Jatuh tempo ' + esc(tgl(x.tempo)) + '">' + esc(x.tgl_jt) + '</span>') + '</td>' +
                    (ada ? '<td class="num">' + pb(rp(x.tung_pkk), rp(x.tung_bg)) + '</td>' : '') +
                    '<td class="num">' + pb(rp(x.kw_pkk), rp(x.kw_bg)) + '</td>';
                (x.minggu || []).forEach(function(w) {
                    h += '<td class="num">' + ((w.bayar_pkk > 0 || w.bayar_bg > 0) ? '<span class="bayar">' + pb(rp(w.bayar_pkk), rp(w.bayar_bg)) + '</span>' : '<span class="muted">–</span>') + '</td>';
                });
                h += '<td class="num ' + ((x.kurang_pkk + x.kurang_bg) > 0 ? 'bad' : '') + '">' + pb(rp(x.kurang_pkk), rp(x.kurang_bg)) + '</td>' +
                    (ada ? '<td class="num">' + (x.tung_pkk_akhir == null && x.tung_bg_akhir == null ? '–' : pb(rp(x.tung_pkk_akhir), rp(x.tung_bg_akhir))) + '</td>' : '') +
                    '<td>' + badge(x.status, 'kw') + (x.tgl_lunas ? '<br><span class="muted">' + esc(tgl(x.tgl_lunas)) + '</span>' : '') + '</td>' + (ada ? '<td>' + badge(x.status_tung, 'tung') + '</td>' : '') + '</tr>';
                return h;
            }).join('') : '<tr><td colspan="' + kolSpan + '" class="muted">' +
                ((st.nama || st.status) ? 'Tidak ada debitur yang cocok dengan pencarian.' : 'Tidak ada rekening yang memenuhi dasar kelompok ini pada posisi awal.') + '</td></tr>';

            $('pgInfo').textContent = 'Halaman ' + st.page + ' dari ' + st.total_page;
            $('pgPrev').disabled = st.page <= 1;
            $('pgNext').disabled = st.page >= st.total_page;
            $('pager').style.display = st.total_page > 1 ? 'flex' : 'none';
        }

        // filter utama: rentang tanggal, dasar kelompok, dan AO
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.awal.value || !f.akhir.value) { msg(false, 'Tanggal awal dan akhir wajib diisi.'); return; }
            if (f.akhir.value < f.awal.value) { msg(false, 'Tanggal akhir tidak boleh sebelum tanggal awal.'); return; }
            st.awal = f.awal.value; st.akhir = f.akhir.value; st.ao = f.ao.value; st.dasar = f.dasar.value; st.page = 1;
            muat(true);
        });

        // pencarian di daftar debitur: tidak mengubah rentang tanggal / dasar / AO yang berlaku
        fdb.addEventListener('submit', function(e) {
            e.preventDefault();
            var nama = fd.nama.value.trim();
            if (nama !== '' && nama.length < 2) { msg(false, 'Nama atau nomor rekening minimal 2 huruf, atau kosongkan.'); fd.nama.focus(); return; }
            st.nama = nama; st.status = fd.status.value; st.urut = fd.urut.value; st.per = fd.per.value; st.page = 1;
            muat(false);
        });
        function kirimDb() { if (fdb.requestSubmit) fdb.requestSubmit(); else fdb.dispatchEvent(new Event('submit', { cancelable: true })); }
        fd.status.addEventListener('change', kirimDb);
        fd.urut.addEventListener('change', kirimDb);
        fd.per.addEventListener('change', kirimDb);

        $('pgPrev').addEventListener('click', function() { if (st.page > 1) { st.page--; muat(false); } });
        $('pgNext').addEventListener('click', function() { if (st.page < st.total_page) { st.page++; muat(false); } });

        muat(true);   // awal: tanggal 1 bulan posisi terakhir sampai posisi terakhir; dasar dipilih backend (keduanya bila data tunggakan ada)
    })();
</script>
<?php page_foot();