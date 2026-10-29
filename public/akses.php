<?php
// Hak akses menu per pengguna. Tidak menyentuh database: semuanya lewat api/api_akses.php (proxy ke API).
// Siapa yang boleh membuka halaman ini ditentukan oleh hak akses menu 'web_akses' (dicek otomatis oleh page_head).
require __DIR__ . '/lib.php';
//require __DIR__ . '/akses_lib.php';

page_head('Hak akses', 'akses');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<link rel="stylesheet" href="assets/karyawan.css">
<link rel="stylesheet" href="assets/akses.css">

<div>
    <h1>Hak akses</h1>
</div>

<?php tabs_html('web_akses'); ?>

<div id="msg" aria-live="polite"></div>

<div class="card" style="margin-bottom:12px">
    <label for="pilihUser"><b>Pengguna</b> <span class="muted" id="jmlUser"></span></label>
    <select class="fi" id="pilihUser" style="margin-top:8px;width:100%;max-width:420px" disabled>
        <option value="">Memuat…</option>
    </select>
</div>

<div>
    <form id="fAkses" class="card" method="post" novalidate>
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="jenis" value="simpan">
        <input type="hidden" name="user_id" value="0">

        <b id="judul">Pilih pengguna pada dropdown di atas</b>
        <div class="hint" style="margin:4px 0 12px">Perubahan berlaku saat pengguna tersebut masuk ulang.</div>

        <div id="daftarMenu"><div class="ak-kosong">Belum ada pengguna yang dipilih.</div></div>

        <div class="aksi" style="margin-top:14px">
            <button type="submit" class="btn primary" id="simpan" disabled><span class="t">Simpan hak akses</span><span class="spin" aria-hidden="true"></span></button>
            <button type="button" class="btn" id="btnSemua" disabled>Pilih semua</button>
            <button type="button" class="btn" id="btnKosong" disabled>Kosongkan</button>
        </div>
    </form>
</div>

