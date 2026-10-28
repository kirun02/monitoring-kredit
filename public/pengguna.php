<?php
// Pengguna aplikasi. Akses diatur lewat menu Hak Akses ('web_pengguna'), dicek otomatis oleh page_head. Tidak menyentuh database: semuanya lewat api/api_pengguna.php (proxy ke API).
require __DIR__ . '/lib.php';

page_head('Pengguna aplikasi', 'pengguna');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<link rel="stylesheet" href="assets/karyawan.css">
<link rel="stylesheet" href="assets/pengguna.css">

<div>
    <h1>Pengguna aplikasi</h1>
</div>

<?php tabs_html('web_pengguna'); ?>

<div id="msg" aria-live="polite"></div>

<form id="fPg" class="card" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="input">
    <input type="hidden" name="id_pengguna" value="0">

    <b id="formJudul">Tambah pengguna</b>

    <div class="pg-form" style="margin-top:12px">
        <div class="kar-row">
            <label for="username">Username <span class="wajib">*</span></label>
            <input class="fi" id="username" name="username" maxlength="30" autocomplete="off" autocapitalize="none" spellcheck="false">
        </div>
        <div class="kar-row">
            <label for="nama">Nama <span class="wajib">*</span></label>
            <input class="fi" id="nama" name="nama" maxlength="100" autocomplete="off">
        </div>
        <div class="kar-row">
            <label for="role">Role <span class="wajib">*</span></label>
            <select class="fi" id="role" name="role">
                <option value="">- Pilih -</option>
                <option value="admin">Administrator</option>
                <option value="pimpinan">Pimpinan</option>
                <option value="ao">Account Officer (AO)</option>
            </select>
        </div>
        <div class="kar-row" id="rowAo" hidden>
            <label for="kode_ao">Kode AO</label>
            <input class="fi" id="kode_ao" name="kode_ao" maxlength="15" autocomplete="off" autocapitalize="characters" spellcheck="false">
        </div>
        <div class="kar-row">
            <label for="password">Password <span class="wajib" id="pwWajib">*</span></label>
            <input class="fi" type="password" id="password" name="password" maxlength="72" autocomplete="new-password">
        </div>
        <div class="kar-row">
            <label for="id_karyawan">Terkait pegawai</label>
            <select class="fi" id="id_karyawan" name="id_karyawan"><option value="">- Tidak terkait pegawai -</option></select>
        </div>
        <div class="kar-row">
            <label for="aktif">Status</label>
            <label class="sw"><input type="checkbox" id="aktif" name="aktif" value="1" checked><span class="sw-k"></span><span id="swTxt">Aktif</span></label>
        </div>
    </div>

    <div class="hint" id="pwInfo" style="margin:4px 0 0">Password minimal 8 karakter.</div>

    <div class="aksi" style="margin-top:14px">
        <button type="submit" class="btn primary" id="simpan"><span class="t">Simpan</span><span class="spin" aria-hidden="true"></span></button>
        <button type="button" class="btn" id="batal" hidden>Batal ubah</button>
    </div>
</form>

