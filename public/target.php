<?php
// Target AO per bulan: OS, realisasi, NOA, kunjungan, promosi, pasca kredit. Menu: web_target_ao.
// Tidak menyentuh database: data lewat api/api_target.php (proxy ke API Target_ao.php). Hak ubah diputuskan backend (role admin).
require __DIR__ . '/lib.php';
page_head('Target AO', 'target');
?>
<style>
    .ti { width: 128px; text-align: right; padding: 6px 8px; border: 1px solid #B8C6C2; border-radius: 8px; font: inherit; }
    .ti[readonly] { background: #F2F5F4; border-color: transparent; }
    tr.kotor { background: #FFF8E6; }
    #tbT td.num, #tbT th.num { text-align: right; }
</style>

<div>
    <h1>Target AO</h1>
    <div class="sub">Target bulanan per AO. Angka abu-abu di kolom isian adalah target bulan lalu sebagai acuan. Kosong = belum ada target.</div>
</div>

<div id="msg" aria-live="polite"></div>

<div class="card">
    <form id="fT" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:12px">
        <label class="f">Periode<input type="month" name="periode"></label>
        <button type="submit" class="btn" id="btnTampil">Tampilkan</button>
        <button type="button" class="btn" id="btnSalin" hidden>Salin dari bulan lalu</button>
    </form>
    <div class="scroll">
        <table class="tl" style="width:100%">
            <thead><tr>
                <th>AO</th><th class="num">OS sekarang</th><th class="num">Pembayaran bulan ini</th><th class="num">Target OS</th><th class="num">Target realisasi</th>
                <th class="num">Target NOA baru</th><th class="num">Target penagihan</th><th id="thAksi"></th>
            </tr></thead>
            <tbody id="tbT"><tr><td colspan="8" class="muted">Memuat…</td></tr></tbody>
        </table>
    </div>
</div>

<input type="hidden" id="csrf" value="<?= h(csrf_token()) ?>">
<script>
    (function() {
        var KOL  = ['target_os', 'target_realisasi', 'target_noa', 'target_penagihan'],
            LALU = ['lalu_os', 'lalu_realisasi', 'lalu_noa', 'lalu_penagihan'],
            fT = document.getElementById('fT'), tb = document.getElementById('tbT'), msgBox = document.getElementById('msg'),
            nf = new Intl.NumberFormat('id-ID'), boleh = false, seq = 0;

        function $(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
            if (t) msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function jfetch(url, opt) {
            return fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, opt || {})).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok || d.error) throw new Error(d.error || 'Gagal (kode ' + r.status + ').'); return d; });
            });
        }
        function inp(k, v, lalu) {
            return '<td class="num"><input class="ti" inputmode="numeric" data-k="' + k + '" value="' + (v == null ? '' : nf.format(v)) + '" placeholder="' + (lalu == null ? '0' : nf.format(lalu)) +
                   '" aria-label="' + k + '"' + (boleh ? '' : ' readonly') + '></td>';
        }
        function render(rows) {
            $('btnSalin').hidden = !boleh;
            $('thAksi').textContent = boleh ? 'Aksi' : '';
            tb.innerHTML = rows.length ? rows.map(function(r) {
                var h = '<tr data-ao="' + esc(r.kode_ao) + '"><td>' + esc(r.nama) + '<div class="muted">' + esc(r.kode_ao) + '</div></td>' +
                        '<td class="num">' + (r.os_sekarang == null ? '<span class="muted">–</span>' : nf.format(r.os_sekarang)) + '</td>' +
                        '<td class="num">' + (r.penagihan_sekarang == null ? '<span class="muted">–</span>' : nf.format(r.penagihan_sekarang)) + '</td>';
                KOL.forEach(function(k, i) { h += inp(k, r[k], r[LALU[i]]); });
                return h + (boleh ? '<td><button type="button" class="btn simpan" disabled>Simpan</button></td>' : '<td></td>') + '</tr>';
            }).join('') : '<tr><td colspan="8" class="muted">Tidak ada AO aktif.</td></tr>';
        }
        function muat(per) {
            var ini = ++seq;
            tb.innerHTML = '<tr><td colspan="8" class="muted">Memuat…</td></tr>';
            jfetch('api/api_target.php?aksi=baca' + (per ? '&periode=' + encodeURIComponent(per) : '')).then(function(d) {
                if (ini !== seq) return;
                boleh = !!d.boleh_ubah;
                fT.elements.periode.value = d.periode;
                render(d.rows || []);
            }).catch(function(e) { if (ini === seq && e.message !== '401') { tb.innerHTML = '<tr><td colspan="8" class="muted">–</td></tr>'; msg(false, e.message); } });
        }

        // isian: hanya angka; diformat saat keluar dari kolom
        tb.addEventListener('input', function(e) {
            var el = e.target; if (!el.classList.contains('ti')) return;
            el.value = el.value.replace(/\D+/g, '');
            var tr = el.closest('tr'); tr.classList.add('kotor');
            var b = tr.querySelector('.simpan'); if (b) b.disabled = false;
        });
        tb.addEventListener('focusin',  function(e) { if (e.target.classList.contains('ti') && boleh) e.target.value = e.target.value.replace(/\D+/g, ''); });
        tb.addEventListener('focusout', function(e) { var el = e.target; if (el.classList.contains('ti')) el.value = el.value === '' ? '' : nf.format(Number(el.value.replace(/\D+/g, ''))); });

        tb.addEventListener('click', function(e) {
            var b = e.target.closest('.simpan'); if (!b || b.disabled) return;
            var tr = b.closest('tr'), fd = new FormData();
            fd.set('_csrf', $('csrf').value);
            fd.set('ao', tr.getAttribute('data-ao'));
            fd.set('periode', fT.elements.periode.value);
            Array.prototype.forEach.call(tr.querySelectorAll('.ti'), function(el) { fd.set(el.getAttribute('data-k'), el.value.replace(/\D+/g, '') || '0'); });
            b.disabled = true; b.textContent = 'Menyimpan…';
            jfetch('api/api_target.php?aksi=simpan', { method: 'POST', body: fd })
                .then(function(d) { tr.classList.remove('kotor'); b.textContent = 'Simpan'; msg(true, (d.message || 'Target tersimpan.') + ' (' + tr.getAttribute('data-ao') + ')'); })
                .catch(function(err) { b.disabled = false; b.textContent = 'Simpan'; if (err.message !== '401') msg(false, err.message); });
        });

        $('btnSalin').addEventListener('click', function() {
            if (!confirm('Salin target bulan lalu ke periode ini? Target yang sudah terisi tidak ditimpa.')) return;
            var fd = new FormData(); fd.set('_csrf', $('csrf').value); fd.set('periode', fT.elements.periode.value);
            jfetch('api/api_target.php?aksi=salin', { method: 'POST', body: fd })
                .then(function(d) { msg(true, d.message || 'Selesai.'); muat(fT.elements.periode.value); })
                .catch(function(err) { if (err.message !== '401') msg(false, err.message); });
        });
        fT.addEventListener('submit', function(e) { e.preventDefault(); muat(fT.elements.periode.value); });
        muat('');
    })();
</script>
<?php page_foot();