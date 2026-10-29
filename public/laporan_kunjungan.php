<?php
// Laporan kunjungan AO. Tidak menyentuh database: data lewat api_kunjungan.php (proxy ke API).
// AO hanya melihat kunjungannya sendiri (dipaksa di api_kunjungan.php); admin/pimpinan bisa memilih AO.
require __DIR__ . '/lib.php';

$u     = auth_user() ?? [];
$staff = (string)($u['staff'] ?? '');

page_head('Laporan kunjungan', 'lapkun');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<style>
    #tblLap td.wrap { white-space: normal; min-width: 220px; text-align: left; }
    #tblLap td.num, #tblLap th.num { text-align: right; }
    .filter { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-end; }
    @media print {
        .top, .no-print, .dataTables_filter, .dataTables_length, .dataTables_paginate, .dataTables_info { display: none !important; }
        main { padding: 0; }
        .card { border: 0; padding: 0; }
        #tblLap td { white-space: normal; }
    }
</style>

<div>
    <h1>Laporan kunjungan</h1>
    <div class="sub" id="periodeTeks">Pilih rentang tanggal, lalu tekan Tampilkan.</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fLap" class="card filter no-print">
    <label class="f">Dari tanggal<input type="date" name="tgl1" required></label>
    <label class="f">Sampai tanggal<input type="date" name="tgl2" required></label>
    <?php if ($staff !== 'ao'): ?>
        <label class="f">Account Officer<select name="id_user" id="fAo"><option value="">Semua AO</option></select></label>
    <?php endif; ?>
    <button type="submit" class="btn primary" id="tampil">Tampilkan</button>
    <div class="seg" role="group" aria-label="Rentang cepat">
        <a href="#" data-p="hari">Hari ini</a><a href="#" data-p="minggu">7 hari</a><a href="#" data-p="bulan">Bulan ini</a>
    </div>
</form>

<div class="row">
    <div class="card kpi"><div class="muted">Jumlah kunjungan</div><div class="v" id="kKunjungan">–</div></div>
    <div class="card kpi"><div class="muted">Debitur dikunjungi</div><div class="v" id="kDebitur">–</div><div class="n">rekening berbeda</div></div>
    <div class="card kpi"><div class="muted">Ada komitmen bayar</div><div class="v" id="kKomit">–</div><div class="n">kunjungan dengan tanggal komitmen</div></div>
    <div class="card kpi"><div class="muted">Bakidebet dikunjungi</div><div class="v" id="kBaki">–</div><div class="n">Rp, tiap debitur dihitung sekali</div></div>
</div>

