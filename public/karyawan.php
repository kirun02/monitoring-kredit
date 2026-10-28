<?php
// Data karyawan. Akses diatur lewat menu Hak Akses ('web_karyawan'), dicek otomatis oleh page_head. Tidak menyentuh database: semuanya lewat api_karyawan.php (proxy ke API).
require __DIR__ . '/lib.php';


page_head('Data karyawan', 'karyawan');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<link rel="stylesheet" href="assets/karyawan.css">

<div>
    <h1>Data karyawan</h1>
</div>

<?php tabs_html('web_karyawan'); ?>

<div id="msg" aria-live="polite"></div>

<form id="fKar" class="card" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="input">
    <input type="hidden" name="id_karyawan" value="0">

    <b id="formJudul">Tambah karyawan</b>

    <div class="kar-grid">
        <div>
            <div class="kar-row">
                <label for="nama">Nama pegawai <span class="wajib">*</span></label>
                <input class="fi" id="nama" name="nama" maxlength="100" autocomplete="off">
            </div>
            <div class="kar-row">
                <label for="jabatan">Jabatan / posisi</label>
                <input class="fi" id="jabatan" name="jabatan" maxlength="30" list="dlJabatan" autocomplete="off">
                <datalist id="dlJabatan"></datalist>
            </div>
            <div class="kar-row">
                <label for="notlp">Nomor telepon</label>
                <input class="fi" id="notlp" name="notlp" maxlength="20" inputmode="tel" autocomplete="off">
            </div>
            <div class="kar-row">
                <label for="id_user">Akun aplikasi <span class="wajib">*</span></label>
                <select class="fi" id="id_user" name="id_user"><option value="">- Pilih -</option></select>
            </div>
        </div>

        <div>
            <span class="lbl-f">Foto</span>
            <div class="kar-foto-box" id="fotoBox">
                <span id="fotoKosong">Belum ada foto</span>
                <img id="fotoImg" alt="Foto karyawan" hidden>
            </div>
            <input class="fi" type="file" id="foto" accept="image/png,image/jpeg,image/webp">
            <div class="hint">PNG, JPG, atau WEBP. Maksimal 1 MB.</div>
            <button type="button" class="btn" id="fotoBatal" hidden>Batalkan foto baru</button>
        </div>
    </div>

    <div class="aksi" style="margin-top:14px">
        <button type="submit" class="btn primary" id="simpan"><span class="t">Simpan</span><span class="spin" aria-hidden="true"></span></button>
        <button type="button" class="btn" id="batal" hidden>Batal ubah</button>
    </div>
</form>

