<?php
// Laporan pencapaian target AO: target vs aktual (OS, realisasi, NOA baru) per AO, lengkap dengan grafik. Hak: web_target_ao (menu Target AO).
// Data lewat api/api_kpi_ao.php; Excel lewat api/export_target.php. Akun AO hanya melihat miliknya (dikunci di backend).
require __DIR__ . '/lib.php';
page_head('Pencapaian target AO', 'target');
?>
<style>
    .kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
    .kpi { border: 1px solid #D5DEDB; border-radius: 12px; padding: 14px 16px; background: #fff; }
    .kpi .jd { font-size: 13px; color: #5B6B67; }
    .kpi .pc { font-size: 30px; font-weight: 700; line-height: 1.2; margin: 2px 0 6px; }
    .kpi .bar { height: 8px; border-radius: 6px; background: #E6ECEA; overflow: hidden; }
    .kpi .bar > i { display: block; height: 100%; background: #0E6B63; }
    .kpi .bar > i.mid { background: #D99A00; }
    .kpi .bar > i.low { background: #C0392B; }
    .kpi .ket { font-size: 12px; color: #5B6B67; margin-top: 6px; }
    .cp-ok  { background: #E3F3E7; color: #14532D; font-weight: 600; }
    .cp-mid { background: #FFF3CD; color: #7A5600; font-weight: 600; }
    .cp-low { background: #FDE2E2; color: #8A1C1C; font-weight: 600; }
    #tblT td.num, #tblT th.num { text-align: right; white-space: nowrap; }
    #tblT tr.tot td { font-weight: 700; background: #EEF3F1; }
    #tblT tr.kantor td { color: #5B6B67; }
    .grafik { display: grid; grid-template-columns: repeat(auto-fit, minmax(380px, 1fr)); gap: 16px; }
    .grafik .gc { border: 1px solid #D5DEDB; border-radius: 12px; padding: 12px; background: #fff; }
    .grafik .gc h3 { margin: 0 0 8px; font-size: 14px; }
    .grafik .cv { position: relative; height: 320px; }
</style>

<div>
    <h1>Pencapaian target AO</h1>
    <div class="sub" id="sub">Memuat…</div>
</div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:12px;align-items:center">
    <a class="btn" href="target.php">Atur target</a>
    <div class="bg" style="margin-left:auto">
        <a class="bg-btn bg-xls" id="unduhXlsx" href="api/export_target.php" title="Unduh Excel (tabel dan grafik)"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-xls"/></svg>Excel</a>
    </div>
</div>

<div class="kpi-grid" id="kpi"></div>

<div class="card">
    <b>Pencapaian per AO</b>
    <div class="scroll" style="margin-top:8px">
        <table id="tblT" class="tl" style="width:100%">
            <thead>
                <tr>
                    <th rowspan="2">AO</th>
                    <th class="num" colspan="4" style="text-align:center">OS (baki debet)</th>
                    <th class="num" colspan="3" style="text-align:center">Realisasi kredit baru</th>
                    <th class="num" colspan="3" style="text-align:center">NOA baru</th>
                    <th class="num" colspan="3" style="text-align:center">Penagihan (pembayaran)</th>
                </tr>
                <tr>
                    <th class="num">Target</th><th class="num">Aktual</th><th class="num">Capai</th><th class="num">Tumbuh bulan ini</th>
                    <th class="num">Target</th><th class="num">Aktual</th><th class="num">Capai</th>
                    <th class="num">Target</th><th class="num">Aktual</th><th class="num">Capai</th>
                    <th class="num">Target</th><th class="num">Aktual</th><th class="num">Capai</th>
                </tr>
            </thead>
            <tbody id="tbT"><tr><td colspan="14" class="muted">Memuat…</td></tr></tbody>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Semua angka dalam Rupiah (NOA dalam jumlah rekening). Capai = aktual dibagi target. Warna: hijau 100% ke atas, kuning 70–99%, merah di bawah 70%. Tanda – = target belum diisi. Tumbuh bulan ini = OS sekarang dikurangi OS awal bulan. Penagihan = total pembayaran pokok dan bunga bulan ini, termasuk angsuran debitur lancar; pelunasan tidak dihitung.</div>
</div>

<div class="grafik" id="grafik" hidden>
    <div class="gc"><h3>OS: target vs aktual per AO</h3><div class="cv"><canvas id="cOS"></canvas></div></div>
    <div class="gc"><h3>Realisasi kredit baru: target vs aktual per AO</h3><div class="cv"><canvas id="cReal"></canvas></div></div>
    <div class="gc"><h3>Penagihan (pembayaran bulan ini): target vs aktual per AO</h3><div class="cv"><canvas id="cTagih"></canvas></div></div>
    <div class="gc"><h3>Persentase capai per AO (garis merah = 100%)</h3><div class="cv"><canvas id="cPct"></canvas></div></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script>
    (function() {
        var nf = new Intl.NumberFormat('id-ID'), nf1 = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 1 }),
            msgBox = document.getElementById('msg'), charts = [];

        function $(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function msg(ok, t) { msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : ''; }
        function rp(v) { return v == null ? '–' : nf.format(Math.round(v)); }
        function pct(v) { return v == null ? '–' : nf1.format(v) + '%'; }
        function kelas(v) { return v == null ? '' : (v >= 100 ? 'cp-ok' : (v >= 70 ? 'cp-mid' : 'cp-low')); }
        function hit(a, b) { return b > 0 ? Math.round(a * 1000 / b) / 10 : null; }
        function singkat(v) {
            var a = Math.abs(v);
            if (a >= 1e9) return (v / 1e9).toFixed(1).replace('.', ',') + ' M';
            if (a >= 1e6) return Math.round(v / 1e6) + ' jt';
            return nf.format(v);
        }
        function nama(r) { return r.nama_ao || r.nama || r.nama_user || r.kode_ao || '-'; }
        function tgl(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || ''); return m ? m[3] + '/' + m[2] + '/' + m[1] : (s || ''); }
        var BLN = ['Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        function bulan(p) { var m = /^(\d{4})-(\d{2})/.exec(p || ''); return m ? BLN[+m[2] - 1] + ' ' + m[1] : (p || ''); }
        function num(v) { return v == null ? 0 : Number(v) || 0; }

        function kartu(judul, aktual, target, fmt, satuan) {
            var p = hit(aktual, target), k = kelas(p), lebar = p == null ? 0 : Math.min(p, 100);
            return '<div class="kpi"><div class="jd">' + esc(judul) + '</div><div class="pc">' + pct(p) + '</div>' +
                   '<div class="bar"><i class="' + (k === 'cp-ok' ? '' : (k === 'cp-mid' ? 'mid' : 'low')) + '" style="width:' + lebar + '%"></i></div>' +
                   '<div class="ket">Aktual ' + satuan + fmt(aktual) + ' dari target ' + satuan + (target > 0 ? fmt(target) : '–') + '</div></div>';
        }

        function gambar(rows) {
            charts.forEach(function(c) { c.destroy(); }); charts = [];
            var ao = rows.filter(function(r) { return !r.is_kantor; });
            if (typeof Chart === 'undefined' || !ao.length) { $('grafik').hidden = true; return; }
            $('grafik').hidden = false;
            var lab = ao.map(function(r) { var n = nama(r); return n.length > 18 ? n.slice(0, 17) + '…' : n; });
            var sb = { ticks: { callback: function(v) { return singkat(v); } }, beginAtZero: true };
            var TG = '#9CA3AF', AK = '#0E6B63';
            function dua(id, t1, v1, t2, v2) {
                charts.push(new Chart($(id), {
                    type: 'bar',
                    data: { labels: lab, datasets: [{ label: t1, data: v1, backgroundColor: TG }, { label: t2, data: v2, backgroundColor: AK }] },
                    options: { responsive: true, maintainAspectRatio: false, scales: { y: sb },
                               plugins: { tooltip: { callbacks: { label: function(c) { return c.dataset.label + ': Rp ' + nf.format(Math.round(c.parsed.y)); } } } } }
                }));
            }
            dua('cOS', 'Target OS', ao.map(function(r) { return num(r.target_os); }), 'OS aktual', ao.map(function(r) { return num(r.aktual_os); }));
            dua('cReal', 'Target realisasi', ao.map(function(r) { return num(r.target_realisasi); }), 'Realisasi aktual', ao.map(function(r) { return num(r.aktual_realisasi); }));
            dua('cTagih', 'Target penagihan', ao.map(function(r) { return num(r.target_penagihan); }), 'Pembayaran aktual', ao.map(function(r) { return num(r.aktual_penagihan); }));
            charts.push(new Chart($('cPct'), {
                type: 'bar',
                data: { labels: lab, datasets: [
                    { label: 'Capai OS (%)',        data: ao.map(function(r) { return r.capai_os; }),   backgroundColor: '#0E6B63' },
                    { label: 'Capai realisasi (%)', data: ao.map(function(r) { return r.capai_real; }), backgroundColor: '#2563EB' },
                    { label: 'Capai NOA (%)',       data: ao.map(function(r) { return r.capai_noa; }),  backgroundColor: '#D97706' },
                    { label: 'Capai penagihan (%)', data: ao.map(function(r) { return r.capai_tagih; }), backgroundColor: '#7C3AED' },
                    { type: 'line', label: '100%', data: lab.map(function() { return 100; }), borderColor: '#B91C1C', borderDash: [6, 4], borderWidth: 2, pointRadius: 0 }
                ] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { callback: function(v) { return v + '%'; } } } } }
            }));
        }

        function tampil(d) {
            var rows = d.rows || [];
            $('sub').textContent = 'Periode ' + bulan(d.periode) + ', posisi data ' + tgl(d.tgl_posisi) + '. Target diisi di menu Target AO.';

            var T = { to: 0, ao: 0, tr: 0, ar: 0, tn: 0, an: 0, tp: 0, ap: 0 };
            rows.forEach(function(r) {
                T.to += num(r.target_os); T.ao += num(r.aktual_os);
                T.tr += num(r.target_realisasi); T.ar += num(r.aktual_realisasi);
                T.tn += num(r.target_noa); T.an += num(r.noa_realisasi);
                T.tp += num(r.target_penagihan); T.ap += num(r.aktual_penagihan);
            });
            $('kpi').innerHTML = kartu('OS (baki debet)', T.ao, T.to, rp, 'Rp ') + kartu('Realisasi kredit baru', T.ar, T.tr, rp, 'Rp ') + kartu('NOA baru', T.an, T.tn, rp, '') + kartu('Penagihan (pembayaran bulan ini)', T.ap, T.tp, rp, 'Rp ');

            var h = rows.map(function(r) {
                var nm = '<b>' + esc(nama(r)) + '</b>' + (r.kode_ao && nama(r) !== r.kode_ao ? '<div class="muted">' + esc(r.kode_ao) + '</div>' : '');
                return '<tr' + (r.is_kantor ? ' class="kantor"' : '') + '><td>' + nm + '</td>' +
                    '<td class="num">' + (num(r.target_os) > 0 ? rp(r.target_os) : '–') + '</td><td class="num">' + rp(r.aktual_os) + '</td><td class="num ' + kelas(r.capai_os) + '">' + pct(r.capai_os) + '</td>' +
                    '<td class="num">' + (r.pertumbuhan_os == null ? '–' : rp(r.pertumbuhan_os)) + '</td>' +
                    '<td class="num">' + (num(r.target_realisasi) > 0 ? rp(r.target_realisasi) : '–') + '</td><td class="num">' + rp(r.aktual_realisasi) + '</td><td class="num ' + kelas(r.capai_real) + '">' + pct(r.capai_real) + '</td>' +
                    '<td class="num">' + (num(r.target_noa) > 0 ? rp(r.target_noa) : '–') + '</td><td class="num">' + rp(r.noa_realisasi) + '</td><td class="num ' + kelas(r.capai_noa) + '">' + pct(r.capai_noa) + '</td>' +
                    '<td class="num">' + (num(r.target_penagihan) > 0 ? rp(r.target_penagihan) : '–') + '</td><td class="num">' + rp(r.aktual_penagihan) + '</td><td class="num ' + kelas(r.capai_tagih) + '">' + pct(r.capai_tagih) + '</td></tr>';
            }).join('');
            var tumbuh = 0; rows.forEach(function(r) { tumbuh += num(r.pertumbuhan_os); });
            h += '<tr class="tot"><td>TOTAL</td><td class="num">' + (T.to > 0 ? rp(T.to) : '–') + '</td><td class="num">' + rp(T.ao) + '</td><td class="num ' + kelas(hit(T.ao, T.to)) + '">' + pct(hit(T.ao, T.to)) + '</td><td class="num">' + rp(tumbuh) + '</td>' +
                 '<td class="num">' + (T.tr > 0 ? rp(T.tr) : '–') + '</td><td class="num">' + rp(T.ar) + '</td><td class="num ' + kelas(hit(T.ar, T.tr)) + '">' + pct(hit(T.ar, T.tr)) + '</td>' +
                 '<td class="num">' + (T.tn > 0 ? rp(T.tn) : '–') + '</td><td class="num">' + rp(T.an) + '</td><td class="num ' + kelas(hit(T.an, T.tn)) + '">' + pct(hit(T.an, T.tn)) + '</td>' +
                 '<td class="num">' + (T.tp > 0 ? rp(T.tp) : '–') + '</td><td class="num">' + rp(T.ap) + '</td><td class="num ' + kelas(hit(T.ap, T.tp)) + '">' + pct(hit(T.ap, T.tp)) + '</td></tr>';
            $('tbT').innerHTML = h;
            gambar(rows);
        }

        fetch('api/api_kpi_ao.php', { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok || d.error) throw new Error(d.error || 'Gagal (kode ' + r.status + ').'); return d; });
            })
            .then(function(d) {
                if (d.empty) { $('sub').textContent = ''; $('tbT').innerHTML = '<tr><td colspan="14" class="muted">' + esc(d.pesan) + '</td></tr>'; return; }
                tampil(d);
            })
            .catch(function(e) {
                if (e.message === '401') return;
                $('sub').textContent = '';
                $('tbT').innerHTML = '<tr><td colspan="14" class="muted">–</td></tr>';
                msg(false, e.message);
            });
    })();
</script>
<?php page_foot();