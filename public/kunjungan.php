<?php
// Form input kunjungan AO. Tidak menyentuh database: pencarian debitur, simpan, dan daftar hari ini lewat api/api_kunjungan.php (proxy ke API).
require __DIR__ . '/lib.php';

$u    = auth_user() ?? [];
$nama = (string)($u['nama'] ?? ($u['username'] ?? ''));
$saya = (string)($u['id'] ?? ($u['id_user'] ?? ''));   // sesi menyimpan 'id' (versi lama kode ini membaca 'id_user' sehingga selalu kosong)

page_head('Input kunjungan', 'kunjungan');
?>
<link rel="stylesheet" href="assets/kunjungan.css">

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></symbol>
    <symbol id="i-cam" viewBox="0 0 24 24"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></symbol>
    <symbol id="i-pin" viewBox="0 0 24 24"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
    <symbol id="i-reset" viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
    <symbol id="i-cal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
</svg>

<div>
    <h1>Input kunjungan</h1>
</div>

<ol class="steps" id="steps" aria-label="Langkah pengisian">
    <li class="step active" data-s="1"><span class="num">1</span><span class="lbl">Pilih Debitur</span></li>
    <li class="step" data-s="2"><span class="num">2</span><span class="lbl">Isi Keterangan</span></li>
    <li class="step" data-s="3"><span class="num">3</span><span class="lbl">Foto &amp; Lokasi</span></li>
    <li class="step" data-s="4"><span class="num">4</span><span class="lbl">Simpan</span></li>
</ol>

<div id="msg" aria-live="polite"></div>

<form id="fKunjungan" method="post" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="insert">

    <!-- 1. DATA DEBITUR -->
    <section class="fsec" id="s1">
        <div class="fsec-h">
            <span class="ico ico-biru"><svg class="ic"><use href="#i-db"/></svg></span>
            <div><h2>Data Debitur</h2><p>Cari rekening debitur yang akan dikunjungi</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="rekening">No. Rekening <span class="wajib">*</span></label>
            <div class="rek-search">
                <input class="fi" id="rekening" name="rekening" maxlength="15" placeholder="Pilih rekening..." readonly autocomplete="off" autocapitalize="characters" spellcheck="false">
                <button type="button" class="btn primary" id="btnCari"><svg class="ic"><use href="#i-search"/></svg>Cari Debitur</button>
            </div>
            <button type="button" class="manual-toggle" id="btnManual">Debitur tidak ada di daftar? Isi manual</button>

            <div class="manual" id="manual" hidden>
                <div>
                    <label class="lbl-f" for="nama">Nama debitur <span class="wajib">*</span></label>
                    <input class="fi" id="nama" name="nama" maxlength="50" autocomplete="off">
                </div>
                <div class="lebar">
                    <label class="lbl-f" for="alamat">Alamat</label>
                    <input class="fi" id="alamat" name="alamat" maxlength="150" autocomplete="off">
                </div>
            </div>

            <div class="deb-card" id="debCard" hidden>
                <div class="deb-name" id="dNama"></div>
                <div class="deb-meta" id="dMeta"></div>
                <div class="deb-stats">
                    <div class="stat"><span>Baki debet</span><b id="dBaki">–</b></div>
                    <div class="stat"><span>Kolektibilitas</span><b id="dKolek">–</b></div>
                    <div class="stat"><span>Hari tunggak</span><b id="dHari">–</b></div>
                    <div class="stat"><span>Jatuh tempo</span><b id="dTempo">–</b></div>
                </div>
            </div>
        </div>
    </section>

    <!-- 2. KETERANGAN KUNJUNGAN -->
    <section class="fsec" id="s2">
        <div class="fsec-h">
            <span class="ico ico-hijau"><svg class="ic"><use href="#i-file"/></svg></span>
            <div><h2>Keterangan Kunjungan</h2><p>Hasil kunjungan dan komitmen pembayaran</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="keterangan">Keterangan <span class="wajib">*</span></label>
            <textarea class="fi" id="keterangan" name="keterangan" maxlength="2000" rows="4" placeholder="Jelaskan hasil kunjungan, kondisi debitur, dll..."></textarea>
            <div class="hint"><span id="hitung">0</span> / 2000 karakter</div>

            <div class="komit">
                <label class="lbl-f" for="tgl_komit"><svg class="ic" style="width:16px;height:16px;vertical-align:-3px"><use href="#i-cal"/></svg> Tanggal Komit Pembayaran <span>(opsional)</span></label>
                <input type="date" class="fi" id="tgl_komit" name="tgl_komit" min="<?= h(date('Y-m-d')) ?>">
            </div>
        </div>
    </section>

    <!-- 3. FOTO & LOKASI -->
    <section class="fsec" id="s3">
        <div class="fsec-h">
            <span class="ico ico-oranye"><svg class="ic"><use href="#i-cam"/></svg></span>
            <div><h2>Foto &amp; Lokasi</h2><p>Bukti dokumentasi kunjungan</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="lokasi">Lokasi GPS <span class="wajib">*</span></label>
            <div class="lok">
                <button type="button" class="btn" id="btnGps"><svg class="ic"><use href="#i-pin"/></svg><span>Ambil Lokasi</span></button>
                <textarea class="fi" id="lokasi" name="lokasi" maxlength="200" rows="2" readonly placeholder="Koordinat akan muncul di sini"></textarea>
            </div>
            <div class="hint" id="gpsInfo" style="margin-bottom:18px">Opsional. Perlu izin lokasi di peramban.</div>

            <label class="lbl-f" for="foto">Foto Kunjungan <span class="wajib">*</span></label>
            <label class="foto-zone" id="fz" for="foto">
                <svg class="ic besar"><use href="#i-cam"/></svg>
                <span class="fz-label" id="fzLabel">Ambil Foto / Upload Gambar</span>
                <span class="fz-sub" id="fzSub">Klik atau tap untuk membuka kamera atau galeri</span>
            </label>
            <input type="file" id="foto" name="foto" accept="image/*" hidden>
            <div class="foto-prev" id="fotoPrev" hidden>
                <img id="fotoImg" alt="Pratinjau foto kunjungan">
                <button type="button" class="btn" id="fotoHapus"><svg class="ic"><use href="#i-x"/></svg>Hapus foto</button>
            </div>
        </div>
    </section>

    <div class="fsec"><div class="fsec-b">
        <div class="aksi">
            <button type="submit" class="btn primary" id="simpan"><svg class="ic"><use href="#i-send"/></svg><span class="t">Simpan Kunjungan</span><span class="spin" aria-hidden="true"></span></button>
            <button type="reset" class="btn" id="kosong"><svg class="ic"><use href="#i-reset"/></svg>Reset</button>
        </div>
    </div></div>