<div class="card">
    <div class="kar-bar">
        <div><b>Tabel data karyawan</b> <span class="muted" id="jumlah"></span></div>
        <input class="fi kar-cari" id="cari" type="search" placeholder="Cari nama, jabatan, akun, telepon" autocomplete="off">
    </div>
    <div class="scroll">
        <table id="tbl" class="tl" style="width:100%">
            <thead><tr><th>Foto</th><th>Nama karyawan</th><th>Jabatan / posisi</th><th>Akun aplikasi</th><th>Telepon</th><th>Aksi</th></tr></thead>
            <tbody><tr><td colspan="6" class="muted">Memuat data…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fKar'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            tbody = document.querySelector('#tbl tbody'),
            data = { rows: [], akun: [] },
            fotoBlob = null,
            fotoAsli = '',   // URL foto karyawan yang sedang diubah (untuk pratinjau)
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

        // ---------- pilihan akun: akun yang sudah dipakai karyawan lain dinonaktifkan ----------
        function isiAkun(pilih) {
            var sel = f.id_user, html = '<option value="">- Pilih -</option>';
            data.akun.forEach(function(a) {
                var terpakai = a.dipakai_oleh && a.dipakai_oleh !== editId;
                html += '<option value="' + a.id + '"' + (terpakai ? ' disabled' : '') + '>' + esc(a.username) + (terpakai ? ' (sudah dipakai)' : '') + '</option>';
            });
            sel.innerHTML = html;
            sel.value = pilih ? String(pilih) : '';
        }

        // ---------- tabel ----------
        function gambarTabel() {
            var q = $('cari').value.trim().toLowerCase();
            var rows = data.rows.filter(function(x) {
                return !q || (x.nama + ' ' + x.jabatan + ' ' + x.username + ' ' + x.notlp).toLowerCase().indexOf(q) !== -1;
            });
            $('jumlah').textContent = rows.length + ' karyawan';
            tbody.innerHTML = rows.length ? rows.map(function(x) {
                return '<tr><td>' + (x.foto_url ? '<img class="kar-thumb" src="' + esc(x.foto_url) + '" alt="" loading="lazy">' : '<span class="kar-thumb"></span>') + '</td>' +
                    '<td class="wrap"><b>' + esc(x.nama) + '</b></td>' +
                    '<td class="wrap">' + (esc(x.jabatan) || '<span class="muted">–</span>') + '</td>' +
                    '<td>' + (esc(x.username) || '<span class="muted">–</span>') + '</td>' +
                    '<td>' + (esc(x.notlp) || '<span class="muted">–</span>') + '</td>' +
                    '<td class="kar-aksi"><button type="button" class="btn" data-aksi="ubah" data-id="' + x.id + '">Ubah</button> ' +
                    '<button type="button" class="btn" data-aksi="hapus" data-id="' + x.id + '">Hapus</button></td></tr>';
            }).join('') : '<tr><td colspan="6" class="muted">' + (q ? 'Tidak ada yang cocok.' : 'Belum ada data karyawan.') + '</td></tr>';
        }
        $('cari').addEventListener('input', gambarTabel);

        function muat(lagi) {
            return ambil('api/api_karyawan.php?jenis=daftar').then(function(r) {
                if (!r.ok) throw new Error((r.d && r.d.error) || 'gagal');
                data = { rows: r.d.rows || [], akun: r.d.akun || [] };
                var jab = {};
                data.rows.forEach(function(x) { if (x.jabatan) jab[x.jabatan] = 1; });
                $('dlJabatan').innerHTML = Object.keys(jab).sort().map(function(j) { return '<option value="' + esc(j) + '">'; }).join('');
                isiAkun(lagi ? f.id_user.value : '');
                gambarTabel();
            }).catch(function(e) {
                $('jumlah').textContent = '';
                tbody.innerHTML = '<tr><td colspan="6" class="muted">Data belum bisa dimuat.</td></tr>';
                msg(false, (e && e.message && e.message !== 'gagal') ? e.message : 'Data karyawan belum bisa dimuat.');
            });
        }

        // ---------- foto: diperkecil di peramban (maks. 1 MB) ----------
        function kecilkan(file) {
            return new Promise(function(selesai) {
                if (!window.createImageBitmap || !/^image\/(jpeg|png|webp)$/.test(file.type)) { selesai(file); return; }
                createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp) {
                    var s = Math.min(1, 800 / Math.max(bmp.width, bmp.height)), c = document.createElement('canvas'), g;
                    c.width = Math.max(1, Math.round(bmp.width * s));
                    c.height = Math.max(1, Math.round(bmp.height * s));
                    g = c.getContext('2d');
                    g.fillStyle = '#fff';
                    g.fillRect(0, 0, c.width, c.height);
                    g.drawImage(bmp, 0, 0, c.width, c.height);
                    c.toBlob(function(b) { selesai(b || file); }, 'image/jpeg', 0.82);
                }).catch(function() { selesai(file); });
            });
        }

        function tampilFoto(src) {
            $('fotoImg').hidden = !src;
            $('fotoKosong').hidden = !!src;
            if (src) $('fotoImg').src = src; else $('fotoImg').removeAttribute('src');
        }

        function setFoto(blob) {
            var prev = $('fotoImg').getAttribute('src');
            if (prev && prev.indexOf('blob:') === 0) URL.revokeObjectURL(prev);
            fotoBlob = blob;
            $('fotoBatal').hidden = !blob;
            tampilFoto(blob ? URL.createObjectURL(blob) : fotoAsli);
        }

        $('foto').addEventListener('change', function() {
            var file = this.files && this.files[0];
            if (!file) return;
            kecilkan(file).then(function(b) {
                if (b.size > 1048576) { msg(false, 'Ukuran foto maksimal 1 MB.'); return; }
                setFoto(b);
            });
            this.value = '';
        });
        $('fotoBatal').addEventListener('click', function() { setFoto(null); });

        // ---------- mode tambah / ubah ----------
        function modeTambah() {
            editId = 0; fotoAsli = '';
            form.reset();
            f.jenis.value = 'input';
            f.id_karyawan.value = '0';
            $('formJudul').textContent = 'Tambah karyawan';
            btn.querySelector('.t').textContent = 'Simpan';
            $('batal').hidden = true;
            setFoto(null);
            isiAkun('');
        }

        function modeUbah(x) {
            editId = x.id; fotoAsli = x.foto_url || '';
            form.reset();
            f.jenis.value = 'update';
            f.id_karyawan.value = String(x.id);
            f.nama.value = x.nama;
            f.jabatan.value = x.jabatan;
            f.notlp.value = x.notlp;
            isiAkun(x.id_user);
            $('formJudul').textContent = 'Ubah karyawan';
            btn.querySelector('.t').textContent = 'Simpan perubahan';
            $('batal').hidden = false;
            setFoto(null);
            msgBox.innerHTML = '';
            form.scrollIntoView({ behavior: 'smooth', block: 'start' });
            f.nama.focus();
        }
        $('batal').addEventListener('click', modeTambah);

        function cariRow(id) { return data.rows.filter(function(x) { return x.id === id; })[0]; }

        tbody.addEventListener('click', function(e) {
            var b = e.target.closest('button[data-aksi]');
            if (!b) return;
            var x = cariRow(+b.getAttribute('data-id'));
            if (!x) return;
            if (b.getAttribute('data-aksi') === 'ubah') { modeUbah(x); return; }

            if (!confirm('Hapus karyawan "' + x.nama + '"?\nRiwayat absensi yang sudah tercatat tidak ikut terhapus.')) return;
            var fd = new FormData();
            fd.set('_csrf', f._csrf.value);
            fd.set('jenis', 'hapus');
            fd.set('id_karyawan', String(x.id));
            b.disabled = true;
            ambil('api/api_karyawan.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Karyawan dihapus.');
                    if (editId === x.id) modeTambah();
                    muat(true);
                } else {
                    msg(false, r.d.error || 'Gagal menghapus. Coba lagi.');
                    b.disabled = false;
                }
            }).catch(function() { msg(false, 'Permintaan gagal. Periksa koneksi, lalu coba lagi.'); b.disabled = false; });
        });

        // ---------- simpan ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return;

            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            if (!f.nama.value.trim()) return gagal('Nama pegawai wajib diisi.', f.nama);
            if (!f.id_user.value) return gagal('Pilih akun aplikasi untuk karyawan ini.', f.id_user);

            var fd = new FormData(form);
            if (fotoBlob) fd.set('foto', fotoBlob, 'foto.jpg');

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';

            ambil('api/api_karyawan.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Data berhasil disimpan.');
                    modeTambah();
                    return muat(true);
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

        muat(false);
    })();
</script>
<?php page_foot();