<?php
// Form absensi karyawan. Tidak menyentuh database: simpan lewat api_absensi.php (proxy ke API).
require __DIR__ . '/lib.php';
date_default_timezone_set('Asia/Jakarta');

$u         = auth_user() ?? [];
$nama      = (string)($u['nama'] ?? ($u['username'] ?? ''));
$jenisAwal = (int)date('G') < 12 ? 'masuk' : 'pulang';   // pagi: masuk, siang ke atas: pulang (bisa diganti)

page_head('Absensi', 'absensi');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<link rel="stylesheet" href="assets/absensi.css">

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-cal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
    <symbol id="i-cam" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
    <symbol id="i-reset" viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
</svg>

<div>
    <h1>Absensi</h1>
</div>

<div class="card">
    <b><?= h($nama ?: 'Karyawan') ?></b>
    <div class="absen-jam" id="jam">--:--:--</div>
    <p class="absen-tgl" id="tglNow"></p>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fAbsensi" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="input">

    <!-- 1. JENIS ABSEN -->
    <section class="fsec" id="s1">
        <div class="fsec-h">
            <span class="ico ico-biru"><svg class="ic"><use href="#i-cal"/></svg></span>
            <div><h2>Jenis Absen</h2><p>Pilih absen masuk atau pulang</p></div>
        </div>
        <div class="fsec-b">
            <div class="jenis" role="radiogroup" aria-label="Jenis absen">
                <input type="radio" name="type_absen" id="jMasuk" value="masuk"<?= $jenisAwal === 'masuk' ? ' checked' : '' ?>>
                <label for="jMasuk" class="m">Absen masuk</label>
                <input type="radio" name="type_absen" id="jPulang" value="pulang"<?= $jenisAwal === 'pulang' ? ' checked' : '' ?>>
                <label for="jPulang" class="p">Absen pulang</label>
            </div>
        </div>
    </section>

    <!-- 2. FOTO & LOKASI -->
    <section class="fsec" id="s2">
        <div class="fsec-h">
            <span class="ico ico-oranye"><svg class="ic"><use href="#i-cam"/></svg></span>
            <div><h2>Foto &amp; Lokasi</h2><p>Selfie dan lokasi saat absen</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="lokasi">Lokasi GPS <span class="wajib">*</span></label>
            <div class="lok">
                <button type="button" class="btn" id="btnGps"><svg class="ic"><use href="#i-pin"/></svg><span>Ambil Lokasi</span></button>
                <textarea class="fi" id="lokasi" name="lokasi" maxlength="200" rows="2" readonly placeholder="Koordinat akan muncul di sini"></textarea>
            </div>
            <div class="hint" id="gpsInfo" style="margin-bottom:18px">Perlu izin lokasi di peramban.</div>

            <label class="lbl-f" for="foto">Foto Selfie <span class="wajib">*</span></label>
            <label class="foto-zone" id="fz" for="foto">
                <svg class="ic besar"><use href="#i-cam"/></svg>
                <span class="fz-label" id="fzLabel">Ambil Foto Selfie</span>
                <span class="fz-sub" id="fzSub">Ketuk untuk membuka kamera depan</span>
            </label>
            <input type="file" id="foto" name="foto" accept="image/*" capture="user" hidden>
            <div class="foto-prev" id="fotoPrev" hidden>
                <img id="fotoImg" alt="Pratinjau foto absen">
                <button type="button" class="btn" id="fotoHapus"><svg class="ic"><use href="#i-x"/></svg>Hapus foto</button>
            </div>
        </div>
    </section>

    <div class="fsec"><div class="fsec-b">
        <div class="aksi">
            <button type="submit" class="btn primary" id="simpan"><svg class="ic"><use href="#i-send"/></svg><span class="t">Kirim Absen</span><span class="spin" aria-hidden="true"></span></button>
            <button type="reset" class="btn" id="kosong"><svg class="ic"><use href="#i-reset"/></svg>Reset</button>
        </div>
    </div></div>
</form>

