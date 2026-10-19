<?php
// Menu Pergerakan: perpindahan kolek antar posisi + riwayat harian + daftar debitur yang pindah.
require __DIR__ . '/lib.php';

/** Riwayat harian: tiap upload harian dibandingkan dengan upload harian sebelumnya (maks 14 hari). */
function riwayat_harian(array $cur): array
{
    $bs = q("SELECT id, tgl_posisi FROM upload_batch WHERE jenis = 'harian' AND tgl_posisi <= ?
             ORDER BY tgl_posisi DESC, id DESC LIMIT 15", [$cur['tgl_posisi']])->fetchAll();
    if (count($bs) < 2) return [];
    $ids = implode(',', array_map(function ($b) { return (int)$b['id']; }, $bs));
    $npl = [];
    foreach (q("SELECT batch_id, SUM(bakidebet) AS o, COALESCE(SUM(bakidebet) FILTER (WHERE kolek >= 3), 0) AS npl
                FROM nominatif WHERE batch_id IN ($ids) GROUP BY batch_id")->fetchAll() as $r) {
        $npl[(int)$r['batch_id']] = (float)$r['o'] > 0 ? (float)$r['npl'] * 100 / (float)$r['o'] : 0.0;
    }
    $sql = 'SELECT COUNT(*) FILTER (WHERE pk <= 2 AND ck >= 3) AS masuk_n,
                   COALESCE(SUM(bd) FILTER (WHERE pk <= 2 AND ck >= 3), 0) AS masuk_nom,
                   COUNT(*) FILTER (WHERE pk >= 3 AND ck <= 2) AS keluar_n,
                   COALESCE(SUM(bd) FILTER (WHERE pk >= 3 AND ck <= 2), 0) AS keluar_nom,
                   COUNT(*) FILTER (WHERE ck > pk) AS buruk_n, COUNT(*) FILTER (WHERE ck < pk) AS baik_n
            FROM (SELECT LEAST(GREATEST(p.kolek, 1), 5) AS pk, LEAST(GREATEST(c.kolek, 1), 5) AS ck, c.bakidebet AS bd
                  FROM nominatif c JOIN nominatif p ON p.norek = c.norek AND p.batch_id = :p
                  WHERE c.batch_id = :c) x';
    $out = [];
    for ($i = 0; $i < count($bs) - 1; $i++) {
        $c = $bs[$i]; $p = $bs[$i + 1];
        $g = q($sql, ['c' => $c['id'], 'p' => $p['id']])->fetch();
        $out[] = [
            'tgl' => date('d/m/Y', strtotime($c['tgl_posisi'])), 'tgl_iso' => $c['tgl_posisi'],
            'vs' => date('d/m', strtotime($p['tgl_posisi'])),
            'npl' => pct($npl[(int)$c['id']] ?? 0, 2) . '%',
            'masuk' => fmt_int($g['masuk_n']), 'masuk_nom' => fmt_int($g['masuk_nom']),
            'keluar' => fmt_int($g['keluar_n']), 'keluar_nom' => fmt_int($g['keluar_nom']),
            'buruk' => fmt_int($g['buruk_n']), 'baik' => fmt_int($g['baik_n']),
        ];
    }
    return $out;
}

function data_pergerakan(): array
{
    $cur = batch_posisi((string)($_GET['tgl'] ?? ''));
    if (!$cur) return ['empty' => true];
    $pb = pembanding($cur, (string)($_GET['banding'] ?? 'kemarin'));
    $arah = (string)($_GET['arah'] ?? '');
    if (!in_array($arah, ['buruk', 'baik', 'masuk', 'keluar'], true)) $arah = '';

    $gerak = $pb['id'] !== null ? kolek_gerak((int)$cur['id'], $pb['id']) : null;

    $rows = [];
    if ($pb['id'] !== null) {
        $kc = 'LEAST(GREATEST(c.kolek, 1), 5)'; $kp = 'LEAST(GREATEST(p.kolek, 1), 5)';
        $cond = ['buruk' => "$kc > $kp", 'baik' => "$kc < $kp", 'masuk' => "$kp <= 2 AND $kc >= 3", 'keluar' => "$kp >= 3 AND $kc <= 2"];
        $extra = $arah !== '' ? ' AND ' . $cond[$arah] : '';
        $res = q("SELECT c.norek, c.cif, TRIM(c.nama_nasabah) AS nama, c.nama_ao, c.kode_ao, c.bakidebet, COALESCE(c.hari_tung, 0) AS hari,
                         c.ckpn, c.ckpn - p.ckpn AS d_ckpn, $kp AS dari, $kc AS ke
                  FROM nominatif c JOIN nominatif p ON p.norek = c.norek AND p.batch_id = :prv
                  WHERE c.batch_id = :cur AND $kc <> $kp $extra
                  ORDER BY c.bakidebet DESC LIMIT 5000", ['cur' => $cur['id'], 'prv' => $pb['id']])->fetchAll();
        foreach ($res as $r) {
            $dd = dlt((float)$r['d_ckpn'], fmt_int(abs((float)$r['d_ckpn'])));
            $rows[] = [
                'norek' => $r['norek'], 'cif' => $r['cif'], 'nama' => (string)($r['nama'] ?? ''),
                'ao' => $r['nama_ao'] ?: (string)$r['kode_ao'],
                'dari' => (int)$r['dari'], 'ke' => (int)$r['ke'],
                'bakidebet' => fmt_int($r['bakidebet']), 'bakidebet_raw' => (float)$r['bakidebet'],
                'hari' => (int)$r['hari'],
                'ckpn' => fmt_int($r['ckpn']), 'ckpn_raw' => (float)$r['ckpn'],
                'dck' => $dd['txt'], 'dck_cls' => $dd['cls'], 'dck_raw' => (float)$r['d_ckpn'],
            ];
        }
    }

    return [
        'empty' => false,
        'sel' => ['tgl' => $cur['tgl_posisi'], 'banding' => $pb['banding'], 'arah' => $arah],
        'bandingNama' => $pb['nama'],
        'dates' => tanggal_harian($cur),
        'tersedia' => $pb['id'] !== null,
        'gerak' => $gerak,
        'riwayat' => riwayat_harian($cur),
        'rows' => $rows,
    ];
}

if (($_GET['data'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode(data_pergerakan(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('pergerakan.php: ' . $e->getMessage());
        echo json_encode(['error' => 'Gagal memuat pergerakan']);
    }
    exit;
}

page_head('Pergerakan kolek', 'gerak');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div><h1>Pergerakan kolek</h1>
<div class="sub">Debitur yang berpindah kolek antara dua posisi, dan riwayat harian masuk/keluar NPL.</div></div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
<label class="f">Tanggal posisi<select id="fTgl"></select></label>
<label class="f">Pembanding<select id="fBanding"></select></label>
<label class="f">Tampilkan debitur<select id="fArah">
<option value="">Semua perpindahan</option><option value="buruk">Memburuk saja</option><option value="baik">Membaik saja</option>
<option value="masuk">Masuk NPL (1-2 ke 3-5)</option><option value="keluar">Keluar NPL (3-5 ke 1-2)</option></select></label>
</div>

<div id="ringkasan"></div>

<div class="row">
<div class="card" style="flex:1 1 380px" id="trans"></div>
<div class="card" style="flex:2 1 560px" id="harian"></div>
</div>

<div class="card">
<b id="judulDeb">Debitur yang pindah kolek</b>
<div class="scroll" style="margin-top:8px"><table id="tblGerak" style="width:100%"><thead><tr>
<th>Debitur</th><th>AO</th><th>Perpindahan</th><th>Bakidebet</th><th>Hari tunggak</th><th>CKPN</th><th>Δ CKPN</th></tr></thead></table></div>
<div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. Bakidebet, hari tunggak, dan CKPN = posisi sekarang; Δ CKPN dibanding pembanding. Urutan awal: bakidebet terbesar.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="dt-id.js"></script>
<script>
$(function () {
  var F = { tgl: document.getElementById('fTgl'), banding: document.getElementById('fBanding'), arah: document.getElementById('fArah') };
  var msgBox = document.getElementById('msg'), seq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function numR(k) { return function (d, t, r) { return (t === 'sort' || t === 'type') ? r[k + '_raw'] : esc(d); }; }
  function query() {
    var p = new URLSearchParams();
    Object.keys(F).forEach(function (k) { if (F[k].value) p.set(k, F[k].value); });
    return p.toString();
  }

  $.fn.dataTable.ext.errMode = 'none';
  var table = $('#tblGerak').DataTable({
    data: [], order: [[3, 'desc']], pageLength: 25, lengthMenu: [25, 50, 100, 250], language: window.DT_ID,
    columns: [
      { data: 'nama', render: function (d, t, r) {
          if (t !== 'display') return (r.nama + ' ' + r.norek + ' ' + r.cif).trim();
          return (r.nama ? '<b>' + esc(r.nama) + '</b><br>' : '') + '<span class="muted">' + esc(r.norek) + ' · CIF ' + esc(r.cif) + '</span>';
      } },
      { data: 'ao', render: function (d) { return esc(d); } },
      { data: 'ke', render: function (d, t, r) {
          if (t !== 'display') return (r.ke - r.dari) * 10 + r.ke;           // urut: yang paling memburuk dulu
          return '<span class="' + (r.ke > r.dari ? 'bad' : 'good') + '">Kolek ' + r.dari + ' → Kolek ' + r.ke + '</span>';
      } },
      { data: 'bakidebet', render: numR('bakidebet') },
      { data: 'hari', render: function (d, t) { return t === 'display' ? '<span class="' + (d > 90 ? 'bad' : (d > 60 ? 'muted' : '')) + '">' + esc(d) + '</span>' : d; } },
      { data: 'ckpn', render: numR('ckpn') },
      { data: 'dck', render: function (d, t, r) { return t === 'display' ? '<span class="' + esc(r.dck_cls) + '">' + esc(d) + '</span>' : r.dck_raw; } }
    ]
  });

  function opt(v, l, sel) { return '<option value="' + esc(v) + '"' + (v === sel ? ' selected' : '') + '>' + esc(l) + '</option>'; }

  function render(d) {
    msgBox.innerHTML = '';
    F.tgl.innerHTML = d.dates.map(function (t) { return opt(t.v, t.l, d.sel.tgl); }).join('');
    F.banding.innerHTML = opt('kemarin', 'Kemarin (upload sebelumnya)', d.sel.banding) + opt('baseline', 'Baseline periode ini', d.sel.banding)
      + d.dates.filter(function (t) { return t.v !== d.sel.tgl; }).map(function (t) { return opt(t.v, t.l, d.sel.banding); }).join('');
    F.arah.value = d.sel.arah;

    var g = d.gerak, rs = document.getElementById('ringkasan'), tr = document.getElementById('trans');
    if (!d.tersedia) {
      rs.innerHTML = '<div class="flash info">Pembanding ' + esc(d.bandingNama) + ' belum tersedia. '
        + (d.sel.banding === 'baseline' ? 'Tandai satu upload sebagai baseline di halaman Upload.' : 'Pilih tanggal lain atau upload data pembanding.') + '</div>';
      tr.innerHTML = '<b>Perpindahan kolek</b><p class="muted">Belum bisa dihitung tanpa pembanding.</p>';
    } else {
      rs.innerHTML = '<div class="row">'
        + '<div class="card kpi"><div class="muted">Masuk NPL</div><div class="v bad">' + esc(g.masuk.n) + ' debitur</div><div class="n">' + esc(g.masuk.nom) + '</div></div>'
        + '<div class="card kpi"><div class="muted">Keluar NPL</div><div class="v good">' + esc(g.keluar.n) + ' debitur</div><div class="n">' + esc(g.keluar.nom) + '</div></div>'
        + '<div class="card kpi"><div class="muted">Debitur baru</div><div class="v">' + esc(g.baru.n) + '</div><div class="n">' + esc(g.baru.nom) + ' · NPL ' + esc(g.baru.npl) + '</div></div>'
        + '<div class="card kpi"><div class="muted">Tidak ada lagi (lunas/hapus buku)</div><div class="v">' + esc(g.hilang.n) + '</div><div class="n">' + esc(g.hilang.nom) + ' · NPL ' + esc(g.hilang.npl) + '</div></div></div>';
      tr.innerHTML = '<b>Perpindahan kolek</b> <span class="muted">vs ' + esc(d.bandingNama) + '</span>'
        + '<table style="width:100%;margin-top:8px"><thead><tr><th>Perpindahan</th><th>Arah</th><th>Debitur</th><th>Bakidebet (Rp)</th></tr></thead><tbody>'
        + (g.rows.length ? g.rows.map(function (r) {
            return '<tr><td>' + esc(r.label) + '</td><td class="' + esc(r.cls) + '">' + esc(r.ket) + '</td><td>' + esc(r.debitur) + '</td><td>' + esc(r.nominal) + '</td></tr>';
          }).join('') : '<tr><td colspan="4" class="muted">Tidak ada debitur yang pindah kolek.</td></tr>')
        + '</tbody></table>';
    }

    var hr = document.getElementById('harian');
    hr.innerHTML = '<b>Riwayat harian</b> <span class="muted">tiap hari dibanding upload harian sebelumnya · klik tanggal untuk membukanya</span>'
      + (d.riwayat.length ? '<div class="scroll"><table style="width:100%;margin-top:8px"><thead><tr><th>Tanggal</th><th>vs</th><th>NPL</th><th>Masuk NPL</th><th>Keluar NPL</th><th>Memburuk</th><th>Membaik</th></tr></thead><tbody>'
        + d.riwayat.map(function (r) {
            return '<tr><td><a href="?tgl=' + esc(r.tgl_iso) + '&banding=kemarin" data-tgl="' + esc(r.tgl_iso) + '">' + esc(r.tgl) + '</a></td><td>' + esc(r.vs) + '</td><td>' + esc(r.npl) + '</td>'
              + '<td class="' + (r.masuk !== '0' ? 'bad' : '') + '">' + esc(r.masuk) + ' · ' + esc(r.masuk_nom) + '</td>'
              + '<td class="' + (r.keluar !== '0' ? 'good' : '') + '">' + esc(r.keluar) + ' · ' + esc(r.keluar_nom) + '</td>'
              + '<td>' + esc(r.buruk) + '</td><td>' + esc(r.baik) + '</td></tr>';
          }).join('') + '</tbody></table></div>'
        : '<p class="muted">Perlu minimal dua upload harian.</p>');

    document.getElementById('judulDeb').textContent = 'Debitur yang pindah kolek' + (d.sel.arah ? ' · ' + F.arah.options[F.arah.selectedIndex].text : '');
    table.clear().rows.add(d.rows).draw();
  }

  function load(qs, push) {
    var n = ++seq;
    fetch('pergerakan.php?data=1&' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        if (n !== seq) return;
        if (d.empty) { msgBox.innerHTML = '<div class="flash info">Belum ada data. Upload nominatif dulu.</div>'; return; }
        render(d);
        if (push) history.pushState(null, '', '?' + query());
      })
      .catch(function () { if (n === seq) msgBox.innerHTML = '<div class="flash err">Gagal memuat data pergerakan.</div>'; });
  }

  F.tgl.addEventListener('change', function () { F.banding.value = 'kemarin'; load(query(), true); });
  F.banding.addEventListener('change', function () { load(query(), true); });
  F.arah.addEventListener('change', function () { load(query(), true); });
  document.getElementById('harian').addEventListener('click', function (e) {
    var a = e.target.closest('a[data-tgl]');
    if (!a) return;
    e.preventDefault();
    load('tgl=' + encodeURIComponent(a.getAttribute('data-tgl')) + '&banding=kemarin', true);
  });
  window.addEventListener('popstate', function () { load(location.search.slice(1), false); });
  load(location.search.slice(1), false);
});
</script>
<?php page_foot();
