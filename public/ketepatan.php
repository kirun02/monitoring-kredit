<?php
// Ketepatan bayar: siapa yang membayar kewajiban bulanannya tepat waktu, terlambat, sebagian, atau tidak bayar,
// dan siapa yang langganan bermasalah (belum bayar atau telat bayar) lintas bulan.
// Tidak menyentuh database: data lewat api/api_ketepatan.php (proxy ke API). Pokok dan bunga selalu ditampilkan terpisah.
require __DIR__ . '/lib.php';

page_head('Ketepatan bayar', 'ketepatan');
?>
<style>
    #tblBln td.num, #tblBln th.num, #tblDb td.num, #tblDb th.num { text-align: right; }
    #tblBln td.mgg { white-space: normal; min-width: 190px; text-align: left; }
    #tblDb td.deb { white-space: normal; min-width: 210px; text-align: left; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    a.btn { text-decoration: none; display: inline-block; }
    a.btn[aria-disabled="true"] { opacity: .45; pointer-events: none; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    .tag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; background: #E9F1EF; color: var(--ink); font-weight: 600; }
    .tag.good { background: #E4F2EC; color: var(--good); }
    .tag.bad { background: #FBE9E9; color: var(--bad); }
    .tag.warn { background: #FFF4DC; color: var(--warn); }
    @media print { .top, .no-print { display: none !important; } main { padding: 0; } .card { border: 0; padding: 0; } }
</style>

<div>
    <h1>Ketepatan bayar</h1>
    <div class="sub" id="subTeks">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fKt" class="card filter no-print" novalidate>
    <label class="f">Dari bulan<select name="dari"><option value="">Memuat…</option></select></label>
    <label class="f">Sampai bulan<select name="sampai"><option value="">Memuat…</option></select></label>
    <label class="f">Toleransi telat
        <select name="toleransi">
            <option value="0">Tanpa toleransi</option>
            <option value="1">1 hari</option>
            <option value="2">2 hari</option>
            <option value="3">3 hari</option>
            <option value="5">5 hari</option>
            <option value="7">7 hari</option>
        </select>
    </label>
    <label class="f">AO
        <select name="ao"><option value="">Semua AO</option></select>
    </label>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
</form>

<div id="peringatan"></div>

<div class="row">
    <div class="card kpi"><div class="muted">Tepat waktu terus</div><div class="v good" id="kTepat">–</div><div class="n">semua bulan yang dinilai tepat</div></div>
    <div class="card kpi"><div class="muted">Selalu bermasalah</div><div class="v bad" id="kSelalu">–</div><div class="n">belum bayar atau telat di semua bulan (min. 2 bulan)</div></div>
    <div class="card kpi"><div class="muted">Campuran</div><div class="v" id="kCampur">–</div><div class="n">kadang tepat, kadang bermasalah</div></div>
    <div class="card kpi"><div class="muted">Bermasalah, baru 1 bulan data</div><div class="v bad" id="kSatu">–</div><div class="n">belum bisa disebut "selalu"</div></div>
    <div class="card kpi"><div class="muted">Belum dinilai</div><div class="v" id="kBelum">–</div><div class="n" id="kBelumN">belum jatuh tempo / tanpa kewajiban bulan itu</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <a class="btn" id="unduhXlsx" href="#" aria-disabled="true">Unduh Excel (lengkap)</a>
        <a class="btn" id="unduhPdf" href="#" target="_blank" rel="noopener" aria-disabled="true">Cetak PDF (ringkas)</a>
    </div>
    <div class="scroll">
        <table id="tblBln" style="width:100%">
            <thead>
                <tr>
                    <th>Bulan</th>
                    <th class="num">Dinilai</th>
                    <th class="num">Tepat waktu</th>
                    <th class="num">Terlambat,<br>sudah bayar</th>
                    <th class="num">Terlambat,<br>belum bayar</th>
                    <th class="num">Lost<br><span class="muted">&gt; 90 hari, tanpa bayar</span></th>
                    <th class="num">Belum jatuh tempo</th>
                    <th class="num">Lunas / keluar<br><span class="muted">dari nominatif</span></th>
                    <th class="num">Kurang bayar<br><span class="muted">(terlambat + lost)</span></th>
                </tr>
            </thead>
            <tbody><tr><td colspan="9" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">
        Penilaian memakai <b>hari tunggak</b> dari nominatif sendiri, bukan menebak tanggal bayar dari selisih antar posisi. Yang dinilai: rekening yang punya kewajiban bulan itu atau sudah menunggak.
        <b>Tepat waktu</b> = tidak pernah melewati toleransi dan kewajiban terbayar penuh (pokok dan bunga dicek sendiri-sendiri).
        <b>Terlambat, sudah bayar</b> = pernah melewati toleransi tetapi di posisi terakhir sudah normal. <b>Terlambat, belum bayar</b> = hari tunggak di posisi terakhir masih di atas toleransi.
        <b>Lost</b> = hari tunggak lebih dari 90 dan tidak ada pembayaran sama sekali di bulan itu. <b>Belum jatuh tempo</b> = belum terlambat dan belum bayar.
        <b>Lunas / keluar</b> = rekening hilang dari nominatif (lunas; hapus buku ikut) dan tidak dinilai. Makin banyak posisi harian tiap bulan, makin terlihat keterlambatan yang sudah dilunasi.
    </div>
</div>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;justify-content:space-between;margin-bottom:12px" class="no-print">
        <div><b>Daftar debitur</b> <span class="muted" id="infoDaftar"></span></div>
        <form id="fDb" style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end" novalidate>
            <label class="f">Cari nama / no. rekening<input class="fi" type="search" name="nama" maxlength="40" placeholder="Minimal 2 huruf" autocomplete="off"></label>
            <label class="f">Pola
                <select name="pola">
                    <option value="">Semua</option>
                    <option value="selalu">Selalu bermasalah</option>
                    <option value="satu">Bermasalah (1 bulan data)</option>
                    <option value="campuran">Campuran</option>
                    <option value="tepat">Tepat waktu terus</option>
                    <option value="belum">Belum dinilai</option>
                </select>
            </label>
            <label class="f">Status bulan terakhir
                <select name="status">
                    <option value="">Semua</option>
                    <option value="lost">Lost</option>
                    <option value="telat_belum">Terlambat, belum bayar</option>
                    <option value="telat_bayar">Terlambat, sudah bayar</option>
                    <option value="tepat">Tepat waktu</option>
                    <option value="belum_tempo">Belum jatuh tempo</option>
                    <option value="keluar">Lunas / keluar</option>
                </select>
            </label>
            <label class="f">Minimal nunggak
                <select name="xmin">
                    <option value="0">Semua</option>
                    <option value="1">1 kali atau lebih</option>
                    <option value="2">2 kali atau lebih</option>
                    <option value="3">3 kali atau lebih</option>
                    <option value="4">4 kali atau lebih</option>
                    <option value="6">6 kali atau lebih</option>
                    <option value="12">12 kali atau lebih</option>
                </select>
            </label>
            <label class="f">Urutkan
                <select name="urut">
                    <option value="">Paling bermasalah</option>
                    <option value="tgl">Tanggal pinjam (1–31)</option>
                </select>
            </label>
            <label class="f">Per halaman
                <select name="per"><option>10</option><option selected>20</option><option>50</option></select>
            </label>
            <button type="submit" class="btn" id="cariDb">Cari</button>
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
        Diurutkan dari bulan bermasalah terbanyak, lalu hari tunggak terbanyak, atau menurut hari pada tanggal pinjam (1–31) bila dipilih di Urutkan.
        "angsuran tiap tanggal N" = hari pada tgl pinjam. "Nunggak (x)" = jumlah angsuran yang menunggak di posisi terakhir. Kurang bayar menunjukkan bulan terakhir,
        pokok dan bunga terpisah (kosong bila tidak terlambat atau tanpa kewajiban). Untuk seluruh daftar beserta rincian tiap bulan, unduh Excel.
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fKt'),
            f = form.elements,
            fdb = document.getElementById('fDb'),
            fd = fdb.elements,
            tbBln = document.querySelector('#tblBln tbody'),
            tbDb = document.querySelector('#tblDb tbody'),
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('tampil'),
            nf = new Intl.NumberFormat('id-ID'),
            NM = { '01': 'Jan', '02': 'Feb', '03': 'Mar', '04': 'Apr', '05': 'Mei', '06': 'Jun', '07': 'Jul', '08': 'Agu', '09': 'Sep', '10': 'Okt', '11': 'Nov', '12': 'Des' },
            POLA = { tepat: ['Tepat waktu terus', 'good'], selalu: ['Selalu bermasalah', 'bad'], campuran: ['Campuran', 'warn'], satu: ['Bermasalah (1 bulan data)', 'bad'], belum: ['Belum dinilai', ''] },
            STATUS = { tepat: ['Tepat waktu', 'good'], telat_bayar: ['Terlambat, sudah bayar', 'warn'], telat_belum: ['Terlambat, belum bayar', 'bad'], lost: ['Lost', 'bad'],
                       belum_tempo: ['Belum jatuh tempo', 'muted'], tanpa_kw: ['Tanpa kewajiban', 'muted'], keluar: ['Lunas / keluar', 'muted'] },
            st = { dari: '', sampai: '', ao: '', toleransi: '0', pola: '', status: '', xmin: '0', urut: '', nama: '', per: '20', page: 1, total_page: 1 },   // filter yang SEDANG berlaku
            seq = 0;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function rp(v) { return v == null ? '–' : nf.format(Math.round(Number(v) || 0)); }
        function pct(v) { return v == null ? '–' : Number(v).toFixed(1).replace('.', ',') + '%'; }
        function labelB(p) { return (NM[String(p).slice(5, 7)] || String(p).slice(5, 7)) + ' ' + String(p).slice(0, 4); }

        // pokok di atas, bunga di bawah; tidak pernah dijumlahkan
        function pb(p, b) { return '<span class="muted">Pokok</span> ' + p + '<br><span class="muted">Bunga</span> ' + b; }

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

        // segarkanForm = true: isi ulang pilihan bulan/AO dari yang dipakai server (muat pertama atau setelah menekan Tampilkan)
        function muat(segarkanForm) {
            var ini = ++seq, q = new URLSearchParams({ hal: st.page, per: st.per, toleransi: st.toleransi });
            if (st.dari) q.set('dari', st.dari);
            if (st.sampai) q.set('sampai', st.sampai);
            if (st.ao) q.set('ao', st.ao);
            if (st.pola) q.set('pola', st.pola);
            if (st.status) q.set('status', st.status);
            if (st.xmin !== '0') q.set('xmin', st.xmin);
            if (st.urut) q.set('urut', st.urut);
            if (st.nama) q.set('nama', st.nama);

            msg(true, '');
            btn.disabled = true;
            btn.textContent = 'Memuat…';
            $('cariDb').disabled = true;
            if (segarkanForm) {
                tbBln.innerHTML = '<tr><td colspan="9" class="muted">Memuat data…</td></tr>';
                atur($('unduhXlsx'), ''); atur($('unduhPdf'), '');
            }
            tbDb.innerHTML = '<tr><td class="muted">Memuat data…</td></tr>';

            fetch('api/api_ketepatan.php?' + q.toString(), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
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
                    tbBln.innerHTML = '<tr><td colspan="9" class="muted">Data belum bisa dimuat.</td></tr>';
                    tbDb.innerHTML = '<tr><td class="muted">Data belum bisa dimuat.</td></tr>';
                    $('pager').style.display = 'none';
                    $('subTeks').textContent = '';
                    msg(false, (e && e.message && !/^\d+$/.test(e.message)) ? e.message : 'Gagal memuat data. Coba lagi.');
                })
                .then(function() {
                    if (ini === seq) { btn.disabled = false; btn.textContent = 'Tampilkan'; $('cariDb').disabled = false; }
                });
        }

        function isiBulan(sel, daftar, pilih) {
            sel.innerHTML = (daftar || []).map(function(p) {
                return '<option value="' + esc(p) + '"' + (p === pilih ? ' selected' : '') + '>' + esc(labelB(p)) + '</option>';
            }).join('');
        }

        function isiAo(daftar, pilih) {
            var h = '<option value="">Semua AO</option>';
            (daftar || []).forEach(function(a) {
                h += '<option value="' + esc(a.v) + '"' + (a.v === pilih ? ' selected' : '') + '>' + esc(a.l) + '</option>';
            });
            f.ao.innerHTML = h;
        }

        function selBulan(e) {
            if (!e) return '<span class="muted">–</span>';
            var m = STATUS[e.status] || [e.status, 'muted'], ket = [];
            if (e.status === 'telat_belum' || e.status === 'lost') ket.push(e.hari_akhir + ' hari tunggak');
            else if (e.status === 'telat_bayar') ket.push('sempat ' + e.maks_hari + ' hari');
            if (e.telat_sejak && e.status !== 'tepat') ket.push('telat sejak ' + tglPendek(e.telat_sejak));
            return '<span class="' + m[1] + '"><b>' + esc(m[0]) + '</b></span>' + (ket.length ? '<br><span class="muted">' + esc(ket.join(' · ')) + '</span>' : '');
        }

        function gambar(d, segarkanForm) {
            if (d.empty) {
                tbBln.innerHTML = '<tr><td colspan="9" class="muted">' + esc(d.pesan || 'Belum ada data.') + '</td></tr>';
                tbDb.innerHTML = '<tr><td class="muted">' + esc(d.pesan || 'Belum ada data.') + '</td></tr>';
                $('subTeks').textContent = '';
                return;
            }
            var bl = d.bulan || [], pj = d.pola_jumlah || {};

            if (segarkanForm) {
                isiBulan(f.dari, d.periode_tersedia, d.sel.dari);
                isiBulan(f.sampai, d.periode_tersedia, d.sel.sampai);
                f.toleransi.value = String(d.sel.toleransi);
                isiAo(d.aos, d.sel.ao);
                st.dari = d.sel.dari; st.sampai = d.sel.sampai; st.ao = d.sel.ao; st.toleransi = String(d.sel.toleransi);
            }

            $('subTeks').textContent = labelB(d.sel.dari) + (d.sel.sampai !== d.sel.dari ? ' s/d ' + labelB(d.sel.sampai) : '') + ' · ' + nf.format(d.total_rek) +
                ' rekening (punya kewajiban atau sudah menunggak) · toleransi ' + (d.sel.toleransi ? d.sel.toleransi + ' hari' : 'tidak ada');
            $('kTepat').textContent = nf.format(pj.tepat || 0);
            $('kSelalu').textContent = nf.format(pj.selalu || 0);
            $('kCampur').textContent = nf.format(pj.campuran || 0);
            $('kSatu').textContent = nf.format(pj.satu || 0);
            $('kBelum').textContent = nf.format(pj.belum || 0);

            $('peringatan').innerHTML = (d.peringatan || []).map(function(t) {
                return '<div class="flash info">' + esc(t) + '</div>';
            }).join('');

            tbBln.innerHTML = bl.length ? bl.map(function(b) {
                return '<tr>' +
                    '<td class="mgg"><b>' + esc(labelB(b.p)) + '</b><br><span class="muted">posisi ' + tglPendek(b.dari) + '–' + tglPendek(b.sampai) + ' · ' + nf.format(b.posisi) + ' posisi' +
                        (b.kosong ? ' · ' + nf.format(b.kosong) + ' hari kerja kosong' : '') + '</span></td>' +
                    '<td class="num">' + nf.format(b.dinilai) + '<br><span class="muted">dari ' + nf.format(b.rek) + ' rek</span></td>' +
                    '<td class="num good"><b>' + nf.format(b.tepat) + '</b><br><span class="muted">' + pct(b.pct_tepat) + '</span></td>' +
                    '<td class="num">' + nf.format(b.telat_bayar) + '</td>' +
                    '<td class="num ' + (b.telat_belum ? 'bad' : '') + '">' + nf.format(b.telat_belum) + (b.telat_belum ? '<br><span class="muted">baki ' + rp(b.baki_telat) + '</span>' : '') + '</td>' +
                    '<td class="num ' + (b.lost ? 'bad' : '') + '"><b>' + nf.format(b.lost) + '</b>' + (b.lost ? '<br><span class="muted">baki ' + rp(b.baki_lost) + '</span>' : '') + '</td>' +
                    '<td class="num">' + nf.format(b.belum_tempo) + '</td>' +
                    '<td class="num">' + nf.format(b.keluar) + '</td>' +
                    '<td class="num ' + ((b.kurang_pkk + b.kurang_bg) > 0 ? 'bad' : '') + '">' + pb(rp(b.kurang_pkk), rp(b.kurang_bg)) + '</td></tr>';
            }).join('') : '<tr><td colspan="9" class="muted">Tidak ada data pada rentang ini.</td></tr>';

            gambarDaftar(d, bl);

            // tombol unduh mengikuti filter yang SEDANG berlaku (d.sel dari server) dan pencarian di daftar
            var p = new URLSearchParams({ laporan: 'ketepatan', dari: d.sel.dari, sampai: d.sel.sampai, toleransi: d.sel.toleransi });
            if (d.sel.ao) p.set('ao', d.sel.ao);
            if (st.pola) p.set('pola', st.pola);
            if (st.status) p.set('status', st.status);
            if (st.xmin !== '0') p.set('xmin', st.xmin);
            if (st.urut) p.set('urut', st.urut);
            if (st.nama) p.set('nama', st.nama);
            atur($('unduhXlsx'), 'api/export.php?' + p.toString() + '&format=xlsx');
            atur($('unduhPdf'), 'api/export.php?' + p.toString() + '&format=pdf');
        }

        function gambarDaftar(d, bl) {
            var dl = d.daftar || { rows: [], total: 0, total_page: 1, page: 1 }, rows = dl.rows || [], head;
            st.page = dl.page || 1;
            st.total_page = dl.total_page || 1;

            head = '<tr><th>Debitur</th><th>Pola</th>';
            bl.forEach(function(b) { head += '<th>' + esc(labelB(b.p)) + '</th>'; });
            head += '<th class="num">Kurang bayar<br><span class="muted">bulan terakhir</span></th><th class="num">Nunggak (x)</th></tr>';
            $('headDb').innerHTML = head;

            var disaring = !!(st.nama || st.pola || st.status || st.xmin !== '0');
            $('infoDaftar').textContent = '· ' + nf.format(dl.total) + ' debitur' + (disaring ? ' (sesuai pencarian)' : '');

            tbDb.innerHTML = rows.length ? rows.map(function(x) {
                var pl = POLA[x.pola] || [x.pola, ''],
                    h = '<tr><td class="deb"><b>' + esc(x.nama || '(tanpa nama)') + '</b><br><span class="muted">' + esc(x.norek) + (x.ao ? ' · ' + esc(x.ao) : '') + '</span>' +
                        (x.hari_angsuran ? '<br><span class="muted">angsuran tiap tanggal ' + esc(x.hari_angsuran) + '</span>' : '') + '</td>' +
                    '<td><span class="tag ' + pl[1] + '">' + esc(pl[0]) + '</span><br><span class="muted">' + esc(x.masalah) + ' dari ' + esc(x.dinilai) + ' bulan</span></td>';
                (x.bulan || []).forEach(function(e) { h += '<td>' + selBulan(e) + '</td>'; });
                h += '<td class="num ' + ((x.kurang_pkk + x.kurang_bg) > 0 ? 'bad' : '') + '">' + pb(rp(x.kurang_pkk), rp(x.kurang_bg)) + '</td>' +
                    '<td class="num">' + (x.xnunggak == null ? '<span class="muted">–</span>' : esc(x.xnunggak)) + '</td></tr>';
                return h;
            }).join('') : '<tr><td colspan="' + (4 + bl.length) + '" class="muted">' +
                (disaring ? 'Tidak ada debitur yang cocok dengan pencarian.' : 'Tidak ada rekening dengan kewajiban atau tunggakan pada rentang ini.') + '</td></tr>';

            $('pgInfo').textContent = 'Halaman ' + st.page + ' dari ' + st.total_page;
            $('pgPrev').disabled = st.page <= 1;
            $('pgNext').disabled = st.page >= st.total_page;
            $('pager').style.display = st.total_page > 1 ? 'flex' : 'none';
        }

        // filter utama: rentang bulan, toleransi, dan AO
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (!f.dari.value || !f.sampai.value) { msg(false, 'Pilih bulan awal dan bulan akhir.'); return; }
            if (f.sampai.value < f.dari.value) { msg(false, 'Bulan akhir tidak boleh sebelum bulan awal.'); return; }
            st.dari = f.dari.value; st.sampai = f.sampai.value; st.toleransi = f.toleransi.value; st.ao = f.ao.value; st.page = 1;
            muat(true);
        });

        // pencarian di daftar debitur: tidak mengubah rentang bulan / toleransi / AO yang berlaku
        fdb.addEventListener('submit', function(e) {
            e.preventDefault();
            var nama = fd.nama.value.trim();
            if (nama !== '' && nama.length < 2) { msg(false, 'Nama atau nomor rekening minimal 2 huruf, atau kosongkan.'); fd.nama.focus(); return; }
            st.nama = nama; st.pola = fd.pola.value; st.status = fd.status.value; st.xmin = fd.xmin.value; st.urut = fd.urut.value; st.per = fd.per.value; st.page = 1;
            muat(false);
        });
        function kirimDb() { if (fdb.requestSubmit) fdb.requestSubmit(); else fdb.dispatchEvent(new Event('submit', { cancelable: true })); }
        fd.pola.addEventListener('change', kirimDb);
        fd.status.addEventListener('change', kirimDb);
        fd.xmin.addEventListener('change', kirimDb);
        fd.urut.addEventListener('change', kirimDb);
        fd.per.addEventListener('change', kirimDb);

        $('pgPrev').addEventListener('click', function() { if (st.page > 1) { st.page--; muat(false); } });
        $('pgNext').addEventListener('click', function() { if (st.page < st.total_page) { st.page++; muat(false); } });

        muat(true);   // awal: 6 bulan terakhir yang ada datanya
    })();
</script>
<?php page_foot();