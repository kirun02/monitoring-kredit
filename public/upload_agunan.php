<?php
// Upload agunan dari Excel (.xlsx). Menu: web_upload_agunan. Hanya admin/pimpinan (akun AO ditolak oleh API).
// Excel dibaca di hosting lewat api/api_agunan_upload.php, barisnya dikirim terenkripsi ke API (Agunan.php). Isi file tidak disimpan di hosting.
require __DIR__ . '/lib.php';
page_head('Upload agunan', 'upload_agunan');
?>
<div>
    <h1>Upload agunan</h1>
    <div class="sub">Unggah file Excel (.xlsx) berisi data agunan. Kunci data: <b>no. rekening + kode agunan</b>. Yang belum ada ditambahkan, yang sudah ada diperbarui bila ada nilai yang berubah.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fAgn" class="card" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
        <label class="f" style="flex:1 1 320px">File Excel (.xlsx)
            <input type="file" name="berkas" id="berkas" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet">
        </label>
        <label style="display:inline-flex;align-items:center;gap:8px;cursor:pointer">
            <input type="checkbox" name="abaikan_kosong" id="abaikan" value="1"> Sel kosong tidak menimpa data lama
        </label>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:12px;align-items:center;margin-top:14px">
        <button type="button" class="btn primary" id="btnPeriksa">Periksa dulu (tidak menyimpan)</button>
        <button type="button" class="btn" id="btnSimpan" disabled>Simpan ke database</button>
        <span class="muted" id="status"></span>
    </div>
    <div class="muted" style="margin-top:10px">
        Tombol <b>Simpan</b> aktif setelah file yang sama lolos pemeriksaan. Mengganti file atau pilihan di atas mengharuskan memeriksa ulang.
        File besar diproses bertahap (2.000 baris per kiriman) dan bisa memakan beberapa menit; jangan tutup halaman sebelum selesai.
    </div>
</form>

<div class="card">
    <b>Format Excel</b>
    <div class="muted" style="margin:6px 0 8px">Baris judul harus memuat nama kolom berikut (huruf besar/kecil bebas, urutan bebas, kolom lain diabaikan). Sebaiknya kolom <b>account</b> dan <b>kodereg</b> berformat teks.</div>
    <div class="scroll">
        <table style="width:100%">
            <thead><tr><th>Kolom Excel</th><th>Isi di database</th></tr></thead>
            <tbody>
                <tr><td><code>account</code> *</td><td>No. rekening (kunci)</td></tr>
                <tr><td><code>kodereg</code> *</td><td>Kode agunan (kunci)</td></tr>
                <tr><td><code>pmilik</code></td><td>Pemilik agunan</td></tr>
                <tr><td><code>rincian</code></td><td>Jenis agunan</td></tr>
                <tr><td><code>n_bank</code>, <code>n_ht</code>, <code>n_njop</code></td><td>Nilai bank, nilai hak tanggungan, nilai NJOP (angka rupiah)</td></tr>
                <tr><td><code>tgl_penilaian</code></td><td>Tanggal pengikatan (tanggal Excel, atau teks dd/mm/yyyy atau yyyy-mm-dd)</td></tr>
                <tr><td><code>alamat_agunan</code></td><td>Alamat agunan</td></tr>
                <tr><td><code>jnsikat</code></td><td>Jenis ikat</td></tr>
            </tbody>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">* wajib ada nilainya di tiap baris. Bila <b>sel kosong tidak menimpa</b> tidak dicentang, data di database mengikuti Excel apa adanya (sel kosong mengosongkan nilai lama), dan semua kolom harus ada di file.</div>
</div>

<div id="hasil"></div>