<div class="card">
    <div class="kar-bar">
        <div><b>Tabel pengguna aplikasi</b> <span class="muted" id="jumlah"></span></div>
        <input class="fi kar-cari" id="cari" type="search" placeholder="Cari username, nama, role, pegawai" autocomplete="off">
    </div>
    <div class="scroll">
        <table id="tbl" class="tl" style="width:100%">
            <thead><tr><th>Username</th><th>Nama</th><th>Role</th><th>Terkait pegawai</th><th>Status</th><th>Aksi</th></tr></thead>
            <tbody><tr><td colspan="6" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        var ROLE = { admin: 'Administrator', pimpinan: 'Pimpinan', ao: 'Account Officer' },
            form = document.getElementById('fPg'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            tbody = document.querySelector('#tbl tbody'),
            data = { rows: [], pegawai: [] },
            editId = 0,
            sending = false;

        function $(id) { return document.getElementById(id); }

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function msg(ok, t) {
            msgBox.innerHTML = '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>';
            msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
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

        // ---------- bagian form yang bergantung pilihan ----------
        function aturAo() { $('rowAo').hidden = f.role.value !== 'ao'; }
        f.role.addEventListener('change', aturAo);
        f.aktif.addEventListener('change', function() { $('swTxt').textContent = f.aktif.checked ? 'Aktif' : 'Nonaktif'; });

        // pegawai yang sudah terkait akun lain dinonaktifkan
        function isiPegawai(pilih) {
            var html = '<option value="">- Tidak terkait pegawai -</option>';
            data.pegawai.forEach(function(p) {
                var terpakai = p.dipakai_oleh && p.dipakai_oleh !== editId;
                html += '<option value="' + p.id + '"' + (terpakai ? ' disabled' : '') + '>' + esc(p.nama) + (terpakai ? ' (sudah terkait akun)' : '') + '</option>';
            });
            f.id_karyawan.innerHTML = html;
            f.id_karyawan.value = pilih ? String(pilih) : '';
        }

        // ---------- tabel ----------
        function gambarTabel() {
            var q = $('cari').value.trim().toLowerCase();
            var rows = data.rows.filter(function(x) {
                return !q || (x.username + ' ' + x.nama + ' ' + (ROLE[x.role] || x.role) + ' ' + x.nama_karyawan).toLowerCase().indexOf(q) !== -1;
            });
            $('jumlah').textContent = rows.length + ' pengguna';
            tbody.innerHTML = rows.length ? rows.map(function(x) {
                return '<tr><td><b>' + esc(x.username) + '</b></td>' +
                    '<td class="wrap">' + esc(x.nama) + '</td>' +
                    '<td>' + esc(ROLE[x.role] || x.role) + (x.role === 'ao' && x.kode_ao ? ' <span class="muted">(' + esc(x.kode_ao) + ')</span>' : '') + '</td>' +
                    '<td class="wrap">' + (esc(x.nama_karyawan) || '<span class="muted">–</span>') + '</td>' +
                    '<td><span class="badge ' + (x.aktif ? 'on' : 'off') + '">' + (x.aktif ? 'Aktif' : 'Nonaktif') + '</span></td>' +
                    '<td class="kar-aksi"><button type="button" class="btn" data-id="' + x.id + '">Ubah</button></td></tr>';
            }).join('') : '<tr><td colspan="6" class="muted">' + (q ? 'Tidak ada yang cocok.' : 'Belum ada pengguna.') + '</td></tr>';
        }
        $('cari').addEventListener('input', gambarTabel);

        function muat(pertahankan) {
            return ambil('api/api_pengguna.php?jenis=daftar').then(function(r) {
                if (!r.ok) throw new Error((r.d && r.d.error) || 'gagal');
                data = { rows: r.d.rows || [], pegawai: r.d.pegawai || [] };
                isiPegawai(pertahankan ? f.id_karyawan.value : '');
                gambarTabel();
            }).catch(function(e) {
                $('jumlah').textContent = '';
                tbody.innerHTML = '<tr><td colspan="6" class="muted">Data belum bisa dimuat.</td></tr>';
                msg(false, (e && e.message && e.message !== 'gagal') ? e.message : 'Data pengguna belum bisa dimuat.');
            });
        }

        // ---------- mode tambah / ubah ----------
        function modeTambah() {
            editId = 0;
            form.reset();
            f.jenis.value = 'input';
            f.id_pengguna.value = '0';
            $('formJudul').textContent = 'Tambah pengguna';
            $('pwWajib').hidden = false;
            $('pwInfo').textContent = 'Password minimal 8 karakter.';
            btn.querySelector('.t').textContent = 'Simpan';
            $('batal').hidden = true;
            $('swTxt').textContent = 'Aktif';
            aturAo();
            isiPegawai('');
        }

        function modeUbah(x) {
            editId = x.id;
            form.reset();
            f.jenis.value = 'update';
            f.id_pengguna.value = String(x.id);
            f.username.value = x.username;
            f.nama.value = x.nama;
            f.role.value = x.role;
            f.kode_ao.value = x.kode_ao;
            f.aktif.checked = !!x.aktif;
            $('swTxt').textContent = x.aktif ? 'Aktif' : 'Nonaktif';
            isiPegawai(x.id_karyawan);
            aturAo();
            $('formJudul').textContent = 'Ubah pengguna';
            $('pwWajib').hidden = true;
            $('pwInfo').textContent = 'Kosongkan password jika tidak ingin menggantinya.';
            btn.querySelector('.t').textContent = 'Simpan perubahan';
            $('batal').hidden = false;
            msgBox.innerHTML = '';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            f.nama.focus();
        }
        $('batal').addEventListener('click', modeTambah);

        tbody.addEventListener('click', function(e) {
            var b = e.target.closest('button[data-id]');
            if (!b) return;
            var id = +b.getAttribute('data-id');
            var x = data.rows.filter(function(r) { return r.id === id; })[0];
            if (x) modeUbah(x);
        });

        // ---------- simpan ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return;

            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            var pw = f.password.value;
            if (!/^[A-Za-z0-9_.\-]{3,30}$/.test(f.username.value.trim())) return gagal('Username 3-30 karakter: huruf, angka, titik, garis bawah, atau strip.', f.username);
            if (!f.nama.value.trim()) return gagal('Nama wajib diisi.', f.nama);
            if (!f.role.value) return gagal('Pilih role pengguna.', f.role);
            if (!editId && !pw) return gagal('Password wajib diisi untuk pengguna baru.', f.password);
            if (pw && (pw.length < 8 || pw.length > 72)) return gagal('Password 8-72 karakter.', f.password);

            f.username.value = f.username.value.trim();
            var fd = new FormData(form);

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';

            ambil('api/api_pengguna.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Data berhasil disimpan.');
                    modeTambah();
                    return muat(false);
                }
                msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal menyimpan. Coba lagi.'));
            }).catch(function() {
                msg(false, 'Permintaan gagal. Periksa koneksi, lalu cek tabel sebelum mengirim ulang.');
            }).then(function() {
                sending = false;
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.querySelector('.t').textContent = editId ? 'Simpan perubahan' : 'Simpan';
            });
        });

        aturAo();
        muat(false);
    })();
</script>
<?php page_foot();
