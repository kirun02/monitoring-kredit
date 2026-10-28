<?php
// Data halaman ini diambil dari API backend (tidak menyentuh database).
require __DIR__ . '/lib.php';
if (($_GET['data'] ?? '') === '1') {
    require_once __DIR__ . '/api/api_client.php';
    api_proxy('prioritas', ['tgl', 'ao', 'min']);
}
page_head('Prioritas tagih', 'prioritas');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div>
    <h1>Prioritas tagih</h1>
    <div class="sub">Debitur kolek 1-2 yang hari tunggaknya sudah dekat batas NPL (<span class="batas">…</span> hari). Daftar kerja harian untuk AO.</div>
</div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
    <label class="f">Tanggal posisi<select id="fTgl"></select></label>
    <label class="f">AO<select id="fAo"></select></label>
    <label class="f">Hari tunggak minimal<select id="fMin">
            <option value="30">30 hari</option>
            <option value="45">45 hari</option>
            <option value="60">60 hari</option>
            <option value="75">75 hari</option>
            <option value="90">90 hari</option>
        </select></label>
</div>

<div id="ringkasan"></div>

<div class="card" id="perAo"></div>

<div class="card">
    <b>Daftar kerja</b> <span class="muted" id="ringkasDaftar"></span>
    <div class="scroll" style="margin-top:8px">
        <table id="tblPrioritas" style="width:100%">
            <thead>
                <tr>
                    <th>Debitur</th>
                    <th>Alamat</th>
                    <th>AO</th>
                    <th>Bakidebet</th>
                    <th>Kolek</th>
                    <th>Hari tunggak</th>
                    <th>Sisa waktu</th>
                    <th>Tagihan bln ini</th>
                    <th>Bayar bln ini</th>
                    <th>Status bayar</th>
                </tr>
            </thead>
        </table>
    </div>
    <div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. "Sisa waktu" dihitung dari batas NPL <span class="batas">…</span> hari. "Lewat batas" berarti hari tunggak sudah di atas batas tapi kolek di data belum berubah. Urutan awal: hari tunggak terbanyak.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    $(function() {
        var F = {
            tgl: document.getElementById('fTgl'),
            ao: document.getElementById('fAo'),
            min: document.getElementById('fMin')
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

        function opt(v, l, sel) {
            return '<option value="' + esc(v) + '"' + (v === sel ? ' selected' : '') + '>' + esc(l) + '</option>';
        }

        function query() {
            var p = new URLSearchParams();
            Object.keys(F).forEach(function(k) {
                if (F[k].value) p.set(k, F[k].value);
            });
            return p.toString();
        }

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#tblPrioritas').DataTable({
            data: [],
            order: [
                [5, 'desc']
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
                    data: 'bakidebet',
                    render: numR('bakidebet')
                },
                {
                    data: 'kolek',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'hari',
                    render: function(d, t, r) {
                        return t === 'display' ? '<span class="' + (r.lewat ? 'bad' : 'muted') + '"><b>' + esc(d) + '</b></span>' : d;
                    }
                },
                {
                    data: 'sisa',
                    render: function(d, t, r) {
                        return t === 'display' ? '<span class="' + (r.lewat ? 'bad' : '') + '">' + esc(d) + '</span>' : r.sisa_raw;
                    }
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
                    data: 'status',
                    render: function(d, t, r) {
                        return t === 'display' ? '<span class="' + esc(r.status_cls) + '">' + esc(d) + '</span>' : d;
                    }
                }
            ]
        });

        function render(d) {
            msgBox.innerHTML = '';
            F.tgl.innerHTML = d.dates.map(function(t) {
                return opt(t.v, t.l, d.sel.tgl);
            }).join('');
            F.ao.innerHTML = opt('', 'Semua AO', d.sel.ao) + d.aos.map(function(a) {
                return opt(a.v, a.l, d.sel.ao);
            }).join('');
            F.min.value = d.sel.min;

            document.querySelectorAll('.batas').forEach(function(e) {
                e.textContent = d.batas;
            });
            var r = d.ringkas;
            document.getElementById('ringkasan').innerHTML = '<div class="row">' +
                '<div class="card kpi"><div class="muted">Potensi tambahan NPL</div><div class="v bad">' + esc(r.potensi) + '</div><div class="n">' + esc(r.n) + ' debitur kolek 1-2 dengan hari tunggak ≥ ' + esc(d.sel.min) + '</div></div>' +
                '<div class="card kpi"><div class="muted">NPL sekarang</div><div class="v">' + esc(r.npl_pct) + '</div><div class="n">' + esc(r.npl) + '</div></div>' +
                '<div class="card kpi"><div class="muted">NPL kalau semuanya tidak tertagih</div><div class="v bad">' + esc(r.proyeksi_pct) + '</div><div class="n">naik ' + esc(r.selisih) + ' poin dari sekarang</div></div></div>';

            document.getElementById('perAo').innerHTML = '<b>Per AO</b> <span class="muted">klik nama AO untuk memfilter daftar kerja</span>' +
                (d.perAo.length ? '<div class="scroll"><table style="width:100%;margin-top:8px"><thead><tr><th>AO</th><th>Debitur</th><th>Potensi tambahan NPL (Rp)</th><th>Sudah lewat batas</th></tr></thead><tbody>' +
                    d.perAo.map(function(a) {
                        return '<tr><td><a href="#" data-ao="' + esc(a.kode) + '">' + esc(a.ao) + '</a></td><td>' + esc(a.n) + '</td><td>' + esc(a.nom) + '</td><td class="' + (a.lewat !== '0' ? 'bad' : '') + '">' + esc(a.lewat) + '</td></tr>';
                    }).join('') + '</tbody></table></div>' :
                    '<p class="muted">Tidak ada debitur yang memenuhi syarat.</p>');

            document.getElementById('ringkasDaftar').textContent = '· ' + d.daftar.n + ' debitur · ' + d.daftar.nom + (d.sel.ao ? ' (filter AO)' : '');
            table.clear().rows.add(d.rows).draw();
        }

        function load(qs, push) {
            var n = ++seq;
            fetch('prioritas.php?data=1&' + qs, {
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
                    if (n === seq) msgBox.innerHTML = '<div class="flash err">Gagal memuat data prioritas tagih.</div>';
                });
        }

        Object.keys(F).forEach(function(k) {
            F[k].addEventListener('change', function() {
                load(query(), true);
            });
        });
        document.getElementById('perAo').addEventListener('click', function(e) {
            var a = e.target.closest('a[data-ao]');
            if (!a) return;
            e.preventDefault();
            F.ao.value = a.getAttribute('data-ao');
            load(query(), true);
            document.getElementById('tblPrioritas').scrollIntoView({
                behavior: 'smooth',
                block: 'start'
            });
        });
        window.addEventListener('popstate', function() {
            load(location.search.slice(1), false);
        });
        load(location.search.slice(1), false);
    });
</script>
<?php page_foot();