<script>
    (function() {
        var form = document.getElementById('fAkses'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            st = { users: [], user: null, menus: [], granted: {} },
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

        function tombol(aktif) {
            btn.disabled = !aktif || sending;
            $('btnSemua').disabled = !aktif;
            $('btnKosong').disabled = !aktif;
        }

        // ---------- daftar pengguna (dropdown) ----------
        function gambarUser() {
            var sel = $('pilihUser');
            $('jmlUser').textContent = st.users.length + ' pengguna';
            sel.innerHTML = '<option value="">— pilih pengguna —</option>' + st.users.map(function(x) {
                return '<option value="' + x.id + '">' + esc(x.username) + (x.nama ? ' · ' + esc(x.nama) : '') + (x.role ? ' (' + esc(x.role) + ')' : '') + '</option>';
            }).join('');
            sel.value = st.user ? String(st.user.id) : '';
            sel.disabled = st.users.length === 0;
        }

        $('pilihUser').addEventListener('change', function() {
            var id = +this.value;
            if (!id) { kosongkan(); return; }
            var u = st.users.filter(function(x) { return x.id === id; })[0];
            if (u) pilihUser(u);
        });

        function kosongkan() {
            st.user = null; st.menus = []; st.granted = {};
            f.user_id.value = '0';
            $('judul').textContent = 'Pilih pengguna pada dropdown di atas';
            $('daftarMenu').innerHTML = '<div class="ak-kosong">Belum ada pengguna yang dipilih.</div>';
            tombol(false);
            msgBox.innerHTML = '';
        }

        function muatUser() {
            ambil('api/api_akses.php?jenis=pengguna').then(function(r) {
                if (!r.ok) throw new Error((r.d && r.d.error) || 'gagal');
                st.users = r.d.rows || [];
                gambarUser();
            }).catch(function(e) {
                $('pilihUser').innerHTML = '<option value="">Daftar pengguna belum bisa dimuat</option>';
                msg(false, (e && e.message && e.message !== 'gagal') ? e.message : 'Daftar pengguna belum bisa dimuat.');
            });
        }

        // ---------- menu seorang pengguna ----------
        function pilihUser(u) {
            st.user = u;
            f.user_id.value = String(u.id);
            $('judul').textContent = 'Hak akses: ' + u.nama + ' (' + u.username + ')';
            $('daftarMenu').innerHTML = '<div class="ak-kosong">Memuat menu…</div>';
            tombol(false);
            msgBox.innerHTML = '';

            ambil('api/api_akses.php?jenis=menu&user_id=' + u.id).then(function(r) {
                if (!r.ok) throw new Error((r.d && r.d.error) || 'gagal');
                st.menus = r.d.menus || [];
                st.granted = {};
                (r.d.granted || []).forEach(function(id) { st.granted[id] = true; });
                gambarMenu();
                tombol(true);
            }).catch(function(e) {
                $('daftarMenu').innerHTML = '<div class="ak-kosong">Hak akses belum bisa dimuat.</div>';
                msg(false, (e && e.message && e.message !== 'gagal') ? e.message : 'Hak akses belum bisa dimuat.');
            });
        }

        // grup: menu utama (tanpa induk) + satu grup per induk
        function susunGrup() {
            var anak = {}, grup = [];
            st.menus.forEach(function(m) { if (m.parent_id > 0) (anak[m.parent_id] = anak[m.parent_id] || []).push(m); });
            var daun = st.menus.filter(function(m) { return m.parent_id === 0 && !anak[m.id]; });
            if (daun.length) grup.push({ judul: 'Menu utama', items: daun });
            st.menus.filter(function(m) { return m.parent_id === 0 && anak[m.id]; }).forEach(function(i) {
                grup.push({ judul: i.label, items: anak[i.id] });
            });
            return grup;
        }

        function gambarMenu() {
            var grup = susunGrup();
            $('daftarMenu').innerHTML = grup.length ? grup.map(function(g, n) {
                return '<div class="ak-grup"><label class="ak-induk"><input type="checkbox" data-induk="' + n + '"><b>' + esc(g.judul) + '</b></label><div class="ak-anak">' +
                    g.items.map(function(m) {
                        return '<label class="ak-item"><input type="checkbox" data-menu="' + m.id + '" data-grup="' + n + '"' + (st.granted[m.id] ? ' checked' : '') + '>' +
                            '<span>' + esc(m.label) + (m.url ? ' <span class="muted">' + esc(m.url) + '</span>' : '') + '</span></label>';
                    }).join('') + '</div></div>';
            }).join('') : '<div class="ak-kosong">Belum ada menu aktif. Isi tabel m_menu terlebih dahulu.</div>';
            grup.forEach(function(g, n) { stateInduk(n); });
        }

        function stateInduk(n) {
            var item = document.querySelectorAll('input[data-menu][data-grup="' + n + '"]'),
                induk = document.querySelector('input[data-induk="' + n + '"]'),
                dicentang = Array.prototype.filter.call(item, function(x) { return x.checked; }).length;
            if (!induk) return;
            induk.checked = item.length > 0 && dicentang === item.length;
            induk.indeterminate = dicentang > 0 && dicentang < item.length;
        }

        $('daftarMenu').addEventListener('change', function(e) {
            var el = e.target;
            if (el.hasAttribute('data-induk')) {
                var n = el.getAttribute('data-induk');
                document.querySelectorAll('input[data-menu][data-grup="' + n + '"]').forEach(function(x) { x.checked = el.checked; });
                return;
            }
            if (el.hasAttribute('data-menu')) stateInduk(el.getAttribute('data-grup'));
        });

        function aturSemua(nilai) {
            document.querySelectorAll('#daftarMenu input[data-menu]').forEach(function(x) { x.checked = nilai; });
            susunGrup().forEach(function(g, n) { stateInduk(n); });
        }
        $('btnSemua').addEventListener('click', function() { aturSemua(true); });
        $('btnKosong').addEventListener('click', function() { aturSemua(false); });

        // ---------- simpan ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending || !st.user) return;

            var fd = new FormData();
            fd.set('_csrf', f._csrf.value);
            fd.set('jenis', 'simpan');
            fd.set('user_id', String(st.user.id));
            document.querySelectorAll('#daftarMenu input[data-menu]:checked').forEach(function(x) { fd.append('menu_ids[]', x.getAttribute('data-menu')); });

            sending = true;
            tombol(true);
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';

            ambil('api/api_akses.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Hak akses disimpan.');
                } else {
                    msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal menyimpan. Coba lagi.'));
                }
            }).catch(function() {
                msg(false, 'Permintaan gagal. Periksa koneksi, lalu coba lagi.');
            }).then(function() {
                sending = false;
                btn.classList.remove('loading');
                btn.querySelector('.t').textContent = 'Simpan hak akses';
                tombol(true);
            });
        });

        muatUser();
    })();
</script>
<?php page_foot();