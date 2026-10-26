<?php
// Data halaman ini diambil dari API backend (tidak menyentuh database).
require __DIR__ . '/lib.php';
if (($_GET['data'] ?? '') === '1') {
    require_once __DIR__ . '/api/api_client.php';
    api_proxy('pergerakan', ['tgl', 'banding', 'arah']);
}
page_head('Pergerakan kolek', 'gerak');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div>
    <h1>Pergerakan kolek</h1>
    <div class="sub">Debitur yang berpindah kolek antara dua posisi, dan riwayat harian masuk/keluar NPL.</div>
</div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
    <label class="f">Tanggal posisi<select id="fTgl"></select></label>
    <label class="f">Pembanding<select id="fBanding"></select></label>
    <label class="f">Tampilkan debitur<select id="fArah">
            <option value="">Semua perpindahan</option>
            <option value="buruk">Memburuk saja</option>
            <option value="baik">Membaik saja</option>
            <option value="masuk">Masuk NPL (1-2 ke 3-5)</option>
            <option value="keluar">Keluar NPL (3-5 ke 1-2)</option>
        </select></label>
</div>

<div id="ringkasan"></div>

<div class="row">
    <div class="card" style="flex:1 1 380px" id="trans"></div>
    <div class="card" style="flex:2 1 560px" id="harian"></div>
</div>