<div class="card">
    <div class="no-print" style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;margin-bottom:8px">
        <button type="button" class="btn" id="csv" disabled>Unduh CSV</button>
        <button type="button" class="btn" id="cetak" disabled>Cetak</button>
    </div>
    <div class="scroll">
        <table id="tblLap" class="tl" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th><th>Waktu</th><th>AO</th><th>Kolek</th>
                    <th class="num">Bakidebet</th><th class="num">Tunggakan</th><th>Hasil kunjungan</th><th>Komitmen bayar</th><th>Foto</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. Tunggakan = pokok + bunga. Bakidebet dan kolek diambil dari data kredit terbaru.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    $(function() {
        var IS_AO = <?= json_encode($staff === 'ao') ?>,
            HARI_INI = <?= json_encode(date('Y-m-d')) ?>,
            AWAL_BULAN = <?= json_encode(date('Y-m-01')) ?>,
            form = document.getElementById('fLap'),
            msgBox = document.getElementById('msg'),
            btnTampil = document.getElementById('tampil'),
            btnCsv = document.getElementById('csv'),
            btnCetak = document.getElementById('cetak'),
            rowsKini = [],
            seq = 0;

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        function msg(ok, t) {
            msgBox.innerHTML = t ? '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>' : '';
        }
        var rpFmt = new Intl.NumberFormat('id-ID');

        function rp(v) {
            return rpFmt.format(Math.round(Number(v) || 0));
        }

        function tgl(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(s || '');
            return m ? m[3] + '/' + m[2] + '/' + m[1] : '';
        }

        function waktu(s) {
            return tgl(s) + ((s || '').length >= 16 ? ' ' + s.slice(11, 16) : '');
        }

        function iso(d) {
            var p = function(n) {
                return ('0' + n).slice(-2);
            };
            return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
        }

        // ---------- tabel ----------
        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblLap').DataTable({
            data: [],
            order: [
                [1, 'desc']
            ],
            pageLength: 25,
            lengthMenu: [25, 50, 100, 250],
            language: window.DT_ID,
            columns: [{
                    data: 'nama',
                    className: 'wrap',
                    render: function(d, t, r) {
                        if (t !== 'display') return (r.nama + ' ' + r.rekening + ' ' + r.alamat).trim();
                        return '<b>' + esc(r.nama) + '</b><br><span class="muted">' + esc(r.rekening) + '</span>' +
                            (r.alamat ? '<br><span class="muted">' + esc(r.alamat) + '</span>' : '');
                    }
                },
                {
                    data: 'waktu',
                    render: function(d, t) {
                        return t === 'display' ? esc(waktu(d)) : d;
                    }
                },
                {
                    data: 'nmuser',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'kolek',
                    render: function(d, t) {
                        return t === 'display' ? '<span class="' + (Number(d) >= 3 ? 'bad' : '') + '">' + esc(d) + '</span>' : (Number(d) || 0);
                    }
                },
                {
                    data: 'baki',
                    className: 'num',
                    render: function(d, t) {
                        return t === 'display' ? rp(d) : (Number(d) || 0);
                    }
                },
                {
                    data: null,
                    className: 'num',
                    render: function(d, t, r) {
                        var v = (Number(r.tungPokok) || 0) + (Number(r.tungBung) || 0);
                        return t === 'display' ? rp(v) : v;
                    }
                },
                {
                    data: 'keterangan',
                    className: 'wrap',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'tglKomit',
                    render: function(d, t) {
                        var ada = d && d !== '-';
                        return t === 'display' ? (ada ? esc(tgl(d)) : '<span class="muted">–</span>') : (ada ? d : '');
                    }
                },
                {
                    data: 'gambar',
                    orderable: false,
                    render: function(d, t) {
                        // hanya foto yang diunggah lewat halaman ini (disimpan di hosting) yang bisa dibuka
                        var ok = /^kunjungan\/\d{6}\/[a-f0-9]{24}\.jpg$/.test(d || '');
                        if (t !== 'display') return ok ? 'ada' : '';
                        return ok ? '<a href="foto.php?f=' + encodeURIComponent(d) + '" target="_blank" rel="noopener">Lihat</a>' : '<span class="muted">–</span>';
                    }
                }
            ]
        });

        // ---------- ringkasan ----------
        function ringkas(rows) {
            var uniq = {},
                komit = 0,
                baki = 0;
            rows.forEach(function(r) {
                if (r.tglKomit && r.tglKomit !== '-') komit++;
                if (!uniq[r.rekening]) {
                    uniq[r.rekening] = 1;
                    baki += Number(r.baki) || 0;
                }
            });
            document.getElementById('kKunjungan').textContent = rpFmt.format(rows.length);
            document.getElementById('kDebitur').textContent = rpFmt.format(Object.keys(uniq).length);
            document.getElementById('kKomit').textContent = rpFmt.format(komit);
            document.getElementById('kBaki').textContent = 'Rp ' + rp(baki);
        }

        // ---------- muat data ----------
        function load(push) {
            var tgl1 = form.elements.tgl1.value,
                tgl2 = form.elements.tgl2.value;
            if (!tgl1 || !tgl2) {
                msg(false, 'Isi tanggal awal dan akhir.');
                return;
            }
            if (tgl1 > tgl2) {
                msg(false, 'Tanggal awal tidak boleh lebih besar dari tanggal akhir.');
                return;
            }
            var p = new URLSearchParams({
                jenis: 'laporan',
                tgl1: tgl1,
                tgl2: tgl2
            });
            var ao = form.elements.id_user;
            if (ao && ao.value) p.set('id_user', ao.value);

            var n = ++seq;
            msg(true, '');
            btnTampil.disabled = true;
            btnTampil.textContent = 'Memuat…';
            fetch('api/api_kunjungan.php?' + p.toString(), {
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json'
                    }
                })
                .then(function(r) {
                    if (r.status === 401) {
                        location.href = 'login.php';
                        throw new Error(401);
                    }
                    return r.json().catch(function() {
                        return {};
                    }).then(function(d) {
                        if (!r.ok) throw new Error(d.error || r.status);
                        return d;
                    });
                })
                .then(function(d) {
                    if (n !== seq) return;
                    rowsKini = d.rows || [];
                    table.clear().rows.add(rowsKini).draw();
                    ringkas(rowsKini);
                    btnCsv.disabled = btnCetak.disabled = !rowsKini.length;
                    var namaAo = ao && ao.value ? ao.options[ao.selectedIndex].text : (IS_AO ? 'kunjungan Anda' : 'semua AO');
                    document.getElementById('periodeTeks').textContent = 'Periode ' + tgl(tgl1) + ' – ' + tgl(tgl2) + ' · ' + namaAo;
                    if (!rowsKini.length) msg(true, 'Tidak ada kunjungan pada periode ini.');
                    if (push) history.replaceState(null, '', '?' + p.toString().replace('jenis=laporan&', ''));
                })
                .catch(function(e) {
                    if (n !== seq) return;
                    msg(false, 'Gagal memuat laporan. Coba lagi.');
                })
                .then(function() {
                    if (n === seq) {
                        btnTampil.disabled = false;
                        btnTampil.textContent = 'Tampilkan';
                    }
                });
        }

        // ---------- daftar AO (admin/pimpinan) ----------
        function muatAo(pilih) {
            var sel = document.getElementById('fAo');
            if (!sel) return Promise.resolve();
            return fetch('api/api_kunjungan.php?jenis=daftar_user', {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    return r.ok ? r.json() : {
                        data: []
                    };
                })
                .then(function(d) {
                    (d.data || []).forEach(function(a) {
                        var o = document.createElement('option');
                        o.value = a.id;
                        o.textContent = a.nama + (a.role ? ' (' + a.role + ')' : '');
                        sel.appendChild(o);
                    });
                    if (pilih) sel.value = pilih;
                })
                .catch(function() {});
        }

        // ---------- rentang cepat ----------
        document.querySelector('.seg').addEventListener('click', function(e) {
            var a = e.target.closest('a[data-p]');
            if (!a) return;
            e.preventDefault();
            var t = new Date(HARI_INI + 'T00:00:00'),
                awal = AWAL_BULAN;
            if (a.dataset.p === 'hari') awal = HARI_INI;
            if (a.dataset.p === 'minggu') {
                t.setDate(t.getDate() - 6);
                awal = iso(t);
            }
            form.elements.tgl1.value = awal;
            form.elements.tgl2.value = HARI_INI;
            load(true);
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            load(true);
        });

        // ---------- unduh CSV (pemisah ; supaya terbuka benar di Excel berbahasa Indonesia) ----------
        btnCsv.addEventListener('click', function() {
            function c(v) {
                return '"' + String(v == null ? '' : v).replace(/"/g, '""') + '"';
            }
            var judul = ['Waktu', 'Rekening', 'Nama', 'Alamat', 'AO', 'Kolek', 'Plafon', 'Bakidebet', 'Tunggakan pokok', 'Tunggakan bunga', 'Hasil kunjungan', 'Tgl komitmen'];
            var baris = rowsKini.map(function(r) {
                return [r.waktu, r.rekening, r.nama, r.alamat, r.nmuser, r.kolek, r.plafon, r.baki, r.tungPokok, r.tungBung, r.keterangan, r.tglKomit === '-' ? '' : r.tglKomit].map(c).join(';');
            });
            var blob = new Blob(['\ufeff' + [judul.map(c).join(';')].concat(baris).join('\r\n')], {
                type: 'text/csv;charset=utf-8'
            });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'laporan-kunjungan_' + form.elements.tgl1.value + '_' + form.elements.tgl2.value + '.csv';
            document.body.appendChild(a);
            a.click();
            setTimeout(function() {
                URL.revokeObjectURL(a.href);
                a.remove();
            }, 500);
        });

        // ---------- cetak: tampilkan semua baris, bukan hanya halaman aktif ----------
        btnCetak.addEventListener('click', function() {
            window.print();
        });
        window.addEventListener('beforeprint', function() {
            table.page.len(-1).draw(false);
        });
        window.addEventListener('afterprint', function() {
            table.page.len(25).draw(false);
        });

        // ---------- awal: dari alamat (?tgl1=&tgl2=&id_user=) atau bulan berjalan ----------
        var q = new URLSearchParams(location.search);
        form.elements.tgl1.value = /^\d{4}-\d{2}-\d{2}$/.test(q.get('tgl1') || '') ? q.get('tgl1') : AWAL_BULAN;
        form.elements.tgl2.value = /^\d{4}-\d{2}-\d{2}$/.test(q.get('tgl2') || '') ? q.get('tgl2') : HARI_INI;
        muatAo(q.get('id_user') || '').then(function() {
            load(false);
        });
    });
</script>
<?php page_foot();
