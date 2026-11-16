<?php
// Catat telepon ke nasabah (penagihan) + daftar telepon milik akun yang login. Menu: web_telepon.
// Pencarian debitur memakai pola yang sama dengan kunjungan.php (api/api_kunjungan.php?jenis=cari).
// Simpan dan daftar telepon lewat api/api_telepon.php (proxy ke API Telepon.php). id_user selalu dari sesi login.
require __DIR__ . '/lib.php';
page_head('Telepon nasabah', 'telepon');
?>
<link rel="stylesheet" href="assets/kunjungan.css">

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false">
    <symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M21 12c0 1.66-4 3-9 3s-9-1.34-9-3"/><path d="M3 5v14c0 1.66 4 3 9 3s9-1.34 9-3V5"/></symbol>
    <symbol id="i-search" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.3-4.3"/></symbol>
    <symbol id="i-file" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></symbol>
    <symbol id="i-send" viewBox="0 0 24 24"><path d="M22 2L11 13"/><path d="M22 2l-7 20-4-9-9-4z"/></symbol>
    <symbol id="i-reset" viewBox="0 0 24 24"><path d="M1 4v6h6"/><path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/></symbol>
    <symbol id="i-x" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></symbol>
    <symbol id="i-cal" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol>
</svg>

<div>
    <h1>Telepon nasabah</h1>
    <div class="sub">Catat hasil telepon penagihan ke nasabah, lengkap dengan janji bayar bila ada.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fTel" method="post" novalidate>
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

    <!-- 1. DATA DEBITUR -->
    <section class="fsec" id="s1">
        <div class="fsec-h">
            <span class="ico ico-biru"><svg class="ic"><use href="#i-db"/></svg></span>
            <div><h2>Data Debitur</h2><p>Cari rekening debitur yang ditelepon</p></div>
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

    <!-- 2. HASIL TELEPON -->
    <section class="fsec" id="s2">
        <div class="fsec-h">
            <span class="ico ico-hijau"><svg class="ic"><use href="#i-file"/></svg></span>
            <div><h2>Hasil Telepon</h2><p>Hasil pembicaraan dan janji pembayaran</p></div>
        </div>
        <div class="fsec-b">
            <label class="lbl-f" for="keterangan">Keterangan <span class="wajib">*</span></label>
            <textarea class="fi" id="keterangan" name="keterangan" maxlength="2000" rows="4" placeholder="mis. nasabah berjanji membayar, sedang di luar kota, nomor tidak aktif"></textarea>
            <div class="hint"><span id="hitung">0</span> / 2000 karakter</div>

            <div class="komit">
                <label class="lbl-f" for="tgl_komit"><svg class="ic" style="width:16px;height:16px;vertical-align:-3px"><use href="#i-cal"/></svg> Tanggal Janji Bayar <span>(opsional)</span></label>
                <input type="date" class="fi" id="tgl_komit" name="tgl_komit" min="<?= h(date('Y-m-d')) ?>">
            </div>
        </div>
    </section>

    <div class="fsec"><div class="fsec-b">
        <div class="aksi">
            <button type="submit" class="btn primary" id="simpan"><svg class="ic"><use href="#i-send"/></svg><span class="t">Simpan Telepon</span><span class="spin" aria-hidden="true"></span></button>
            <button type="reset" class="btn" id="kosong"><svg class="ic"><use href="#i-reset"/></svg>Reset</button>
        </div>
    </div></div>
</form>