</form>

<div class="card">
    <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:space-between;align-items:baseline;margin-bottom:8px">
        <b>Kunjungan saya hari ini</b><span class="muted" id="jumlah"></span>
    </div>
    <div class="scroll">
        <table id="hariIni" class="tl" style="width:100%">
            <thead><tr><th>Debitur</th><th>Waktu</th><th>Hasil kunjungan</th><th>Komitmen bayar</th><th>Foto</th></tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- DIALOG CARI DEBITUR -->
<dialog class="dlg" id="dlg" aria-labelledby="dlgJudul">
    <div class="dlg-h">
        <h2 id="dlgJudul">Cari Debitur</h2>
        <button type="button" class="btn" id="dlgTutup" aria-label="Tutup"><svg class="ic"><use href="#i-x"/></svg></button>
    </div>
    <form class="dlg-s" id="dlgForm" role="search" style="display:flex;gap:8px">
        <input class="fi" id="dlgCari" type="search" enterkeyhint="search" placeholder="Ketik nama debitur atau nomor rekening" autocomplete="off" style="flex:1;min-width:0">
        <button type="submit" class="btn primary" id="dlgGo"><svg class="ic"><use href="#i-search"/></svg>Cari</button>
    </form>
    <div class="dlg-info" id="dlgInfo"></div>
    <ul class="dlg-list" id="dlgList"></ul>
    <div id="dlgPg" style="display:none;align-items:center;justify-content:space-between;gap:8px;padding:10px 16px">
        <button type="button" class="btn" id="pgPrev">Sebelumnya</button>
        <span class="muted" id="pgInfo"></span>
        <button type="button" class="btn" id="pgNext">Berikutnya</button>
    </div>
