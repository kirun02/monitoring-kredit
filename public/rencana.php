<?php
// Rencana kunjungan AO. Tidak menyentuh database: data lewat api/api_rencana.php dan api/api_kunjungan.php (proxy ke API).
// AO hanya mengelola rencananya sendiri (dipaksa di proxy); admin/pimpinan bisa memilih AO.
require __DIR__ . '/lib.php';

$ao = (string)((auth_user() ?? [])['staff'] ?? '') === 'ao';

page_head('Rencana kunjungan', 'rencana');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<style>
    .rn-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 10px; }
    .rn-lebar { grid-column: 1 / -1; }
    .rn-cari { display: flex; gap: 8px; }
    .rn-cari .fi { flex: 1; min-width: 0; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    .pager { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-top: 12px; }
    a.btn { text-decoration: none; display: inline-block; }
    #tblRn td.wrap { white-space: normal; min-width: 180px; text-align: left; }
    .st { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: .8rem; font-weight: 600; white-space: nowrap; }
    .st-belum { background: #f8dedb; color: #7c1913; }
    .st-monitoring { background: #fdecc8; color: #7a4b00; }
    .st-dikunjungi { background: #dcefe9; color: #0a4a44; }
    #hasilDeb { max-height: 260px; overflow: auto; }
    @media (max-width: 760px) { .rn-grid { grid-template-columns: 1fr; } }
    @media print { .top, .no-print { display: none !important; } }
</style>

<div>
    <h1>Rencana kunjungan</h1>
    <div class="sub">Rencana kunjungan debitur, rekap status, dan unggahan massal dari Excel.</div>
</div>

<div id="msg" aria-live="polite"></div>

<!-- ============ TAMBAH RENCANA ============ -->
<form id="fRen" class="card no-print" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="insert">

    <b>Tambah rencana kunjungan</b> <span class="muted">(untuk hari ini)</span>

    <?php if (!$ao): ?>
    <label class="f" style="margin-top:10px">AO
        <select class="fi" name="id_user" id="aoTambah"><option value="">- Pilih AO -</option></select>
    </label>
    <?php endif; ?>

    <label class="f" style="margin-top:10px">Cari debitur
        <span class="rn-cari">
            <input class="fi" type="search" id="cariDeb" placeholder="Nama debitur atau nomor rekening" autocomplete="off" enterkeyhint="search">
            <button type="button" class="btn" id="btnCariDeb">Cari</button>
        </span>
    </label>
    <div class="muted" id="infoDeb" style="margin-top:4px"></div>
    <ul class="dlg-list" id="hasilDeb"></ul>
    <div id="pgDeb" style="display:none;align-items:center;justify-content:space-between;gap:8px;margin:8px 0">
        <button type="button" class="btn" id="pgDebPrev">Sebelumnya</button>
        <span class="muted" id="pgDebInfo"></span>
        <button type="button" class="btn" id="pgDebNext">Berikutnya</button>
    </div>

    <div class="rn-grid">
        <label class="f">Nomor rekening *<input class="fi" name="rekening" maxlength="15" autocomplete="off"></label>
        <label class="f">Nama debitur *<input class="fi" name="nama" maxlength="100" autocomplete="off"></label>
        <label class="f rn-lebar">Alamat<input class="fi" name="alamat" maxlength="500" autocomplete="off"></label>
        <label class="f rn-lebar">Keterangan rencana<textarea class="fi" name="keterangan" rows="2" maxlength="500" placeholder="Opsional, misalnya tujuan kunjungan"></textarea></label>
    </div>
    <div style="margin-top:12px">
        <button type="submit" class="btn primary" id="simpanRen">Simpan rencana</button>
    </div>
</form>

<!-- ============ FILTER + REKAP + DAFTAR ============ -->
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

<!-- ============ UNGGAH EXCEL ============ -->
<form id="fUpl" class="card no-print" novalidate>
    <b>Unggah rencana dari Excel</b>
    <div class="hint" style="margin:4px 0 10px">
        Kolom: Tanggal, Rekening, Nama, Alamat, Keterangan (baris pertama berisi judul kolom). Maksimal 500 baris, ukuran 2 MB.
        <a href="api/api_rencana.php?jenis=template">Unduh template</a>
    </div>
    <?php if (!$ao): ?>
    <label class="f">AO pemilik rencana
        <select class="fi" id="aoUpl"><option value="">- Pilih AO -</option></select>
    </label>
    <?php endif; ?>
    <label class="f" style="margin-top:8px">Berkas Excel (.xlsx)
        <input class="fi" type="file" id="berkas" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
    </label>
    <div style="margin-top:12px"><button type="submit" class="btn primary" id="btnUpl">Unggah</button></div>
    <div id="hasilUpl" aria-live="polite" style="margin-top:10px"></div>
</form>

<script>
    (function() {
        var IS_AO = <?= $ao ? 'true' : 'false' ?>,
            fRen = document.getElementById('fRen'),
            fFil = document.getElementById('fFil'),
            msgBox = document.getElementById('msg'),
            CSRF = fRen.elements._csrf.value,
            LABEL = { 'belum': ['Belum', 'belum'], 'monitoring whatsapp': ['Monitoring WA', 'monitoring'], 'dikunjungi': ['Dikunjungi', 'dikunjungi'] },
            PER = 25,
            fil = { tgl1: '', tgl2: '', status: '', id_user: '' },
            data = [], halTbl = 1, seqDaftar = 0,
            deb = { q: '', hal: 1, tot: 1, list: [], seq: 0 };

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
            if (t) msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function ambil(url, opsi) {
            opsi = opsi || {};
            opsi.credentials = 'same-origin';
            opsi.headers = Object.assign({ 'Accept': 'application/json', 'X-Requested-With': 'fetch' }, opsi.headers || {});
            return fetch(url, opsi).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error(401); }
                return r.json().catch(function() { return {}; }).then(function(d) { return { ok: r.ok, status: r.status, d: d }; });
            });
        }

        function iso(d) { return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + ('0' + d.getDate()).slice(-2); }

        // ---------- daftar AO (admin/pimpinan) ----------
        function muatAO() {
            if (IS_AO) return;
            ambil('api/api_rencana.php?jenis=daftar_user').then(function(r) {
                var opsi = (r.d.data || []).map(function(x) {
                    return '<option value="' + x.id + '">' + esc(x.nama) + (x.tipe ? ' (' + esc(x.tipe) + ')' : '') + '</option>';
                }).join('');
                ['aoTambah', 'aoFil', 'aoUpl'].forEach(function(id) {
                    var el = $(id);
                    if (el) el.insertAdjacentHTML('beforeend', opsi);
                });
            }).catch(function() {});
        }

        // ---------- cari debitur (nama / rekening) ----------
        function renderDeb() {
            $('hasilDeb').innerHTML = deb.list.map(function(x, i) {
                return '<li><button type="button" class="dlg-item" data-i="' + i + '"><span><span class="t">' + esc(x.nama || '(tanpa nama)') + '</span><br>' +
                    '<span class="m">' + esc(x.norek) + (x.alamat ? ' · ' + esc(x.alamat) : '') + '</span></span>' +
                    '<span class="r">Kolek ' + esc(x.kolek) + '<br>' + esc(x.hari) + ' hari</span></button></li>';
            }).join('');
            $('pgDebInfo').textContent = 'Halaman ' + deb.hal + ' dari ' + deb.tot;
            $('pgDebPrev').disabled = deb.hal <= 1;
            $('pgDebNext').disabled = deb.hal >= deb.tot;
            $('pgDeb').style.display = deb.tot > 1 ? 'flex' : 'none';
        }

        function cariDeb(hal) {
            var q = hal === 1 ? $('cariDeb').value.trim() : deb.q, ini = ++deb.seq;
            if (q.length < 2) { $('infoDeb').textContent = 'Ketik minimal 2 huruf atau angka, lalu tekan Cari.'; return; }
            deb.q = q;
            $('infoDeb').textContent = 'Mencari…';
            ambil('api/api_kunjungan.php?jenis=cari&nama=' + encodeURIComponent(q) + '&page=' + hal).then(function(r) {
                if (ini !== deb.seq) return;
                if (!r.ok) { deb.list = []; renderDeb(); $('infoDeb').textContent = (r.d && r.d.error) || 'Pencarian belum bisa dilakukan. Isi data di bawah secara manual.'; return; }
                deb.list = r.d.rows || [];
                deb.hal = r.d.page || 1;
                deb.tot = r.d.total_page || 1;
                $('infoDeb').textContent = deb.list.length ? (r.d.total || deb.list.length) + ' debitur ditemukan. Ketuk satu untuk mengisi form.' : 'Tidak ada debitur yang cocok.';
                renderDeb();
            }).catch(function() { if (ini === deb.seq) $('infoDeb').textContent = 'Pencarian belum bisa dilakukan. Isi data di bawah secara manual.'; });
        }

        $('btnCariDeb').addEventListener('click', function() { cariDeb(1); });
        $('cariDeb').addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); cariDeb(1); } });   // Enter = Cari, bukan kirim form
        $('pgDebPrev').addEventListener('click', function() { if (deb.hal > 1) cariDeb(deb.hal - 1); });
        $('pgDebNext').addEventListener('click', function() { if (deb.hal < deb.tot) cariDeb(deb.hal + 1); });
        $('hasilDeb').addEventListener('click', function(e) {
            var b = e.target.closest('button[data-i]');
            if (!b) return;
            var x = deb.list[+b.getAttribute('data-i')];
            if (!x) return;
            fRen.elements.rekening.value = x.norek;
            fRen.elements.nama.value = x.nama;
            fRen.elements.alamat.value = x.alamat;
            fRen.elements.keterangan.focus();
        });

        // ---------- simpan rencana ----------
        var kirimRen = false;
        fRen.addEventListener('submit', function(e) {
            e.preventDefault();
            if (kirimRen) return;
            var f = fRen.elements;
            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            if (!IS_AO && !f.id_user.value) return gagal('Pilih AO untuk rencana ini.', f.id_user);
            if (!/^[0-9A-Za-z]{1,15}$/.test(f.rekening.value.trim())) return gagal('Nomor rekening harus huruf atau angka, maksimal 15 karakter.', f.rekening);
            if (!f.nama.value.trim()) return gagal('Nama debitur wajib diisi.', f.nama);

            kirimRen = true;
            $('simpanRen').disabled = true;
            $('simpanRen').textContent = 'Menyimpan…';
            ambil('api/api_rencana.php', { method: 'POST', body: new FormData(fRen) }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Rencana disimpan.');
                    var ao = IS_AO ? null : f.id_user.value;
                    fRen.reset();
                    if (ao) f.id_user.value = ao;     // admin: AO tetap terpilih untuk input berikutnya
                    deb.list = []; renderDeb(); $('infoDeb').textContent = ''; $('cariDeb').value = '';
                    muatDaftar();
                } else {
                    msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal menyimpan. Coba lagi.'));
                }
            }).catch(function() { msg(false, 'Permintaan gagal. Periksa koneksi, lalu cek daftar sebelum mengirim ulang.'); })
              .then(function() { kirimRen = false; $('simpanRen').disabled = false; $('simpanRen').textContent = 'Simpan rencana'; });
        });

        // ---------- daftar + rekap ----------
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

        function muatDaftar() {
            var ini = ++seqDaftar, p = new URLSearchParams({ jenis: 'daftar', tgl1: fil.tgl1, tgl2: fil.tgl2 });
            if (fil.status) p.set('status', fil.status);
            if (fil.id_user) p.set('id_user', fil.id_user);
            aturUnduh();
            $('btnFil').disabled = true;
            $('btnFil').textContent = 'Memuat…';
            $('tblRn').querySelector('tbody').innerHTML = '<tr><td colspan="6" class="muted">Memuat data…</td></tr>';

            ambil('api/api_rencana.php?' + p.toString()).then(function(r) {
                if (ini !== seqDaftar) return;
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
                if (ini !== seqDaftar) return;
                $('tblRn').querySelector('tbody').innerHTML = '<tr><td colspan="6" class="muted">Data belum bisa dimuat.</td></tr>';
                msg(false, (e && e.message && e.message !== 'gagal' && !/^\d+$/.test(e.message)) ? e.message : 'Daftar rencana belum bisa dimuat.');
            }).then(function() {
                if (ini === seqDaftar) { $('btnFil').disabled = false; $('btnFil').textContent = 'Tampilkan'; }
            });
        }

        fFil.addEventListener('submit', function(e) {
            e.preventDefault();
            var f = fFil.elements;
            if (!f.tgl1.value || !f.tgl2.value || f.tgl1.value > f.tgl2.value) { msg(false, 'Rentang tanggal tidak valid.'); return; }
            msg(false, '');
            fil = { tgl1: f.tgl1.value, tgl2: f.tgl2.value, status: f.status.value, id_user: IS_AO ? '' : f.id_user.value };
            muatDaftar();
        });
        $('pgPrev').addEventListener('click', function() { if (halTbl > 1) { halTbl--; gambarTabel(); } });
        $('pgNext').addEventListener('click', function() { halTbl++; gambarTabel(); });

        // ---------- unggah Excel ----------
        var kirimUpl = false;
        $('fUpl').addEventListener('submit', function(e) {
            e.preventDefault();
            if (kirimUpl) return;
            var file = $('berkas').files && $('berkas').files[0];
            if (!file) { msg(false, 'Pilih berkas Excel (.xlsx) terlebih dahulu.'); return; }
            if (!IS_AO && !$('aoUpl').value) { msg(false, 'Pilih AO pemilik rencana.'); $('aoUpl').focus(); return; }

            var fd = new FormData();
            fd.set('_csrf', CSRF);
            fd.set('jenis', 'upload');
            if (!IS_AO) fd.set('id_user', $('aoUpl').value);
            fd.set('file', file);

            kirimUpl = true;
            $('btnUpl').disabled = true;
            $('btnUpl').textContent = 'Mengunggah…';
            $('hasilUpl').innerHTML = '';
            ambil('api/api_rencana.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    $('hasilUpl').innerHTML = '<div class="flash ok">Ditambahkan: <b>' + esc(r.d.inserted) + '</b> · Dilewati (duplikat atau tidak valid): <b>' + esc(r.d.skipped) +
                        '</b> · Terhubung ke kunjungan yang sudah ada: <b>' + esc(r.d.updated) + '</b>' +
                        ((r.d.galat || []).length ? '<br>' + r.d.galat.map(esc).join('<br>') : '') + '</div>';
                    $('berkas').value = '';
                    muatDaftar();
                } else {
                    $('hasilUpl').innerHTML = '<div class="flash err">' + esc(r.d.error || 'Unggahan gagal. Coba lagi.') + '</div>';
                }
            }).catch(function() { $('hasilUpl').innerHTML = '<div class="flash err">Permintaan gagal. Periksa koneksi, lalu cek daftar sebelum mengunggah ulang.</div>'; })
              .then(function() { kirimUpl = false; $('btnUpl').disabled = false; $('btnUpl').textContent = 'Unggah'; });
        });

        // ---------- mulai ----------
        var now = new Date();
        fFil.elements.tgl1.value = fil.tgl1 = iso(new Date(now.getFullYear(), now.getMonth(), 1));
        fFil.elements.tgl2.value = fil.tgl2 = iso(now);
        muatAO();
        muatDaftar();
    })();
</script>
<?php page_foot();
