<?php
// Data halaman ini diambil dari API backend (tidak menyentuh database).
require __DIR__ . '/lib.php';
if (($_GET['data'] ?? '') === '1') {
    require_once __DIR__ . '/api/api_client.php';
    api_proxy('debitur', ['tgl', 'ao', 'kolek', 'aging']);
}
page_head('Debitur', 'debitur');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div>
    <h1>Debitur</h1>
    <div class="sub">Daftar debitur per posisi. Pilih filter, atau klik nama AO atau kelompok aging di dashboard.</div>
</div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
    <label class="f">Tanggal posisi<select id="fTgl"></select></label>
    <label class="f">AO<select id="fAo"></select></label>
    <label class="f">Kolek<select id="fKolek">
            <option value="">Semua</option>
            <option value="npl">NPL (kolek 3-5)</option>
            <option value="1">Kolek 1 (Lancar)</option>
            <option value="2">Kolek 2 (DPK)</option>
            <option value="3">Kolek 3 (Kurang Lancar)</option>
            <option value="4">Kolek 4 (Diragukan)</option>
            <option value="5">Kolek 5 (Macet)</option>
        </select></label>
    <label class="f">Hari tunggak<select id="fAging">
            <option value="">Semua</option>
            <option value="0">Lancar (0 hari)</option>
            <option value="1">1–30 hari</option>
            <option value="2">31–60 hari</option>
            <option value="3">61–90 hari</option>
            <option value="4">Lebih dari 90 hari</option>
        </select></label>
    <button type="button" class="btn" id="fReset">Reset filter</button>
</div>

<div class="card">
    <div id="ringkas" class="muted" style="margin-bottom:8px">Memuat…</div>
    <div class="scroll">
        <table id="tblDeb" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th>
                    <th>Alamat</th>
                    <th>AO</th>
                    <th>Plafon</th>
                    <th>Bakidebet</th>
                    <th>Kolek</th>
                    <th>Hari tunggak</th>
                    <th>CKPN</th>
                    <th>Tagihan bln ini</th>
                    <th>Bayar bln ini</th>
                    <th>Jatuh tempo</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. Urutan awal: hari tunggak terbanyak. Kotak Cari bisa mencari nama, norek, CIF, atau alamat.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="dt-id.js"></script>
<script>
    $(function() {
        var F = {
            tgl: document.getElementById('fTgl'),
            ao: document.getElementById('fAo'),
            kolek: document.getElementById('fKolek'),
            aging: document.getElementById('fAging')
        };
        var msgBox = document.getElementById('msg'),
            ringkas = document.getElementById('ringkas'),
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

        function fill(sel, opts, val, semua) {
            sel.innerHTML = (semua ? '<option value="">' + semua + '</option>' : '') +
                opts.map(function(o) {
                    return '<option value="' + esc(o.v) + '"' + (o.v === val ? ' selected' : '') + '>' + esc(o.l) + '</option>';
                }).join('');
        }

        function query() {
            var p = new URLSearchParams();
            Object.keys(F).forEach(function(k) {
                if (F[k].value) p.set(k, F[k].value);
            });
            return p.toString();
        }

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblDeb').DataTable({
            data: [],
            order: [
                [6, 'desc']
            ],
            pageLength: 25,
            lengthMenu: [25, 50, 100, 250],
            language: window.DT_ID,
            columns: [{
                    data: 'nama',
                    render: function(d, t, r) {
                        if (t !== 'display') return (r.nama + ' ' + r.norek + ' ' + r.cif).trim(); // urut & cari: nama, norek, CIF
                        return (r.nama ? '<b>' + esc(r.nama) + '</b><br>' : '') + '<span class="muted">' + esc(r.norek) + ' · CIF ' + esc(r.cif) + '</span>';
                    }
                },
                {
                    data: 'alamat',
                    render: function(d) {
                        return d ? esc(d) : '<span class="muted">–</span>';
                    }
                },
                {
                    data: 'ao',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'plafon',
                    render: numR('plafon')
                },
                {
                    data: 'bakidebet',
                    render: numR('bakidebet')
                },
                {
                    data: 'kolek',
                    render: function(d, t) {
                        return t === 'display' ? '<span class="' + (d >= 3 ? 'bad' : '') + '">' + esc(d) + '</span>' : d;
                    }
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
                    data: 'tagihan',
                    render: numR('tagihan')
                },
                {
                    data: 'bayar',
                    render: numR('bayar')
                },
                {
                    data: 'tempo',
                    render: function(d, t, r) {
                        return (t === 'sort' || t === 'type') ? r.tempo_raw : esc(d);
                    }
                }
            ]
        });

        function load(qs, push) {
            var n = ++seq;
            ringkas.textContent = 'Memuat…';
            fetch('nasabah.php?data=1&' + qs, {
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
                    msgBox.innerHTML = '';
                    if (d.empty) {
                        table.clear().draw();
                        ringkas.textContent = 'Belum ada data. Upload nominatif dulu.';
                        return;
                    }
                    fill(F.tgl, d.dates, d.sel.tgl);
                    fill(F.ao, d.aos, d.sel.ao, 'Semua AO');
                    F.kolek.value = d.sel.kolek;
                    F.aging.value = d.sel.aging;
                    table.clear().rows.add(d.rows).draw();
                    ringkas.innerHTML = '<b>' + esc(d.total.n) + ' debitur</b> · Bakidebet ' + esc(d.total.bakidebet) + ' · CKPN ' + esc(d.total.ckpn) +
                        (d.terpotong ? ' · <span class="bad">hanya ' + esc(d.batas) + ' baris pertama yang ditampilkan, persempit filter</span>' : '');
                    if (push) history.pushState(null, '', '?' + query());
                })
                .catch(function() {
                    if (n !== seq) return;
                    ringkas.textContent = '';
                    msgBox.innerHTML = '<div class="flash err">Gagal memuat daftar debitur.</div>';
                });
        }

        Object.keys(F).forEach(function(k) {
            F[k].addEventListener('change', function() {
                load(query(), true);
            });
        });
        document.getElementById('fReset').addEventListener('click', function() {
            F.ao.value = '';
            F.kolek.value = '';
            F.aging.value = '';
            load(query(), true);
        });
        window.addEventListener('popstate', function() {
            load(location.search.slice(1), false);
        });
        load(location.search.slice(1), false);
    });
</script>
<?php page_foot();