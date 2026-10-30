<?php
// Laporan rencana kunjungan: filter, rekap status, daftar, unduh Excel/PDF. Hanya membaca.
// Tidak menyentuh database: data lewat api/api_rencana.php (proxy ke API). Input rencana ada di rencana.php.
// AO hanya melihat rencananya sendiri (dipaksa di proxy); admin/pimpinan bisa memilih AO.
require __DIR__ . '/lib.php';

$ao = (string)((auth_user() ?? [])['staff'] ?? '') === 'ao';

page_head('Laporan rencana kunjungan', 'laprencana');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<style>
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    a.btn { text-decoration: none; display: inline-block; }
    #tblRn td.wrap { white-space: normal; min-width: 180px; text-align: left; }
    .st { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; white-space: nowrap; }
    .st-belum { background: #f8dedb; color: #7c1913; }
    .st-monitoring { background: #fdecc8; color: #7a4b00; }
    .st-dikunjungi { background: #dcefe9; color: #0a4a44; }
    @media print { .top, .no-print { display: none !important; } main { padding: 0; } }
</style>

<div>
    <h1>Laporan rencana kunjungan</h1>
    <div class="sub">Rekap status dan daftar rencana kunjungan menurut periode.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fFil" class="card filter no-print" novalidate>
    <label class="f">Dari tanggal<input type="date" name="tgl1" required></label>
    <label class="f">Sampai tanggal<input type="date" name="tgl2" required></label>
    <label class="f">Status
        <select name="status">
            <option value="">Semua</option>
            <option value="belum">Belum</option>
            <option value="monitoring whatsapp">Monitoring WA</option>
            <option value="dikunjungi">Dikunjungi</option>
        </select>
    </label>
    <?php if (!$ao): ?>
    <label class="f">AO<select name="id_user" id="aoFil"><option value="">Semua AO</option></select></label>
    <?php endif; ?>
    <button type="submit" class="btn primary" id="btnFil">Tampilkan</button>
    <div class="seg" role="group" aria-label="Rentang cepat">
        <button type="button" data-r="hari">Hari ini</button>
        <button type="button" data-r="7">7 hari</button>
        <button type="button" data-r="bulan">Bulan ini</button>
    </div>
</form>

<div class="row">
    <div class="card kpi"><div class="muted">Total rencana</div><div class="v" id="kTotal">–</div><div class="n">pada rentang tanggal</div></div>
    <div class="card kpi"><div class="muted">Belum</div><div class="v" id="kBelum">–</div><div class="n">belum ditindaklanjuti</div></div>
    <div class="card kpi"><div class="muted">Monitoring WA</div><div class="v" id="kMon">–</div><div class="n">dipantau lewat WhatsApp</div></div>
    <div class="card kpi"><div class="muted">Dikunjungi</div><div class="v" id="kKun">–</div><div class="n" id="kPersen">&nbsp;</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <a class="btn" id="unduhXlsx" href="#">Unduh Excel</a>
        <a class="btn" id="unduhPdf" href="#" target="_blank" rel="noopener">Cetak PDF</a>
    </div>
    <div class="scroll">
        <table id="tblRn" class="tl" style="width:100%">
            <thead>
                <tr><th>Tanggal</th><th>Debitur</th><th>AO</th><th>Status</th><th>Waktu kunjungan</th><th>Keterangan</th></tr>
            </thead>
            <tbody><tr><td colspan="6" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
    <div class="pager no-print" id="pager" style="display:none">
        <button type="button" class="btn" id="pgPrev">Sebelumnya</button>
        <span class="muted" id="pgInfo"></span>
        <button type="button" class="btn" id="pgNext">Berikutnya</button>
    </div>
    <div class="muted" id="infoTabel" style="margin-top:8px"></div>
</div>

<script>
    (function() {
        var IS_AO = <?= $ao ? 'true' : 'false' ?>,
            fFil = document.getElementById('fFil'),
            msgBox = document.getElementById('msg'),
            LABEL = { 'belum': ['Belum', 'belum'], 'monitoring whatsapp': ['Monitoring WA', 'monitoring'], 'dikunjungi': ['Dikunjungi', 'dikunjungi'] },
            PER = 25,
            fil = { tgl1: '', tgl2: '', status: '', id_user: '' },
            data = [], halTbl = 1, seq = 0;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
        }

        function ambil(url) {
            return fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } }).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                return r.json().catch(function() { return {}; }).then(function(d) { return { ok: r.ok, status: r.status, d: d }; });
            });
        }

        function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

        function muatAO() {
            if (IS_AO) return;
            ambil('api/api_rencana.php?jenis=daftar_user').then(function(r) {
                $('aoFil').insertAdjacentHTML('beforeend', (r.d.data || []).map(function(x) {
                    return '<option value="' + x.id + '">' + esc(x.nama) + (x.tipe ? ' (' + esc(x.tipe) + ')' : '') + '</option>';
                }).join(''));
            }).catch(function() {});
        }

        function aturUnduh() {
            var p = new URLSearchParams({ laporan: 'rencana_kunjungan', tgl1: fil.tgl1, tgl2: fil.tgl2 });
            if (fil.status) p.set('status', fil.status);
            if (fil.id_user) p.set('id_user', fil.id_user);
            $('unduhXlsx').href = 'api/export.php?' + p.toString() + '&format=xlsx';
            $('unduhPdf').href  = 'api/export.php?' + p.toString() + '&format=pdf';
        }

        function gambarTabel() {
            var tot = Math.max(1, Math.ceil(data.length / PER));
            halTbl = Math.min(halTbl, tot);
            var irisan = data.slice((halTbl - 1) * PER, halTbl * PER);
            $('tblRn').querySelector('tbody').innerHTML = irisan.length ? irisan.map(function(x) {
                var s = LABEL[x.status] || [x.status || '–', 'belum'];
                return '<tr><td>' + esc(x.tanggal) + '</td>' +
                    '<td class="wrap"><b>' + esc(x.nama) + '</b><br><span class="muted">' + esc(x.rekening) + '</span></td>' +
                    '<td>' + esc(x.nmuser || '–') + '</td>' +
                    '<td><span class="st st-' + s[1] + '">' + esc(s[0]) + '</span></td>' +
                    '<td>' + esc(x.waktu_kunjungan || '–') + '</td>' +
                    '<td class="wrap">' + (x.keterangan ? esc(x.keterangan) : '') + (x.ket_kunjungan && x.ket_kunjungan !== '-' ? '<br><span class="muted">Hasil: ' + esc(x.ket_kunjungan) + '</span>' : '') + '</td></tr>';
            }).join('') : '<tr><td colspan="6" class="muted">Tidak ada rencana pada rentang ini.</td></tr>';
            $('pgInfo').textContent = 'Halaman ' + halTbl + ' dari ' + tot;
            $('pgPrev').disabled = halTbl <= 1;
            $('pgNext').disabled = halTbl >= tot;
            $('pager').style.display = tot > 1 ? 'flex' : 'none';
        }

        function muat() {
            var ini = ++seq, p = new URLSearchParams({ jenis: 'daftar', tgl1: fil.tgl1, tgl2: fil.tgl2 });
            if (fil.status) p.set('status', fil.status);
            if (fil.id_user) p.set('id_user', fil.id_user);
            aturUnduh();
            $('btnFil').disabled = true;
            $('btnFil').textContent = 'Memuat…';
            $('tblRn').querySelector('tbody').innerHTML = '<tr><td colspan="6" class="muted">Memuat data…</td></tr>';

            ambil('api/api_rencana.php?' + p.toString()).then(function(r) {
                if (ini !== seq) return;
                if (!r.ok) throw new Error((r.d && r.d.error) || 'gagal');
                var k = r.d.rekap || {};
                data = r.d.rows || [];
                halTbl = 1;
                $('kTotal').textContent = k.total || 0;
                $('kBelum').textContent = k.belum || 0;
                $('kMon').textContent = k.monitoring || 0;
                $('kKun').textContent = k.dikunjungi || 0;
                $('kPersen').textContent = k.total ? Math.round(100 * (k.dikunjungi || 0) / k.total) + '% dari total' : '\u00a0';
                $('infoTabel').textContent = r.d.terpotong ? 'Hanya 3.000 rencana pertama yang ditampilkan. Persempit filter atau unduh laporan.' : '';
                gambarTabel();
            }).catch(function(e) {
                if (ini !== seq) return;
                $('tblRn').querySelector('tbody').innerHTML = '<tr><td colspan="6" class="muted">Data belum bisa dimuat.</td></tr>';
                msg(false, (e && e.message && e.message !== 'gagal' && !/^\d+$/.test(e.message)) ? e.message : 'Laporan belum bisa dimuat.');
            }).then(function() {
                if (ini === seq) { $('btnFil').disabled = false; $('btnFil').textContent = 'Tampilkan'; }
            });
        }

        function terapkan() {
            var f = fFil.elements;
            if (!f.tgl1.value || !f.tgl2.value || f.tgl1.value > f.tgl2.value) { msg(false, 'Rentang tanggal tidak valid.'); return; }
            msg(false, '');
            fil = { tgl1: f.tgl1.value, tgl2: f.tgl2.value, status: f.status.value, id_user: IS_AO ? '' : f.id_user.value };
            muat();
        }

        fFil.addEventListener('submit', function(e) { e.preventDefault(); terapkan(); });
        fFil.querySelectorAll('.seg button').forEach(function(b) {
            b.addEventListener('click', function() {
                var n = new Date(), a = new Date(n), r = b.getAttribute('data-r');
                if (r === '7') a.setDate(a.getDate() - 6);
                else if (r === 'bulan') a = new Date(n.getFullYear(), n.getMonth(), 1);
                fFil.elements.tgl1.value = iso(a);
                fFil.elements.tgl2.value = iso(n);
                terapkan();
            });
        });
        $('pgPrev').addEventListener('click', function() { if (halTbl > 1) { halTbl--; gambarTabel(); } });
        $('pgNext').addEventListener('click', function() { halTbl++; gambarTabel(); });

        var now = new Date();
        fFil.elements.tgl1.value = iso(new Date(now.getFullYear(), now.getMonth(), 1));
        fFil.elements.tgl2.value = iso(now);
        muatAO();
        terapkan();
    })();
</script>
<?php page_foot();