<script>
    (function() {
        var form = document.getElementById('fAgn'), berkas = document.getElementById('berkas'), abaikan = document.getElementById('abaikan'),
            btnP = document.getElementById('btnPeriksa'), btnS = document.getElementById('btnSimpan'), status = document.getElementById('status'),
            msgBox = document.getElementById('msg'), hasil = document.getElementById('hasil'), nf = new Intl.NumberFormat('id-ID'),
            lulus = '', gagalCsv = [];

        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function msg(ok, t) { msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : ''; }
        function sidik() { var f = berkas.files[0]; return f ? [f.name, f.size, f.lastModified, abaikan.checked ? 1 : 0].join('|') : ''; }
        function segarkanTombol() { btnS.disabled = !(lulus && lulus === sidik()); }

        berkas.addEventListener('change', function() { lulus = ''; segarkanTombol(); hasil.innerHTML = ''; msg(true, ''); });
        abaikan.addEventListener('change', function() { lulus = ''; segarkanTombol(); });

        function kpi(l, v, c, n) { return '<div class="card kpi"><div class="muted">' + l + '</div><div class="v ' + (c || '') + '">' + nf.format(v) + '</div>' + (n ? '<div class="n">' + n + '</div>' : '') + '</div>'; }

        function tampil(d) {
            var simpan = d.mode === 'simpan';
            gagalCsv = d.gagal || [];
            var h = '<div class="flash ' + (d.gagal_n ? 'info' : 'ok') + '">' +
                (simpan ? 'Selesai disimpan: ' : 'Hasil pemeriksaan (belum ada yang disimpan): ') + esc(d.berkas) + ' · ' + nf.format(d.baris_file) + ' baris data dibaca · ' + nf.format(d.kiriman) + ' kiriman.' + '</div>';
            if (d.kolom_tidak_ada && d.kolom_tidak_ada.length) h += '<div class="flash info">Kolom tidak ada di file (tidak mengubah data lama): ' + esc(d.kolom_tidak_ada.join(', ')) + '</div>';
            h += '<div class="row">' +
                kpi(simpan ? 'Baru ditambahkan' : 'Akan ditambahkan', d.baru, 'good') +
                kpi(simpan ? 'Diperbarui' : 'Akan diperbarui', d.diperbarui) +
                kpi('Tidak berubah', d.tidak_berubah) +
                kpi('Duplikat dalam file', (d.duplikat_file || 0) + (d.duplikat_api || 0), '', 'norek + kode agunan sama; baris terakhir dipakai') +
                kpi('Gagal', d.gagal_n, d.gagal_n ? 'bad' : '', 'dilewati, tidak disimpan') +
                (d.dipotong ? kpi('Teks dipotong', d.dipotong, '', 'melebihi panjang kolom') : '') +
                '</div>';
            if (d.gagal_n) {
                h += '<div class="card"><div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;margin-bottom:8px"><b>Baris yang gagal</b>' +
                    '<button type="button" class="btn" id="unduhGagal">Unduh daftar (CSV)</button></div>' +
                    (d.gagal.length < d.gagal_n ? '<div class="muted" style="margin-bottom:6px">Menampilkan ' + nf.format(d.gagal.length) + ' dari ' + nf.format(d.gagal_n) + ' baris gagal.</div>' : '') +
                    '<div class="scroll"><table style="width:100%"><thead><tr><th>Baris Excel</th><th>No. rekening</th><th>Kode agunan</th><th>Alasan</th></tr></thead><tbody>' +
                    d.gagal.map(function(g) { return '<tr><td>' + esc(g.baris) + '</td><td>' + esc(g.norek) + '</td><td>' + esc(g.kodereg) + '</td><td>' + esc(g.alasan) + '</td></tr>'; }).join('') +
                    '</tbody></table></div></div>';
            }
            hasil.innerHTML = h;
            var u = document.getElementById('unduhGagal');
            if (u) u.addEventListener('click', function() {
                var baris = ['baris,norek,kodereg,alasan'].concat(gagalCsv.map(function(g) {
                    return [g.baris, g.norek, g.kodereg, g.alasan].map(function(x) { return '"' + String(x == null ? '' : x).replace(/"/g, '""') + '"'; }).join(',');
                }));
                var a = document.createElement('a');
                a.href = URL.createObjectURL(new Blob(['\ufeff' + baris.join('\r\n')], { type: 'text/csv;charset=utf-8' }));
                a.download = 'agunan_gagal.csv';
                document.body.appendChild(a); a.click(); a.remove();
            });
        }

        function kirim(mode) {
            if (!berkas.files.length) { msg(false, 'Pilih file Excel (.xlsx) dulu.'); return; }
            if (mode === 'simpan' && !confirm('Simpan data agunan dari file ini ke database?')) return;
            var fd = new FormData(form);
            fd.set('mode', mode);
            msg(true, ''); hasil.innerHTML = '';
            btnP.disabled = true; btnS.disabled = true;
            status.textContent = mode === 'simpan' ? 'Menyimpan… (file besar bisa beberapa menit)' : 'Memeriksa… (file besar bisa beberapa menit)';
            var kunciFile = sidik();
            fetch('api/api_agunan_upload.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function(r) {
                    if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                    return r.text().then(function(t) {
                        var d = null; try { d = JSON.parse(t); } catch (e) {}
                        if (!d) throw new Error(r.status === 413 ? 'File terlalu besar untuk server.' : 'Respons server tidak terbaca (kode ' + r.status + '). Kemungkinan file melebihi post_max_size di php.ini, atau token halaman kedaluwarsa; muat ulang halaman.');
                        if (!r.ok || d.error) throw new Error(d.error || ('Gagal (kode ' + r.status + ').'));
                        return d;
                    });
                })
                .then(function(d) {
                    tampil(d);
                    if (mode === 'periksa') { lulus = kunciFile; msg(true, 'Pemeriksaan selesai. Cek ringkasan di bawah; bila sudah sesuai, klik "Simpan ke database".'); }
                    else { lulus = ''; msg(true, 'Data agunan sudah disimpan.'); }
                })
                .catch(function(e) { if (e && e.message !== '401') msg(false, e.message || 'Gagal memproses file.'); })
                .then(function() { btnP.disabled = false; status.textContent = ''; segarkanTombol(); });
        }
        btnP.addEventListener('click', function() { kirim('periksa'); });
        btnS.addEventListener('click', function() { kirim('simpan'); });
    })();
</script>
<?php page_foot();
