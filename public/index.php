<?php
require __DIR__ . '/lib.php';
page_head('Monitoring kredit', 'dash');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div id="dash" aria-live="polite" style="transition:opacity .15s">
    <p class="muted">Memuat data…</p>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="assets/dt-id.js"></script>
<script>
    (function() {
        var box = document.getElementById('dash'),
            seq = 0,
            aoDt = null,
            state = {
                banding: 'kemarin',
                tgl: '',
                batch: 0
            };

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

        function qs(banding, tgl) {
            var p = new URLSearchParams();
            p.set('banding', banding);
            if (tgl) p.set('tgl', tgl);
            return p.toString();
        }

        function dl(x) {
            return '<span class="' + esc(x.cls) + '">' + esc(x.txt) + '</span>';
        }

        function load(query, push) {
            var n = ++seq;
            box.style.opacity = '.5';
            fetch('api.php?' + query, {
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
                    if (!r.ok) throw new Error(r.status);
                    return r.json();
                })
                .then(function(d) {
                    if (n !== seq) return; // abaikan respons yang sudah basi
                    render(d);
                    box.style.opacity = '';
                    if (push) history.pushState(null, '', '?' + query);
                })
                .catch(function() {
                    if (n !== seq) return;
                    box.style.opacity = '';
                    box.innerHTML = '<div class="flash error">Gagal memuat data. <a href="#" data-retry="' + esc(query) + '">Coba lagi</a></div>';
                });
        }

        function trendSvg(t) {
            if (!t) return '<p class="muted">Grafik muncul setelah ada minimal dua upload harian.</p>';
            var last = t.pts[t.pts.length - 1];
            return '<svg viewBox="0 0 640 200" style="width:100%;height:auto;display:block" role="img" aria-label="Grafik tren NPL">' +
                '<g stroke="#E6ECEA">' + t.ticks.map(function(k) {
                    return '<line x1="40" y1="' + k[0] + '" x2="630" y2="' + k[0] + '"/>';
                }).join('') + '</g>' +
                '<g font-size="11" fill="#4B5F5A">' + t.ticks.map(function(k) {
                    return '<text x="2" y="' + (k[0] + 4) + '">' + esc(k[1]) + '</text>';
                }).join('') +
                '<text x="40" y="198">' + esc(t.first) + '</text><text x="600" y="198">' + esc(t.end) + '</text></g>' +
                '<polyline fill="none" stroke="#0F5F57" stroke-width="2.5" stroke-linejoin="round" points="' +
                t.pts.map(function(p) {
                    return p[0] + ',' + p[1];
                }).join(' ') + '"/>' +
                '<circle cx="' + last[0] + '" cy="' + last[1] + '" r="5" fill="#0F5F57"/></svg>';
        }

        function debUrl(o) {
            return 'nasabah.php?' + new URLSearchParams(o).toString();
        }

        function gerakCard(d) {
            var g = d.gerak,
                url = 'pergerakan.php?' + new URLSearchParams({
                    tgl: d.tgl,
                    banding: d.banding
                }).toString();
            var h = '<div class="card" style="flex:1 1 420px"><div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:12px">' +
                '<b>Pergerakan kolek</b><span class="muted">vs ' + esc(d.bandingNama) + '</span></div>';
            if (!g) return h + '<p class="muted">Pembanding belum tersedia, jadi pergerakan kolek belum bisa dihitung.</p></div>';
            return h + '<div style="display:flex;flex-wrap:wrap;gap:12px;margin-bottom:12px">' +
                '<div style="flex:1 1 160px"><div class="muted">Masuk NPL (kolek 1-2 ke 3-5)</div><div class="bad"><b>' + esc(g.masuk.n) + ' debitur</b></div><div class="n">' + esc(g.masuk.nom) + '</div></div>' +
                '<div style="flex:1 1 160px"><div class="muted">Keluar NPL (kolek 3-5 ke 1-2)</div><div class="good"><b>' + esc(g.keluar.n) + ' debitur</b></div><div class="n">' + esc(g.keluar.nom) + '</div></div></div>' +
                '<a class="btn" href="' + esc(url) + '">Lihat detail dan riwayat harian →</a></div>';
        }

        function agingCard(d) {
            return '<div class="card" style="flex:1 1 380px"><div style="display:flex;justify-content:space-between;gap:8px;margin-bottom:12px">' +
                '<b>Aging tunggakan</b><span class="muted">menurut hari tunggak · Δ vs ' + esc(d.bandingNama) + '</span></div>' +
                '<table style="width:100%"><thead><tr><th>Kelompok</th><th>Debitur</th><th>Bakidebet (Rp)</th><th>% outstanding</th><th>Δ bakidebet</th></tr></thead><tbody>' +
                d.aging.map(function(r) {
                    return '<tr><td><a href="' + esc(debUrl({
                            tgl: d.tgl,
                            aging: r.i
                        })) + '">' + esc(r.label) + '</a>' + (r.rawan ? ' <span title="Mendekati batas NPL">⚠</span>' : '') + '</td><td>' + esc(r.debitur) + '</td><td>' + esc(r.nominal) + '</td><td>' + esc(r.pct) +
                        '</td><td>' + dl(r.d) + ' <span class="muted">(' + esc(r.dDebitur) + ' debitur)</span></td></tr>';
                }).join('') +
                '</tbody></table><div class="muted" style="margin-top:8px">⚠ = kelompok yang paling dekat dengan batas NPL. Klik nama kelompok untuk melihat debiturnya.</div></div>';
        }

        function render(d) {
            if (aoDt) {
                aoDt.destroy();
                aoDt = null;
            }
            if (d.empty) {
                box.innerHTML = '<div class="card"><h1>Belum ada data</h1><p class="sub">Upload file nominatif pertama untuk mulai memantau.</p>' +
                    '<a class="btn primary" href="upload.php">Upload nominatif</a></div>';
                return;
            }
            state = {
                banding: d.banding,
                tgl: d.tgl,
                batch: d.batch
            };
            var k = d.kpi,
                h = '';

            h += '<div class="row" style="align-items:center;justify-content:space-between">' +
                '<div style="flex:1 1 400px"><h1>Monitoring kredit harian</h1><div class="sub">' + esc(d.sub) + '</div></div>' +
                '<div style="flex:0 1 auto;display:flex;flex-wrap:wrap;gap:12px;align-items:center">' +
                '<label class="f" style="flex-direction:row;align-items:center">Tanggal posisi <select id="tgl">' +
                d.dates.map(function(t) {
                    return '<option value="' + esc(t.v) + '"' + (t.v === d.tgl ? ' selected' : '') + '>' + esc(t.l) + '</option>';
                }).join('') +
                '</select></label>' +
                '<label class="f" style="flex-direction:row;align-items:center">Pembanding <select id="banding">' +
                '<option value="kemarin"' + (d.banding === 'kemarin' ? ' selected' : '') + '>Kemarin (upload sebelumnya)</option>' +
                '<option value="baseline"' + (d.banding === 'baseline' ? ' selected' : '') + '>Baseline periode ' + esc(d.periode) + '</option>' +
                d.dates.filter(function(t) {
                    return t.v !== d.tgl;
                }).map(function(t) {
                    return '<option value="' + esc(t.v) + '"' + (t.v === d.banding ? ' selected' : '') + '>' + esc(t.l) + '</option>';
                }).join('') +
                '</select></label></div></div>';

            d.notes.forEach(function(m) {
                h += '<div class="flash info">' + esc(m) + '</div>';
            });

            h += '<div class="row">' +
                '<div class="card kpi"><div class="muted">Outstanding total</div><div class="v">' + esc(k.out.v) + '</div><div class="n">' + dl(k.out.d) + '</div></div>' +
                '<div class="card kpi"><div class="muted">NPL total</div><div class="v">' + esc(k.npl.v) + '</div><div class="n">' + dl(k.npl.d) + ' · ' + esc(k.npl.rp) + '</div></div>' +
                '<div class="card kpi"><div class="muted">Pencapaian angsuran</div><div class="v">' + esc(k.ach.v) + '</div>' +
                '<div class="n">' + esc(k.ach.real) + ' dari tagihan ' + esc(k.ach.tagihan) + '</div>' +
                '<div class="n" style="margin-top:2px">Bayar tunggakan/jatuh tempo: ' + esc(k.ach.lain) + '</div></div>' +
                '<div class="card kpi"><div class="muted">CKPN</div><div class="v">' + esc(k.ckpn.v) + '</div><div class="n">' + dl(k.ckpn.d) + '</div>' +
                '<div class="n" style="margin-top:2px">Turun karena angsuran: <span class="good">' + esc(k.ckpn.turun) + '</span></div></div>' +
                '</div>';

            h += '<div class="row"><div class="card" style="flex:2 1 520px">' +
                '<div style="display:flex;justify-content:space-between;margin-bottom:12px"><b>Tren NPL 30 hari terakhir</b><span class="muted">NPL (%)</span></div>' +
                trendSvg(d.trend) + '</div>' +
                '<div class="card" style="flex:1 1 300px"><b>Komposisi kolektibilitas</b><div style="display:flex;flex-direction:column;gap:12px;margin-top:16px">' +
                d.kolek.map(function(c) {
                    return '<div><div style="display:flex;justify-content:space-between"><span>' + esc(c.nama) + '</span><span>' + esc(c.pct) + '</span></div>' +
                        '<div class="bar"><i style="width:' + Number(c.w) + '%;background:' + esc(c.color) + '"></i></div></div>';
                }).join('') +
                '</div></div></div>';

            h += '<div class="row">' + gerakCard(d) + agingCard(d) + '</div>';

            h += '<div class="card"><div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:space-between;margin-bottom:8px"><b>Kinerja per Account Officer</b>' +
                '<span class="muted">Diurutkan dari NPL tertinggi · klik nama AO untuk membuka daftar debiturnya · semua angka dalam Rupiah · CKPN dibandingkan dengan ' + esc(d.bandingNama) + '</span></div>' +
                '<div class="scroll"><table id="aoTable" style="width:100%"><thead><tr><th>AO</th><th>Debitur</th><th>Outstanding</th><th>NPL (Rp)</th><th>NPL %</th><th>Tagihan</th><th>Realisasi</th>' +
                '<th>Pencapaian</th><th>CKPN</th><th>Δ vs ' + esc(d.bandingNama) + '</th><th>Turun krn angsuran</th></tr></thead><tbody>' +
                d.rows.map(function(r) {
                    var w = r.raw;

                    function td(txt, raw, cls) {
                        return '<td data-order="' + raw + '"' + (cls ? ' class="' + esc(cls) + '"' : '') + '>' + txt + '</td>';
                    }
                    return '<tr><td><a href="' + esc(debUrl({
                            ao: r.kode,
                            tgl: d.tgl
                        })) + '">' + esc(r.ao) + '</a></td>' + td(esc(r.debitur), w.debitur) + td(esc(r.out), w.out) + td(esc(r.npl), w.npl) +
                        td('<b>' + esc(r.nplPct) + '</b>', w.nplPct, r.nplCls) + td(esc(r.tagihan), w.tagihan) + td(esc(r.real), w.real) +
                        td(esc(r.ach), w.ach) + td(esc(r.ckpn), w.ckpn) + td(esc(r.dTxt), w.d, r.dCls) +
                        td(esc(r.turun), w.turun, 'good') + '</tr>';
                }).join('') +
                '</tbody></table></div></div>';

            box.innerHTML = h;
            aoDt = $('#aoTable').DataTable({
                order: [
                    [4, 'desc']
                ],
                paging: false,
                info: false,
                language: window.DT_ID
            });
        }

        box.addEventListener('click', function(e) {
            var r = e.target.closest('[data-retry]');
            if (r) {
                e.preventDefault();
                load(r.getAttribute('data-retry'), false);
            }
        });
        box.addEventListener('change', function(e) {
            if (e.target.id === 'tgl') {
                // pembanding tidak boleh sama dengan tanggal posisi baru
                load(qs(state.banding === e.target.value ? 'kemarin' : state.banding, e.target.value), true);
            } else if (e.target.id === 'banding') {
                load(qs(e.target.value, state.tgl), true);
            }
        });
        window.addEventListener('popstate', function() {
            load(location.search.slice(1), false);
        });

        load(location.search.slice(1), false); // muat pertama kali
    })();
</script>
<?php page_foot();