<div class="card">
    <b id="judulDeb">Debitur yang pindah kolek</b>
    <div class="scroll" style="margin-top:8px">
        <table id="tblGerak" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th>
                    <th>AO</th>
                    <th>Perpindahan</th>
                    <th>Bakidebet</th>
                    <th>Hari tunggak</th>
                    <th>CKPN</th>
                    <th>Δ CKPN</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. Bakidebet, hari tunggak, dan CKPN = posisi sekarang; Δ CKPN dibanding pembanding. Urutan awal: bakidebet terbesar.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    $(function() {
        var F = {
            tgl: document.getElementById('fTgl'),
            banding: document.getElementById('fBanding'),
            arah: document.getElementById('fArah')
        };
        var msgBox = document.getElementById('msg'),
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

        function numR(k) {
            return function(d, t, r) {
                return (t === 'sort' || t === 'type') ? r[k + '_raw'] : esc(d);
            };
        }

        function query() {
            var p = new URLSearchParams();
            Object.keys(F).forEach(function(k) {
                if (F[k].value) p.set(k, F[k].value);
            });
            return p.toString();
        }

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblGerak').DataTable({
            data: [],
            order: [
                [3, 'desc']
            ],
            pageLength: 25,
            lengthMenu: [25, 50, 100, 250],
            language: window.DT_ID,
            columns: [{
                    data: 'nama',
                    render: function(d, t, r) {
                        if (t !== 'display') return (r.nama + ' ' + r.norek + ' ' + r.cif).trim();
                        return (r.nama ? '<b>' + esc(r.nama) + '</b><br>' : '') + '<span class="muted">' + esc(r.norek) + ' · CIF ' + esc(r.cif) + '</span>';
                    }
                },
                {
                    data: 'ao',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'ke',
                    render: function(d, t, r) {
                        if (t !== 'display') return (r.ke - r.dari) * 10 + r.ke; // urut: yang paling memburuk dulu
                        return '<span class="' + (r.ke > r.dari ? 'bad' : 'good') + '">Kolek ' + r.dari + ' → Kolek ' + r.ke + '</span>';
                    }
                },
                {
                    data: 'bakidebet',
                    render: numR('bakidebet')
                },
                {
                    data: 'hari',
                    render: function(d, t) {
                        return t === 'display' ? '<span class="' + (d > 90 ? 'bad' : (d > 60 ? 'muted' : '')) + '">' + esc(d) + '</span>' : d;
                    }
                },
                {
                    data: 'ckpn',
                    render: numR('ckpn')
                },
                {
                    data: 'dck',
                    render: function(d, t, r) {
                        return t === 'display' ? '<span class="' + esc(r.dck_cls) + '">' + esc(d) + '</span>' : r.dck_raw;
                    }
                }
            ]
        });

        function opt(v, l, sel) {
            return '<option value="' + esc(v) + '"' + (v === sel ? ' selected' : '') + '>' + esc(l) + '</option>';
        }

        function render(d) {
            msgBox.innerHTML = '';
            F.tgl.innerHTML = d.dates.map(function(t) {
                return opt(t.v, t.l, d.sel.tgl);
            }).join('');
            F.banding.innerHTML = opt('kemarin', 'Kemarin (upload sebelumnya)', d.sel.banding) + opt('baseline', 'Baseline periode ini', d.sel.banding) +
                d.dates.filter(function(t) {
                    return t.v !== d.sel.tgl;
                }).map(function(t) {
                    return opt(t.v, t.l, d.sel.banding);
                }).join('');
            F.arah.value = d.sel.arah;

            var g = d.gerak,
                rs = document.getElementById('ringkasan'),
                tr = document.getElementById('trans');
            if (!d.tersedia) {
                rs.innerHTML = '<div class="flash info">Pembanding ' + esc(d.bandingNama) + ' belum tersedia. ' +
                    (d.sel.banding === 'baseline' ? 'Tandai satu upload sebagai baseline di halaman Upload.' : 'Pilih tanggal lain atau upload data pembanding.') + '</div>';
                tr.innerHTML = '<b>Perpindahan kolek</b><p class="muted">Belum bisa dihitung tanpa pembanding.</p>';
            } else {
                rs.innerHTML = '<div class="row">' +
                    '<div class="card kpi"><div class="muted">Masuk NPL</div><div class="v bad">' + esc(g.masuk.n) + ' debitur</div><div class="n">' + esc(g.masuk.nom) + '</div></div>' +
                    '<div class="card kpi"><div class="muted">Keluar NPL</div><div class="v good">' + esc(g.keluar.n) + ' debitur</div><div class="n">' + esc(g.keluar.nom) + '</div></div>' +
                    '<div class="card kpi"><div class="muted">Debitur baru</div><div class="v">' + esc(g.baru.n) + '</div><div class="n">' + esc(g.baru.nom) + ' · NPL ' + esc(g.baru.npl) + '</div></div>' +
                    '<div class="card kpi"><div class="muted">Tidak ada lagi (lunas/hapus buku)</div><div class="v">' + esc(g.hilang.n) + '</div><div class="n">' + esc(g.hilang.nom) + ' · NPL ' + esc(g.hilang.npl) + '</div></div></div>';
                tr.innerHTML = '<b>Perpindahan kolek</b> <span class="muted">vs ' + esc(d.bandingNama) + '</span>' +
                    '<table style="width:100%;margin-top:8px"><thead><tr><th>Perpindahan</th><th>Arah</th><th>Debitur</th><th>Bakidebet (Rp)</th></tr></thead><tbody>' +
                    (g.rows.length ? g.rows.map(function(r) {
                        return '<tr><td>' + esc(r.label) + '</td><td class="' + esc(r.cls) + '">' + esc(r.ket) + '</td><td>' + esc(r.debitur) + '</td><td>' + esc(r.nominal) + '</td></tr>';
                    }).join('') : '<tr><td colspan="4" class="muted">Tidak ada debitur yang pindah kolek.</td></tr>') +
                    '</tbody></table>';
            }

            var hr = document.getElementById('harian');
            hr.innerHTML = '<b>Riwayat harian</b> <span class="muted">tiap hari dibanding upload harian sebelumnya · klik tanggal untuk membukanya</span>' +
                (d.riwayat.length ? '<div class="scroll"><table style="width:100%;margin-top:8px"><thead><tr><th>Tanggal</th><th>vs</th><th>NPL</th><th>Masuk NPL</th><th>Keluar NPL</th><th>Memburuk</th><th>Membaik</th></tr></thead><tbody>' +
                    d.riwayat.map(function(r) {
                        return '<tr><td><a href="?tgl=' + esc(r.tgl_iso) + '&banding=kemarin" data-tgl="' + esc(r.tgl_iso) + '">' + esc(r.tgl) + '</a></td><td>' + esc(r.vs) + '</td><td>' + esc(r.npl) + '</td>' +
                            '<td class="' + (r.masuk !== '0' ? 'bad' : '') + '">' + esc(r.masuk) + ' · ' + esc(r.masuk_nom) + '</td>' +
                            '<td class="' + (r.keluar !== '0' ? 'good' : '') + '">' + esc(r.keluar) + ' · ' + esc(r.keluar_nom) + '</td>' +
                            '<td>' + esc(r.buruk) + '</td><td>' + esc(r.baik) + '</td></tr>';
                    }).join('') + '</tbody></table></div>' :
                    '<p class="muted">Perlu minimal dua upload harian.</p>');

            document.getElementById('judulDeb').textContent = 'Debitur yang pindah kolek' + (d.sel.arah ? ' · ' + F.arah.options[F.arah.selectedIndex].text : '');
            table.clear().rows.add(d.rows).draw();
        }

        function load(qs, push) {
            var n = ++seq;
            fetch('pergerakan.php?data=1&' + qs, {
                    credentials: 'same-origin'
                })
                .then(function(r) {
                    if (r.status === 401) {
                        location.href = 'login.php';
                        throw new Error(401);
                    }
                    if (!r.ok) throw new Error(r.status);
                    return r.json();
                })
                .then(function(d) {
                    if (n !== seq) return;
                    if (d.empty) {
                        msgBox.innerHTML = '<div class="flash info">Belum ada data. Upload nominatif dulu.</div>';
                        return;
                    }
                    render(d);
                    if (push) history.pushState(null, '', '?' + query());
                })
                .catch(function() {
                    if (n === seq) msgBox.innerHTML = '<div class="flash err">Gagal memuat data pergerakan.</div>';
                });
        }

        F.tgl.addEventListener('change', function() {
            F.banding.value = 'kemarin';
            load(query(), true);
        });
        F.banding.addEventListener('change', function() {
            load(query(), true);
        });
        F.arah.addEventListener('change', function() {
            load(query(), true);
        });
        document.getElementById('harian').addEventListener('click', function(e) {
            var a = e.target.closest('a[data-tgl]');
            if (!a) return;
            e.preventDefault();
            load('tgl=' + encodeURIComponent(a.getAttribute('data-tgl')) + '&banding=kemarin', true);
        });
        window.addEventListener('popstate', function() {
            load(location.search.slice(1), false);
        });
        load(location.search.slice(1), false);
    });
</script>
<?php page_foot();