<script>
    (function() {
        var form = document.getElementById('fAbsensi'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            gps = document.getElementById('btnGps'),
            gpsInfo = document.getElementById('gpsInfo'),
            sending = false,
            fotoBlob = null;

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

        // ---------- jam ----------
        function jam() {
            var d = new Date();
            $('jam').textContent = d.toLocaleTimeString('id-ID', { hour12: false }).replace(/\./g, ':');
            $('tglNow').textContent = d.toLocaleDateString('id-ID', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
        }
        jam();
        setInterval(jam, 1000);

        // ---------- GPS (diambil otomatis saat halaman dibuka; tombol untuk mengulang) ----------
        function ambilLokasi() {
            if (!navigator.geolocation) { msg(false, 'Peramban ini tidak mendukung GPS.'); return; }
            gps.disabled = true;
            gps.classList.remove('dapat');
            gps.querySelector('span').textContent = 'Mencari lokasi…';
            navigator.geolocation.getCurrentPosition(function(p) {
                var acc = Math.round(p.coords.accuracy);
                f.lokasi.value = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6);
                gpsInfo.textContent = 'Akurasi sekitar ' + acc + ' meter.' + (acc > 100 ? ' Kurang akurat, coba di area terbuka.' : '');
                gps.classList.add('dapat');
                gps.disabled = false;
                gps.querySelector('span').textContent = 'Lokasi didapat';
            }, function() {
                msg(false, 'Lokasi tidak bisa diambil. Izinkan akses lokasi di peramban lalu coba lagi.');
                gps.disabled = false;
                gps.querySelector('span').textContent = 'Ambil Lokasi';
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 });
        }
        gps.addEventListener('click', ambilLokasi);

        // ---------- foto: diperkecil di peramban sebelum diunggah ----------
        function kecilkan(file) {
            return new Promise(function(selesai) {
                if (!window.createImageBitmap || !/^image\/(jpeg|png|webp)$/.test(file.type)) { selesai(file); return; }
                createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp) {
                    var s = Math.min(1, 1024 / Math.max(bmp.width, bmp.height)), c = document.createElement('canvas'), g;
                    c.width = Math.max(1, Math.round(bmp.width * s));
                    c.height = Math.max(1, Math.round(bmp.height * s));
                    g = c.getContext('2d');
                    g.fillStyle = '#fff';
                    g.fillRect(0, 0, c.width, c.height);
                    g.drawImage(bmp, 0, 0, c.width, c.height);
                    c.toBlob(function(b) { selesai(b || file); }, 'image/jpeg', 0.8);
                }).catch(function() { selesai(file); });
            });
        }

        function setFoto(blob) {
            var prev = $('fotoImg').getAttribute('src');
            if (prev && prev.indexOf('blob:') === 0) URL.revokeObjectURL(prev);
            fotoBlob = blob;
            $('fotoPrev').hidden = !blob;
            $('fz').classList.toggle('ada', !!blob);
            $('fzLabel').textContent = blob ? 'Foto siap dikirim' : 'Ambil Foto Selfie';
            $('fzSub').textContent = blob ? 'Ketuk untuk mengganti foto' : 'Ketuk untuk membuka kamera depan';
            if (blob) $('fotoImg').src = URL.createObjectURL(blob); else $('fotoImg').removeAttribute('src');
        }

        $('foto').addEventListener('change', function() {
            var file = this.files && this.files[0];
            if (!file) return;
            $('fz').classList.add('sibuk');
            kecilkan(file).then(function(b) {
                $('fz').classList.remove('sibuk');
                setFoto(b);
            });
            this.value = '';
        });
        $('fotoHapus').addEventListener('click', function() { setFoto(null); });

        // ---------- reset ----------
        form.addEventListener('reset', function() {
            setTimeout(function() {
                setFoto(null);
                gpsInfo.textContent = 'Perlu izin lokasi di peramban.';
                ambilLokasi();
            }, 0);
        });
        $('kosong').addEventListener('click', function() { msgBox.innerHTML = ''; });   // pesan sukses tetap tampil setelah kirim

        // ---------- kirim ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return; // klik ganda = absen tercatat dua kali

            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            if (!f.type_absen.value) return gagal('Pilih absen masuk atau pulang.');
            if (!f.lokasi.value.trim()) return gagal('Lokasi wajib diambil sebelum absen.', gps);
            if (!fotoBlob) return gagal('Foto selfie wajib diisi.', $('fz'));

            var fd = new FormData(form);
            fd.set('foto', fotoBlob, 'foto.jpg');

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Mengirim…';

            ambil('api/api_absensi.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Absen berhasil disimpan.');
                    form.reset();
                } else {
                    msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal mengirim absen. Coba lagi.'));
                }
            }).catch(function() {
                msg(false, 'Permintaan gagal. Periksa koneksi lalu coba lagi.');
            }).then(function() {
                sending = false;
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.querySelector('.t').textContent = 'Kirim Absen';
            });
        });

        ambilLokasi();
    })();
</script>
<?php page_foot();
