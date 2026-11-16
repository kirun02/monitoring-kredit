<?php
// Input promosi / survey: Kegiatan, Produk & Prospek, Lokasi & Bukti, plus daftar kegiatan milik akun yang login. Menu: web_promosi.
// Tidak menyentuh database: data lewat api/api_promosi.php (proxy ke API Promosi.php). id_user selalu dari sesi login.
require __DIR__ . '/lib.php';
page_head('Input promosi', 'promosi');
?>
<link rel="stylesheet" href="assets/kunjungan.css">
<style>
    .pills { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
    .pills input { position: absolute; opacity: 0; pointer-events: none; }
    .pills label { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; min-height: 40px; border: 1px solid #B8C6C2; border-radius: 999px;
                   background: #fff; cursor: pointer; font-size: 13px; color: var(--ink); user-select: none; }
    .pills label .ic { width: 16px; height: 16px; }
    .pills input:checked + label { background: var(--brand); border-color: var(--brand); color: #fff; font-weight: 600; }
    .pills input:focus-visible + label { outline: 3px solid #7FD1C4; outline-offset: 2px; }
    .dua { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
    .stepper { display: inline-flex; align-items: center; border: 1px solid #B8C6C2; border-radius: 10px; overflow: hidden; background: #fff; }
    .stepper button { width: 44px; height: 44px; border: 0; background: #F2F5F4; font-size: 20px; line-height: 1; cursor: pointer; color: var(--ink); }
    .stepper button:hover { background: #E4ECEA; }
    .stepper input { width: 92px; height: 44px; border: 0; text-align: center; font: inherit; font-size: 16px; font-weight: 600; color: var(--ink); -moz-appearance: textfield; }
    .stepper input::-webkit-outer-spin-button, .stepper input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .btn.lok { width: 100%; justify-content: center; gap: 8px; margin-bottom: 8px; }
    .btn.lok.dapat { border-color: var(--good); color: var(--good); }
    #tbList td.num, #tbList th.num { text-align: right; }
    @media (max-width: 600px) { .dua { grid-template-columns: 1fr; } }
</style>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-mega" viewBox="0 0 24 24"><path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15 9a4 4 0 0 1 0 6M18 6a8 8 0 0 1 0 12"/></symbol>
    <symbol id="i-clip" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2M9 12h6M9 16h6"/></symbol>
    <symbol id="i-box" viewBox="0 0 24 24"><path d="M21 8l-9-5-9 5v8l9 5 9-5z"/><path d="M3 8l9 5 9-5M12 13v8"/></symbol>
    <symbol id="i-card" viewBox="0 0 24 24"><rect x="2" y="5" width="20" height="14" rx="2"/><path d="M2 10h20"/></symbol>
    <symbol id="i-bank" viewBox="0 0 24 24"><path d="M3 21h18M5 21V10M9 21V10M15 21V10M19 21V10M2 10l10-6 10 6z"/></symbol>
    <symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol>
    <symbol id="i-cam" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
    <symbol id="i-reset" viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
</svg>

<div>
    <h1>Input Promosi</h1>
    <div class="sub">Catat kegiatan promosi atau survey: waktu, produk, jumlah prospek, lokasi, dan foto. Tanda * wajib diisi.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fPro" novalidate autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <!-- 1. KEGIATAN -->
    <section class="fsec">
        <div class="fsec-h">
            <span class="ico ico-biru"><svg class="ic"><use href="#i-mega"/></svg></span>
            <div><h2>Kegiatan</h2><p>Jenis dan waktu kegiatan</p></div>
        </div>
        <div class="fsec-b">
            <div class="pills" role="radiogroup" aria-label="Jenis kegiatan">
                <input type="radio" name="jenis_kegiatan" id="jPromosi" value="promosi" checked>
                <label for="jPromosi"><svg class="ic"><use href="#i-mega"/></svg>Promosi</label>
                <input type="radio" name="jenis_kegiatan" id="jSurvey" value="survey">
                <label for="jSurvey"><svg class="ic"><use href="#i-clip"/></svg>Survey</label>
            </div>
            <div class="dua">
                <div><label class="lbl-f" for="tanggal">Tanggal <span class="wajib">*</span></label><input class="fi" type="date" id="tanggal" name="tanggal"></div>
                <div><label class="lbl-f" for="jam">Jam <span class="wajib">*</span></label><input class="fi" type="time" id="jam" name="jam"></div>
            </div>
        </div>
    </section>

    <!-- 2. PRODUK & PROSPEK -->
    <section class="fsec">
        <div class="fsec-h">
            <span class="ico ico-hijau"><svg class="ic"><use href="#i-box"/></svg></span>
            <div><h2>Produk &amp; Prospek</h2><p>Yang ditawarkan dan jumlah calon nasabah</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f">Produk yang Dipromosikan <span class="wajib">*</span></label>
            <div class="pills" id="produk" role="group" aria-label="Produk yang dipromosikan">
                <input type="checkbox" id="pKredit" value="Kredit"><label for="pKredit"><svg class="ic"><use href="#i-card"/></svg>Kredit</label>
                <input type="checkbox" id="pTabungan" value="Tabungan"><label for="pTabungan"><svg class="ic"><use href="#i-bank"/></svg>Tabungan</label>
                <input type="checkbox" id="pDeposito" value="Deposito"><label for="pDeposito"><svg class="ic"><use href="#i-chart"/></svg>Deposito</label>
            </div>

            <label class="lbl-f" for="prospek">Jumlah Prospek / Calon Nasabah <span class="wajib">*</span></label>
            <div class="stepper">
                <button type="button" id="kurang" aria-label="Kurangi jumlah prospek">−</button>
                <input type="number" id="prospek" name="prospek" min="0" max="999999999" step="1" inputmode="numeric" value="0">
                <button type="button" id="tambah" aria-label="Tambah jumlah prospek">+</button>
            </div>
            <div class="hint" style="margin-bottom:18px">Jumlah orang yang ditemui / dijangkau.</div>

            <label class="lbl-f" for="keterangan">Keterangan / Catatan Tambahan</label>
            <textarea class="fi" id="keterangan" name="keterangan" maxlength="5000" rows="3" placeholder="Catatan kegiatan, hambatan, atau informasi tambahan..."></textarea>
        </div>
    </section>

    <!-- 3. LOKASI & BUKTI -->
    <section class="fsec">
        <div class="fsec-h">
            <span class="ico ico-oranye"><svg class="ic"><use href="#i-pin"/></svg></span>
            <div><h2>Lokasi &amp; Bukti</h2><p>Titik lokasi dan foto kegiatan</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="btnGps">Lokasi Kegiatan <span class="wajib">*</span></label>
            <button type="button" class="btn lok" id="btnGps"><svg class="ic"><use href="#i-pin"/></svg><span>Ambil Lokasi GPS</span></button>
            <input class="fi" type="text" id="gps" name="lokasi_gps" readonly maxlength="100" placeholder="Koordinat akan muncul di sini">
            <div class="hint" id="gpsInfo" style="margin-bottom:12px">Wajib. Ambil di lokasi kegiatan dan tunggu akurasi muncul. Perlu izin lokasi di peramban.</div>
            <input class="fi" type="text" id="alamat" name="alamat_kegiatan" maxlength="250" placeholder="Nama tempat / alamat kegiatan (misal: Pasar Gadang, Malang)" style="margin-bottom:18px">

            <label class="lbl-f" for="foto">Foto Kegiatan <span class="wajib">*</span></label>
            <label class="foto-zone" id="fz" for="foto">
                <svg class="ic besar"><use href="#i-cam"/></svg>
                <span class="fz-label" id="fzLabel">Ambil / Pilih Foto</span>
                <span class="fz-sub" id="fzSub">Ketuk untuk membuka kamera atau galeri</span>
            </label>
            <input type="file" id="foto" name="foto" accept="image/*" hidden>
            <div class="foto-prev" id="fotoPrev" hidden>
                <img id="fotoImg" alt="Pratinjau foto kegiatan">
                <button type="button" class="btn" id="fotoHapus"><svg class="ic"><use href="#i-x"/></svg>Hapus foto</button>
            </div>
        </div>
    </section>

    <div class="fsec"><div class="fsec-b">
        <div class="aksi">
            <button type="submit" class="btn primary" id="simpan"><svg class="ic"><use href="#i-send"/></svg><span class="t">Simpan</span><span class="spin" aria-hidden="true"></span></button>
            <button type="button" class="btn" id="kosong" title="Kosongkan isian" aria-label="Kosongkan isian"><svg class="ic"><use href="#i-reset"/></svg></button>
        </div>
    </div></div>
</form>

<div class="card">
    <form id="fList" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:12px">
        <b style="flex:1 1 100%">Kegiatan saya</b>
        <label class="f">Dari tanggal<input type="date" name="tgl1"></label>
        <label class="f">Sampai tanggal<input type="date" name="tgl2"></label>
        <label class="f">Jenis
            <select name="jenis"><option value="">Semua</option><option value="promosi">Promosi</option><option value="survey">Survey</option></select>
        </label>
        <button type="submit" class="btn" id="btnTampil">Tampilkan</button>
    </form>
    <div class="row" id="kpi" style="margin-bottom:12px"></div>
    <div class="scroll">
        <table id="tbl" class="tl" style="width:100%">
            <thead><tr><th>Waktu kegiatan</th><th>Jenis</th><th>Produk</th><th class="num">Prospek</th><th>Lokasi / alamat</th><th>Keterangan</th><th>Foto</th></tr></thead>
            <tbody id="tbList"><tr><td colspan="7" class="muted">Memuat…</td></tr></tbody>
        </table>
    </div>
</div>

<script>
    (function() {
        var form = document.getElementById('fPro'), fl = document.getElementById('fList'), msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'), gps = document.getElementById('btnGps'), tb = document.getElementById('tbList'),
            nf = new Intl.NumberFormat('id-ID'), sending = false, fotoBlob = null, fix = null, watchId = null, timerGps = null, seq = 0,
            GPS_HINT = 'Wajib. Ambil di lokasi kegiatan dan tunggu akurasi muncul. Perlu izin lokasi di peramban.';

        function $(id) { return document.getElementById(id); }
        function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }
        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
            if (t) msgBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function two(n) { return ('0' + n).slice(-2); }
        function ymd(d) { return d.getFullYear() + '-' + two(d.getMonth() + 1) + '-' + two(d.getDate()); }
        function jfetch(url, opt) {
            return fetch(url, Object.assign({ credentials: 'same-origin', headers: { 'Accept': 'application/json' } }, opt || {})).then(function(r) {
                if (r.status === 401) { location.href = 'login.php'; throw new Error('401'); }
                return r.json().catch(function() { return {}; }).then(function(d) { if (!r.ok || d.error) throw new Error(d.error || 'Gagal (kode ' + r.status + ').'); return d; });
            });
        }
        function awalIsian() { var n = new Date(); $('tanggal').value = ymd(n); $('tanggal').max = ymd(n); $('jam').value = two(n.getHours()) + ':' + two(n.getMinutes()); }
        function waktu(s) {                       // 'DD-MM-YYYY HH:MM' (dari API) atau ISO -> DD/MM/YYYY HH:MM
            var m = /^(\d{2})-(\d{2})-(\d{4})[ T](\d{2}):(\d{2})/.exec(s || '');
            if (m) return m[1] + '/' + m[2] + '/' + m[3] + ' ' + m[4] + ':' + m[5];
            m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : (s || '');
        }
        function kpi(l, v) { return '<div class="card kpi"><div class="muted">' + l + '</div><div class="v">' + v + '</div></div>'; }

        // ---------- stepper jumlah prospek ----------
        function ubahProspek(d) {
            var v = parseInt($('prospek').value, 10); if (isNaN(v)) v = 0;
            $('prospek').value = Math.max(0, Math.min(999999999, v + d));
        }
        $('kurang').addEventListener('click', function() { ubahProspek(-1); });
        $('tambah').addEventListener('click', function() { ubahProspek(1); });

        // ---------- GPS ----------
        // Memantau posisi sampai ~20 detik dan mengambil hasil yang PALING AKURAT (lokasi segar, tanpa posisi lama).
        // Lokasi yang kurang akurat tetap boleh disimpan; yang tidak boleh adalah menyimpan tanpa lokasi sama sekali.
        function meterTeks(m) { return m >= 1000 ? (m / 1000).toFixed(1).replace('.', ',') + ' km' : Math.round(m) + ' m'; }
        function mutuGps(m) { return m <= 100 ? ['good', 'baik'] : (m <= 1000 ? ['muted', 'kurang akurat'] : ['bad', 'kasar']); }
        function infoGps(teks, kelas) { $('gpsInfo').textContent = teks; $('gpsInfo').className = 'hint' + (kelas ? ' ' + kelas : ''); }
        function hentikanGps() {
            if (watchId !== null) { navigator.geolocation.clearWatch(watchId); watchId = null; }
            clearTimeout(timerGps);
        }
        function resetGps() {
            hentikanGps();
            fix = null;
            $('gps').value = '';
            gps.disabled = false;
            gps.classList.remove('dapat');
            gps.querySelector('span').textContent = 'Ambil Lokasi GPS';
            infoGps(GPS_HINT, '');
        }
        function selesaiGps(alasan) {
            hentikanGps();
            gps.disabled = false;
            if (!fix) {
                gps.querySelector('span').textContent = 'Ambil Lokasi GPS';
                infoGps(alasan === 'ditolak'
                    ? 'Izin lokasi ditolak. Izinkan akses lokasi untuk situs ini di pengaturan peramban, lalu coba lagi.'
                    : 'Lokasi belum ditemukan. Nyalakan GPS/Lokasi di HP, pindah ke tempat terbuka, lalu coba lagi.', 'bad');
                return;
            }
            var a = fix.coords.accuracy, m = mutuGps(a);
            gps.classList.add('dapat');
            gps.querySelector('span').textContent = 'Lokasi didapat · ambil ulang';
            infoGps('Akurasi sekitar ' + meterTeks(a) + ' (' + m[1] + ').' +
                (a > 1000 ? ' Lokasi ini kemungkinan dari jaringan dan bisa meleset sangat jauh. Coba di tempat terbuka, pastikan GPS menyala, lalu ambil ulang.'
                          : (a > 100 ? ' Untuk hasil lebih baik, coba di tempat terbuka.' : '')), m[0]);
        }
        gps.addEventListener('click', function() {
            if (!navigator.geolocation) { msg(false, 'Peramban ini tidak mendukung GPS.'); return; }
            resetGps();
            gps.disabled = true;
            gps.querySelector('span').textContent = 'Mencari lokasi…';
            infoGps('Mencari lokasi… tunggu beberapa detik.', '');
            watchId = navigator.geolocation.watchPosition(function(p) {
                if (!fix || p.coords.accuracy < fix.coords.accuracy) {
                    fix = p;
                    $('gps').value = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6);
                }
                infoGps('Mencari lokasi… akurasi saat ini ±' + meterTeks(fix.coords.accuracy) + ', mencari yang lebih baik.', '');
                if (fix.coords.accuracy <= 30) selesaiGps();
            }, function(err) {
                if (err.code === 1) selesaiGps('ditolak');            // izin ditolak: berhenti sekarang
                // kode 2 / 3 (posisi belum tersedia / lambat): tunggu sampai batas waktu
            }, { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 });
            timerGps = setTimeout(function() { selesaiGps(); }, 20000);
        });

        // ---------- foto: diperkecil di peramban sebelum diunggah ----------
        function kecilkan(file) {
            return new Promise(function(selesai) {
                if (!window.createImageBitmap || !/^image\/(jpeg|png|webp)$/.test(file.type)) { selesai(file); return; }
                createImageBitmap(file, { imageOrientation: 'from-image' }).then(function(bmp) {
                    var s = Math.min(1, 1600 / Math.max(bmp.width, bmp.height)), c = document.createElement('canvas'), g;
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
        function setFoto(blob) {
            var prev = $('fotoImg').getAttribute('src');
            if (prev && prev.indexOf('blob:') === 0) URL.revokeObjectURL(prev);
            fotoBlob = blob;
            $('fotoPrev').hidden = !blob;
            $('fz').classList.toggle('ada', !!blob);
            $('fzLabel').textContent = blob ? 'Foto siap dikirim' : 'Ambil / Pilih Foto';
            $('fzSub').textContent = blob ? 'Ketuk untuk mengganti foto' : 'Ketuk untuk membuka kamera atau galeri';
            if (blob) $('fotoImg').src = URL.createObjectURL(blob); else $('fotoImg').removeAttribute('src');
        }
        $('foto').addEventListener('change', function() {
            var file = this.files && this.files[0];
            if (!file) return;
            $('fz').classList.add('sibuk');
            kecilkan(file).then(function(b) { $('fz').classList.remove('sibuk'); setFoto(b); });
            this.value = '';
        });
        $('fotoHapus').addEventListener('click', function() { setFoto(null); });

        // ---------- kosongkan ----------
        function kosongkan() {
            form.reset();
            setFoto(null);
            resetGps();
            awalIsian();
        }
        $('kosong').addEventListener('click', function() { kosongkan(); msg(true, ''); });

        // ---------- kirim ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return;                       // klik ganda = kegiatan tercatat dua kali
            function gagal(t, el) { msg(false, t); if (el) el.focus(); }

            var produk = Array.prototype.map.call(document.querySelectorAll('#produk input:checked'), function(i) { return i.value; }).join(', '),
                prospek = parseInt($('prospek').value, 10);
            if (!$('tanggal').value) return gagal('Tanggal kegiatan wajib diisi.', $('tanggal'));
            if (!$('jam').value) return gagal('Jam kegiatan wajib diisi.', $('jam'));
            if (!produk) return gagal('Pilih minimal satu produk yang dipromosikan.', $('pKredit'));
            if (isNaN(prospek) || prospek < 0) return gagal('Jumlah prospek harus berupa angka 0 atau lebih.', $('prospek'));
            if (!fix || !$('gps').value.trim()) return gagal('Lokasi kegiatan wajib diambil (tombol Ambil Lokasi GPS).', gps);
            if (!fotoBlob) return gagal('Foto kegiatan wajib diisi.', $('fz'));

            var fd = new FormData();
            fd.set('_csrf', form.elements._csrf.value);
            fd.set('jenis_kegiatan', form.elements.jenis_kegiatan.value);
            fd.set('tanggal', $('tanggal').value);
            fd.set('jam', $('jam').value);
            fd.set('produk', produk);
            fd.set('prospek', String(prospek));
            fd.set('keterangan', $('keterangan').value);
            fd.set('lokasi_gps', $('gps').value.trim());
            fd.set('alamat_kegiatan', $('alamat').value.trim());
            fd.set('foto', fotoBlob, 'foto.jpg');

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';
            msg(true, '');

            jfetch('api/api_promosi.php?aksi=insert', { method: 'POST', body: fd })
                .then(function(d) { kosongkan(); msg(true, d.message || 'Data berhasil disimpan.'); muat(); })
                .catch(function(err) { if (err.message !== '401') msg(false, err.message); })
                .then(function() {
                    sending = false;
                    btn.disabled = false;
                    btn.classList.remove('loading');
                    btn.querySelector('.t').textContent = 'Simpan';
                });
        });

        // ---------- daftar kegiatan saya ----------
        function muat() {
            var ini = ++seq, q = new URLSearchParams({ tgl1: fl.elements.tgl1.value, tgl2: fl.elements.tgl2.value });
            if (fl.elements.jenis.value) q.set('jenis', fl.elements.jenis.value);
            tb.innerHTML = '<tr><td colspan="7" class="muted">Memuat…</td></tr>';
            Promise.all([jfetch('api/api_promosi.php?aksi=preview&' + q), jfetch('api/api_promosi.php?aksi=read&' + q)])
                .then(function(h) {
                    if (ini !== seq) return;
                    var rows = h[0].rows || [], k = h[1];
                    $('kpi').innerHTML = kpi('Jumlah kegiatan', nf.format(k.total)) + kpi('Total prospek', nf.format(k.total_prospek)) + kpi('Rata-rata prospek', nf.format(k.rata_prospek));
                    tb.innerHTML = rows.length ? rows.map(function(r) {
                        var alamat = esc(r.alamat_kegiatan || '') + (r.lokasi_gps ? (r.alamat_kegiatan ? '<br>' : '') + '<a href="https://www.google.com/maps?q=' + encodeURIComponent(String(r.lokasi_gps).replace(/\s+/g, '')) + '" target="_blank" rel="noopener">Lihat peta</a>' : '');
                        return '<tr><td>' + esc(waktu(r.waktu_kegiatan)) + '</td><td>' + esc(r.jenis_kegiatan) + '</td><td style="white-space:normal;min-width:140px">' + esc(r.produk) +
                            '</td><td class="num">' + nf.format(Number(r.jumlah_prospek) || 0) + '</td><td style="white-space:normal;min-width:180px">' + (alamat || '<span class="muted">–</span>') +
                            '</td><td style="white-space:normal;min-width:200px">' + esc(r.keterangan) + '</td><td>' +
                            (r.foto ? '<a href="' + esc(r.foto) + '" target="_blank" rel="noopener"><img src="' + esc(r.foto) + '" alt="foto" style="width:64px;height:48px;object-fit:cover;border-radius:6px"></a>' : '<span class="muted">–</span>') + '</td></tr>';
                    }).join('') : '<tr><td colspan="7" class="muted">Belum ada kegiatan pada rentang ini.</td></tr>';
                })
                .catch(function(err) { if (ini === seq && err.message !== '401') { tb.innerHTML = '<tr><td colspan="7" class="muted">–</td></tr>'; msg(false, err.message); } });
        }
        fl.addEventListener('submit', function(e) { e.preventDefault(); muat(); });

        var now = new Date();
        fl.elements.tgl1.value = ymd(new Date(now.getFullYear(), now.getMonth(), 1));
        fl.elements.tgl2.value = ymd(now);
        awalIsian();
        muat();
    })();
</script>
<?php page_foot();