</dialog>

<script>
    (function() {
        var SAYA = <?= json_encode($saya) ?>,
            HARI_INI = <?= json_encode(date('Y-m-d')) ?>,
            form = document.getElementById('fKunjungan'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            sending = false,
            fotoBlob = null,
            manual = false;

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

        function tgl(s) { // 2026-10-21 -> 21/10/2026
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
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

        // ---------- langkah (1 Pilih Debitur, 2 Keterangan, 3 Foto & Lokasi, 4 Simpan) ----------
        var langkah = document.querySelectorAll('#steps .step');

        function perbarui() {
            var selesai = [
                /^[0-9A-Za-z]{1,15}$/.test(f.rekening.value.trim()) && f.nama.value.trim() !== '',
                f.keterangan.value.trim() !== '',
                !!fotoBlob
            ];
            var aktif = selesai.indexOf(false);
            aktif = aktif === -1 ? 3 : aktif; // semua beres -> langkah 4 (Simpan)
            Array.prototype.forEach.call(langkah, function(li, i) {
                li.classList.toggle('done', i < 3 && selesai[i]);
                li.classList.toggle('active', i === aktif);
            });
        }
        form.addEventListener('input', perbarui);

        // ---------- hitung karakter ----------
        f.keterangan.addEventListener('input', function() { $('hitung').textContent = f.keterangan.value.length; });

        // ---------- pilih debitur ----------
        var dlg = $('dlg'), dlgList = $('dlgList'), dlgInfo = $('dlgInfo'), dlgCari = $('dlgCari'), tampil = [];

        function tutupDlg() { if (dlg.open) dlg.close(); }

        var dlgPg = $('dlgPg'), kataCari = '', halCari = 1, totalHal = 1, seq = 0;

        function petunjuk() {
            tampil = [];
            dlgList.innerHTML = '';
            dlgPg.style.display = 'none';
            dlgInfo.textContent = 'Ketik nama debitur (minimal 2 huruf) atau nomor rekening, lalu tekan Cari.';
        }

        function gambarHasil(d) {
            tampil = d.rows || [];
            if (d.info && !tampil.length) {   // pencarian tidak tersedia untuk akun ini (mis. belum ada kode AO)
                dlgList.innerHTML = '';
                dlgPg.style.display = 'none';
                dlgInfo.textContent = d.info;
                return;
            }
            dlgList.innerHTML = tampil.map(function(x, i) {
                return '<li><button type="button" class="dlg-item" data-i="' + i + '"><span><span class="t">' + esc(x.nama || '(tanpa nama)') + '</span><br>' +
                    '<span class="m">' + esc(x.norek) + (x.alamat ? ' · ' + esc(x.alamat) : '') + '</span></span>' +
                    '<span class="r">Kolek ' + esc(x.kolek) + '<br>' + esc(x.hari) + ' hari</span></button></li>';
            }).join('') || '<li class="dlg-info" style="padding:16px">Tidak ada debitur yang cocok.</li>';
            totalHal = d.total_page || 1;
            halCari  = d.page || 1;
            dlgInfo.textContent = (d.total || 0) + ' debitur ditemukan';
            $('pgInfo').textContent = 'Halaman ' + halCari + ' dari ' + totalHal;
            $('pgPrev').disabled = halCari <= 1;
            $('pgNext').disabled = halCari >= totalHal;
            dlgPg.style.display = totalHal > 1 ? 'flex' : 'none';
        }

        // pencarian dijalankan di server, hanya saat tombol Cari ditekan atau Enter (bukan setiap ketikan)
        function cariDebitur(hal) {
            var q = dlgCari.value.trim(), ini = ++seq;
            if (hal === 1) kataCari = q;       // pindah halaman memakai kata kunci yang sama dengan pencarian terakhir
            q = kataCari;
            if (q.length < 2) { petunjuk(); dlgInfo.textContent = 'Ketik minimal 2 huruf atau angka, lalu tekan Cari.'; return; }
            dlgInfo.textContent = 'Mencari…';
            $('dlgGo').disabled = true;
            ambil('api/api_kunjungan.php?jenis=cari&nama=' + encodeURIComponent(q) + '&page=' + hal).then(function(r) {
                if (ini !== seq) return;       // sudah ada pencarian yang lebih baru
                if (!r.ok) {
                    tampil = []; dlgList.innerHTML = ''; dlgPg.style.display = 'none';
                    dlgInfo.textContent = (r.d && r.d.error) || 'Pencarian belum bisa dilakukan. Gunakan isi manual.';
                    return;
                }
                gambarHasil(r.d);
            }).catch(function() {
                if (ini === seq) dlgInfo.textContent = 'Pencarian belum bisa dilakukan. Periksa koneksi, atau gunakan isi manual.';
            }).then(function() { $('dlgGo').disabled = false; });
        }

        $('btnCari').addEventListener('click', function() {
            dlgCari.value = '';
            kataCari = '';
            petunjuk();
            if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
            dlgCari.focus();
        });
        $('dlgTutup').addEventListener('click', tutupDlg);
        dlg.addEventListener('click', function(e) { if (e.target === dlg) tutupDlg(); }); // klik latar
        $('dlgForm').addEventListener('submit', function(e) { e.preventDefault(); cariDebitur(1); });
        $('pgPrev').addEventListener('click', function() { if (halCari > 1) cariDebitur(halCari - 1); });
        $('pgNext').addEventListener('click', function() { if (halCari < totalHal) cariDebitur(halCari + 1); });

        function isiDebitur(d) {
            f.rekening.value = d.norek;
            f.nama.value = d.nama;
            f.alamat.value = d.alamat;
            $('dNama').textContent = d.nama || '(tanpa nama)';
            $('dMeta').textContent = d.norek + (d.cif ? ' · CIF ' + d.cif : '') + (d.alamat ? ' · ' + d.alamat : '');
            $('dBaki').textContent = d.baki ? 'Rp ' + d.baki : '–';
            $('dKolek').textContent = d.kolek ? 'Kolek ' + d.kolek : '–';
            $('dHari').textContent = d.hari + ' hari';
            $('dTempo').textContent = d.tempo || '–';
            $('debCard').hidden = false;
            aturManual(false);
            perbarui();
        }

        dlgList.addEventListener('click', function(e) {
            var b = e.target.closest('.dlg-item');
            if (!b) return;
            isiDebitur(tampil[+b.getAttribute('data-i')]);
            tutupDlg();
            f.keterangan.focus();
        });

        // ---------- isi manual ----------
        function aturManual(aktif) {
            manual = aktif;
            $('manual').hidden = !aktif;
            f.rekening.readOnly = !aktif;
            f.rekening.placeholder = aktif ? 'Ketik nomor rekening' : 'Pilih rekening...';
            $('btnManual').textContent = aktif ? 'Pilih dari daftar debitur' : 'Debitur tidak ada di daftar? Isi manual';
            if (aktif) $('debCard').hidden = true;
        }
        $('btnManual').addEventListener('click', function() {
            if (manual) { aturManual(false); $('btnCari').click(); return; }
            aturManual(true);
            f.rekening.focus();
        });

        // ---------- GPS ----------
        var gps = $('btnGps'), gpsInfo = $('gpsInfo');
        gps.addEventListener('click', function() {
            if (!navigator.geolocation) { msg(false, 'Peramban ini tidak mendukung GPS.'); return; }
            gps.disabled = true;
            gps.querySelector('span').textContent = 'Mencari lokasi…';
            navigator.geolocation.getCurrentPosition(function(p) {
                f.lokasi.value = p.coords.latitude.toFixed(6) + ', ' + p.coords.longitude.toFixed(6);
                gpsInfo.textContent = 'Akurasi sekitar ' + Math.round(p.coords.accuracy) + ' meter.';
                gps.classList.add('dapat');
                gps.disabled = false;
                gps.querySelector('span').textContent = 'Lokasi didapat';
            }, function() {
                msg(false, 'Lokasi tidak bisa diambil. Izinkan akses lokasi di peramban lalu coba lagi.');
                gps.disabled = false;
                gps.querySelector('span').textContent = 'Ambil Lokasi';
            }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 60000 });
        });

        // ---------- foto: diperkecil di peramban sebelum diunggah (foto ponsel 3-8 MB -> sekitar 300 KB) ----------
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
            $('fzLabel').textContent = blob ? 'Foto siap dikirim' : 'Ambil Foto / Upload Gambar';
            $('fzSub').textContent = blob ? 'Ketuk untuk mengganti foto' : 'Klik atau tap untuk membuka kamera atau galeri';
            if (blob) $('fotoImg').src = URL.createObjectURL(blob); else $('fotoImg').removeAttribute('src');
            perbarui();
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
                $('hitung').textContent = '0';
                $('debCard').hidden = true;
                aturManual(false);
                setFoto(null);
                gps.classList.remove('dapat');
                gps.querySelector('span').textContent = 'Ambil Lokasi';
                gpsInfo.textContent = 'Opsional. Perlu izin lokasi di peramban.';
                msgBox.innerHTML = '';
                perbarui();
            }, 0);
        });

        // ---------- daftar kunjungan hari ini ----------
        var tbody = document.querySelector('#hariIni tbody'), jumlah = $('jumlah');

        function muatDaftar() {
            ambil('api/api_kunjungan.php?jenis=preview&tgl1=' + HARI_INI + '&tgl2=' + HARI_INI).then(function(r) {
                if (!r.ok) throw new Error(r.status);
                var rows = (r.d.rows || []).slice().reverse(); // terbaru di atas
                jumlah.textContent = rows.length + ' kunjungan';
                tbody.innerHTML = rows.length ? rows.map(function(x) {
                    var jam = (x.tglInput || '').slice(11, 16);
                    return '<tr><td class="wrap"><b>' + esc(x.nama) + '</b></td><td>' + esc(jam) + '</td>' +
                        '<td class="wrap">' + esc(x.keterangan) + '</td>' +
                        '<td>' + (x.tglkomit && x.tglkomit !== '-' ? esc(tgl(x.tglkomit)) : '<span class="muted">–</span>') + '</td>' +
                        '<td>' + (x.foto ? '<a href="' + esc(x.foto) + '" target="_blank" rel="noopener">Lihat</a>' : '<span class="muted">–</span>') + '</td></tr>';
                }).join('') : '<tr><td colspan="5" class="muted">Belum ada kunjungan hari ini.</td></tr>';
            }).catch(function() {
                jumlah.textContent = '';
                tbody.innerHTML = '<tr><td colspan="5" class="muted">Daftar belum bisa dimuat.</td></tr>';
            });
        }

        // ---------- simpan ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return; // klik ganda = kunjungan tercatat dua kali

            var rek = f.rekening.value.trim();
            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            if (!/^[0-9A-Za-z]{1,15}$/.test(rek)) return gagal(manual ? 'Nomor rekening harus berupa huruf atau angka, maksimal 15 karakter.' : 'Pilih debitur terlebih dahulu (tombol Cari Debitur).', manual ? f.rekening : $('btnCari'));
            if (!f.nama.value.trim()) return gagal('Nama debitur wajib diisi.', f.nama);
            if (!f.keterangan.value.trim()) return gagal('Keterangan kunjungan wajib diisi.', f.keterangan);
            if (!fotoBlob) return gagal('Foto kunjungan wajib diisi.', $('fz'));
            if (!f.lokasi) return gagal('Lokasi wajib diisi.', f.lokasi);
            if (f.tgl_komit.value && f.tgl_komit.value < HARI_INI) return gagal('Tanggal komitmen tidak boleh sebelum hari ini.', f.tgl_komit);
            f.rekening.value = rek;

            var fd = new FormData(form);
            fd.set('foto', fotoBlob, 'foto.jpg');

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';

            ambil('api/api_kunjungan.php', { method: 'POST', body: fd }).then(function(r) {
                if (r.ok) {
                    msg(true, r.d.message || 'Data berhasil disimpan.');
                    form.reset();
                    muatDaftar();
                } else {
                    msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal menyimpan. Coba lagi.'));
                }
            }).catch(function() {
                msg(false, 'Permintaan gagal. Periksa koneksi, lalu cek daftar di bawah sebelum mengirim ulang.');
            }).then(function() {
                sending = false;
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.querySelector('.t').textContent = 'Simpan Kunjungan';
            });
        });

        perbarui();
        muatDaftar();
    })();
</script>
<?php page_foot();