<div class="card">
    <form id="fList" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end;margin-bottom:12px">
        <b style="flex:1 1 100%">Telepon saya</b>
        <label class="f">Dari tanggal<input type="date" name="tgl1"></label>
        <label class="f">Sampai tanggal<input type="date" name="tgl2"></label>
        <button type="submit" class="btn" id="btnTampil">Tampilkan</button>
        <span class="muted" id="jumlah"></span>
    </form>
    <div class="scroll">
        <table style="width:100%">
            <thead><tr><th>No</th><th>Nama</th><th>Keterangan</th><th>Janji bayar</th><th>Waktu input</th></tr></thead>
            <tbody id="tbList"><tr><td colspan="5" class="muted">Memuat…</td></tr></tbody>
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
        var HARI_INI = <?= json_encode(date('Y-m-d')) ?>,
            AWAL_BLN = <?= json_encode(date('Y-m-01')) ?>,
            form = document.getElementById('fTel'),
            fl = document.getElementById('fList'),
            f = form.elements,
            msgBox = document.getElementById('msg'),
            btn = document.getElementById('simpan'),
            tb = document.getElementById('tbList'),
            nf = new Intl.NumberFormat('id-ID'),
            sending = false,
            manual = false,
            seqList = 0;

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

        function tgl(s) { // 2026-10-21 -> 21/10/2026
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '–';
        }

        function waktu(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] : (s || '');
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

        fl.elements.tgl1.value = AWAL_BLN;
        fl.elements.tgl2.value = HARI_INI;

        // ---------- hitung karakter ----------
        f.keterangan.addEventListener('input', function() { $('hitung').textContent = f.keterangan.value.length; });

        // ---------- pilih debitur (pola sama dengan kunjungan.php) ----------
        var dlg = $('dlg'), dlgList = $('dlgList'), dlgInfo = $('dlgInfo'), dlgCari = $('dlgCari'), dlgPg = $('dlgPg'),
            tampil = [], kataCari = '', halCari = 1, totalHal = 1, seq = 0;

        function tutupDlg() { if (dlg.open) dlg.close(); }

        function petunjuk() {
            tampil = [];
            dlgList.innerHTML = '';
            dlgPg.style.display = 'none';
            dlgInfo.textContent = 'Ketik nama debitur (minimal 2 huruf) atau nomor rekening, lalu tekan Cari.';
        }

        function gambarHasil(d) {
            tampil = d.rows || [];
            if (d.info && !tampil.length) {   // pencarian tidak tersedia untuk akun ini
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

        // dijalankan di server, hanya saat tombol Cari ditekan atau Enter (bukan setiap ketikan)
        function cariDebitur(hal) {
            var q = dlgCari.value.trim(), ini = ++seq;
            if (hal === 1) kataCari = q;
            q = kataCari;
            if (q.length < 2) { petunjuk(); dlgInfo.textContent = 'Ketik minimal 2 huruf atau angka, lalu tekan Cari.'; return; }
            dlgInfo.textContent = 'Mencari…';
            $('dlgGo').disabled = true;
            ambil('api/api_kunjungan.php?jenis=cari&nama=' + encodeURIComponent(q) + '&page=' + hal).then(function(r) {
                if (ini !== seq) return;
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
        dlg.addEventListener('click', function(e) { if (e.target === dlg) tutupDlg(); });
        $('dlgForm').addEventListener('submit', function(e) { e.preventDefault(); cariDebitur(1); });
        $('pgPrev').addEventListener('click', function() { if (halCari > 1) cariDebitur(halCari - 1); });
        $('pgNext').addEventListener('click', function() { if (halCari < totalHal) cariDebitur(halCari + 1); });

        function isiDebitur(d) {
            var ada = function(v) { return v !== undefined && v !== null && v !== ''; };
            f.rekening.value = d.norek || '';
            f.nama.value = (d.nama || '').slice(0, 50);
            f.alamat.value = (d.alamat || '').slice(0, 150);
            $('dNama').textContent = d.nama || '(tanpa nama)';
            $('dMeta').textContent = (d.norek || '') + (d.cif ? ' · CIF ' + d.cif : '') + (d.alamat ? ' · ' + d.alamat : '');
            $('dBaki').textContent = d.baki ? 'Rp ' + d.baki : '–';
            $('dKolek').textContent = d.kolek ? 'Kolek ' + d.kolek : '–';
            $('dHari').textContent = ada(d.hari) ? d.hari + ' hari' : '–';
            $('dTempo').textContent = d.tempo || '–';
            $('debCard').hidden = false;
            aturManual(false);
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

        // ---------- isian dari halaman lain (sessionStorage, bukan URL, supaya data nasabah tidak tercatat di alamat halaman) ----------
        try {
            var p = JSON.parse(sessionStorage.getItem('telepon_prefill') || 'null');
            if (p && p.rekening) {
                isiDebitur({ norek: p.rekening, nama: p.nama || '', alamat: p.alamat || '' });
                sessionStorage.removeItem('telepon_prefill');
            }
        } catch (e) {}

        // ---------- reset ----------
        form.addEventListener('reset', function() {
            setTimeout(function() {
                $('hitung').textContent = '0';
                $('debCard').hidden = true;
                aturManual(false);
                msgBox.innerHTML = '';
            }, 0);
        });

        // ---------- simpan ----------
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            if (sending) return; // klik ganda = telepon tercatat dua kali

            var rek = f.rekening.value.trim();
            function gagal(t, el) { msg(false, t); if (el) el.focus(); }
            if (!/^[0-9A-Za-z]{1,15}$/.test(rek)) return gagal(manual ? 'Nomor rekening harus berupa huruf atau angka, maksimal 15 karakter.' : 'Pilih debitur terlebih dahulu (tombol Cari Debitur).', manual ? f.rekening : $('btnCari'));
            if (!f.nama.value.trim()) return gagal('Nama debitur wajib diisi.', f.nama);
            if (!f.keterangan.value.trim()) return gagal('Keterangan hasil telepon wajib diisi.', f.keterangan);
            if (f.tgl_komit.value && f.tgl_komit.value < HARI_INI) return gagal('Tanggal janji bayar tidak boleh sebelum hari ini.', f.tgl_komit);
            f.rekening.value = rek;

            sending = true;
            btn.disabled = true;
            btn.classList.add('loading');
            btn.querySelector('.t').textContent = 'Menyimpan…';

            ambil('api/api_telepon.php?aksi=insert', { method: 'POST', body: new FormData(form) }).then(function(r) {
                if (r.ok) {
                    form.reset();
                    msg(true, r.d.message || 'Data berhasil disimpan.');
                    muat();
                } else {
                    msg(false, r.d.error || (r.status === 419 ? 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.' : 'Gagal menyimpan. Coba lagi.'));
                }
            }).catch(function() {
                msg(false, 'Permintaan gagal. Periksa koneksi, lalu cek daftar di bawah sebelum mengirim ulang.');
            }).then(function() {
                sending = false;
                btn.disabled = false;
                btn.classList.remove('loading');
                btn.querySelector('.t').textContent = 'Simpan Telepon';
            });
        });

        // ---------- daftar telepon saya ----------
        function muat() {
            var ini = ++seqList, q = new URLSearchParams({ tgl1: fl.elements.tgl1.value, tgl2: fl.elements.tgl2.value }).toString();
            tb.innerHTML = '<tr><td colspan="5" class="muted">Memuat…</td></tr>';
            Promise.all([ambil('api/api_telepon.php?aksi=preview&' + q), ambil('api/api_telepon.php?aksi=read&' + q)])
                .then(function(h) {
                    if (ini !== seqList) return;
                    if (!h[0].ok || !h[1].ok) throw new Error((h[0].d && h[0].d.error) || (h[1].d && h[1].d.error) || 'Daftar belum bisa dimuat.');
                    var rows = h[0].d.rows || [];
                    $('jumlah').textContent = nf.format(h[1].d.total || 0) + ' telepon pada rentang ini';
                    tb.innerHTML = rows.length ? rows.map(function(r, i) {
                        return '<tr><td>' + (i + 1) + '</td><td>' + esc(r.nama) + '</td><td style="white-space:normal;min-width:240px">' + esc(r.keterangan) + '</td><td>' + esc(tgl(r.tglkomit)) + '</td><td>' + esc(waktu(r.tglInput)) + '</td></tr>';
                    }).join('') : '<tr><td colspan="5" class="muted">Belum ada telepon pada rentang ini.</td></tr>';
                })
                .catch(function(err) {
                    if (ini !== seqList || err.message === '401') return;
                    $('jumlah').textContent = '';
                    tb.innerHTML = '<tr><td colspan="5" class="muted">Daftar belum bisa dimuat.</td></tr>';
                    msg(false, err.message);
                });
        }
        fl.addEventListener('submit', function(e) { e.preventDefault(); muat(); });
        muat();
    })();
</script>
<?php page_foot();