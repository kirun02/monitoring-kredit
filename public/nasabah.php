<?php
// Menu Debitur: daftar debitur per posisi dengan filter AO, kolek, dan hari tunggak.
require __DIR__ . '/lib.php';

const AGING_SQL = [
    0 => 'COALESCE(hari_tung, 0) <= 0',
    1 => 'hari_tung BETWEEN 1 AND 30',
    2 => 'hari_tung BETWEEN 31 AND 60',
    3 => 'hari_tung BETWEEN 61 AND 90',
    4 => 'hari_tung > 90',
];
const BATAS_BARIS = 20000;

function daftar_debitur(): array
{
    $tgl = (string)($_GET['tgl'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = '';
    $ao = (string)($_GET['ao'] ?? '');
    $kolek = (string)($_GET['kolek'] ?? '');
    if (!in_array($kolek, ['1', '2', '3', '4', '5', 'npl'], true)) $kolek = '';
    $aging = (string)($_GET['aging'] ?? '');
    if (!in_array($aging, ['0', '1', '2', '3', '4'], true)) $aging = '';

    $cur = q("SELECT id, tgl_posisi FROM upload_batch WHERE jenis = 'harian'" . ($tgl ? ' AND tgl_posisi = ?' : '') .
             ' ORDER BY tgl_posisi DESC, id DESC LIMIT 1', $tgl ? [$tgl] : [])->fetch();
    if (!$cur) $cur = q('SELECT id, tgl_posisi FROM upload_batch ORDER BY tgl_posisi DESC, id DESC LIMIT 1')->fetch();
    if (!$cur) return ['empty' => true];

    // pilihan untuk dropdown
    $dates = [];
    foreach (q("SELECT DISTINCT tgl_posisi FROM upload_batch WHERE jenis = 'harian' ORDER BY tgl_posisi DESC LIMIT 90")->fetchAll() as $t) {
        $dates[] = ['v' => $t['tgl_posisi'], 'l' => date('d M Y', strtotime($t['tgl_posisi']))];
    }
    if (!in_array($cur['tgl_posisi'], array_column($dates, 'v'), true)) {
        array_unshift($dates, ['v' => $cur['tgl_posisi'], 'l' => date('d M Y', strtotime($cur['tgl_posisi']))]);
    }
    $aos = [];
    foreach (q('SELECT kode_ao, MAX(nama_ao) AS nama FROM nominatif WHERE batch_id = ? GROUP BY kode_ao ORDER BY MAX(nama_ao), kode_ao', [$cur['id']])->fetchAll() as $a) {
        $aos[] = ['v' => (string)$a['kode_ao'], 'l' => $a['nama'] ?: (string)$a['kode_ao']];
    }

    // filter
    $w = ['batch_id = :b']; $p = ['b' => $cur['id']];
    if ($ao !== '') { $w[] = 'kode_ao = :ao'; $p['ao'] = $ao; }
    if ($kolek === 'npl') $w[] = 'kolek >= 3';
    elseif ($kolek !== '') { $w[] = 'LEAST(GREATEST(kolek, 1), 5) = :k'; $p['k'] = (int)$kolek; }
    if ($aging !== '') $w[] = AGING_SQL[(int)$aging];
    $where = implode(' AND ', $w);

    $tot = q("SELECT COUNT(*) AS n, COALESCE(SUM(bakidebet), 0) AS bakidebet, COALESCE(SUM(ckpn), 0) AS ckpn FROM nominatif WHERE $where", $p)->fetch();
    $rows = q("SELECT norek, cif, kode_ao, nama_ao, TRIM(nama_nasabah) AS nama, alamat, plafon, bakidebet, kolek,
                      COALESCE(hari_tung, 0) AS hari_tung, ckpn, tgl_tempo,
                      kw_bln_pkk + kw_bln_bg AS tagihan, byr_blnpkk + byr_blnbg AS bayar
               FROM nominatif WHERE $where ORDER BY hari_tung DESC NULLS LAST, ckpn DESC LIMIT " . (BATAS_BARIS + 1), $p)->fetchAll();
    $terpotong = count($rows) > BATAS_BARIS;
    if ($terpotong) array_pop($rows);

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'norek' => $r['norek'], 'cif' => $r['cif'],
            'nama' => (string)($r['nama'] ?? ''), 'alamat' => (string)($r['alamat'] ?? ''),
            'ao' => $r['nama_ao'] ?: (string)$r['kode_ao'],
            'plafon' => fmt_int($r['plafon']), 'plafon_raw' => (float)$r['plafon'],
            'bakidebet' => fmt_int($r['bakidebet']), 'bakidebet_raw' => (float)$r['bakidebet'],
            'kolek' => (int)$r['kolek'], 'hari' => (int)$r['hari_tung'],
            'ckpn' => fmt_int($r['ckpn']), 'ckpn_raw' => (float)$r['ckpn'],
            'tagihan' => fmt_int($r['tagihan']), 'tagihan_raw' => (float)$r['tagihan'],
            'bayar' => fmt_int($r['bayar']), 'bayar_raw' => (float)$r['bayar'],
            'tempo' => $r['tgl_tempo'] ? date('d/m/Y', strtotime($r['tgl_tempo'])) : '–',
            'tempo_raw' => $r['tgl_tempo'] ?: '',
        ];
    }

    return [
        'empty' => false,
        'sel' => ['tgl' => $cur['tgl_posisi'], 'ao' => $ao, 'kolek' => $kolek, 'aging' => $aging],
        'dates' => $dates, 'aos' => $aos,
        'total' => ['n' => fmt_int($tot['n']), 'bakidebet' => rp($tot['bakidebet']), 'ckpn' => rp($tot['ckpn'])],
        'terpotong' => $terpotong, 'batas' => fmt_int(BATAS_BARIS),
        'rows' => $out,
    ];
}

if (($_GET['data'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        echo json_encode(daftar_debitur(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    } catch (Throwable $e) {
        http_response_code(500);
        error_log('nasabah.php: ' . $e->getMessage());
        echo json_encode(['error' => 'Gagal memuat debitur']);
    }
    exit;
}

page_head('Debitur', 'debitur');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div><h1>Debitur</h1>
<div class="sub">Daftar debitur per posisi. Pilih filter, atau klik nama AO atau kelompok aging di dashboard.</div></div>

<div id="msg" aria-live="polite"></div>

<div class="card" style="display:flex;flex-wrap:wrap;gap:16px;align-items:flex-end">
<label class="f">Tanggal posisi<select id="fTgl"></select></label>
<label class="f">AO<select id="fAo"></select></label>
<label class="f">Kolek<select id="fKolek">
<option value="">Semua</option><option value="npl">NPL (kolek 3-5)</option>
<option value="1">Kolek 1 (Lancar)</option><option value="2">Kolek 2 (DPK)</option><option value="3">Kolek 3 (Kurang Lancar)</option>
<option value="4">Kolek 4 (Diragukan)</option><option value="5">Kolek 5 (Macet)</option></select></label>
<label class="f">Hari tunggak<select id="fAging">
<option value="">Semua</option><option value="0">Lancar (0 hari)</option><option value="1">1–30 hari</option>
<option value="2">31–60 hari</option><option value="3">61–90 hari</option><option value="4">Lebih dari 90 hari</option></select></label>
<button type="button" class="btn" id="fReset">Reset filter</button>
</div>

<div class="card">
<div id="ringkas" class="muted" style="margin-bottom:8px">Memuat…</div>
<div class="scroll"><table id="tblDeb" style="width:100%"><thead><tr>
<th>Debitur</th><th>Alamat</th><th>AO</th><th>Plafon</th><th>Bakidebet</th><th>Kolek</th><th>Hari tunggak</th>
<th>CKPN</th><th>Tagihan bln ini</th><th>Bayar bln ini</th><th>Jatuh tempo</th></tr></thead></table></div>
<div class="muted" style="margin-top:8px">Semua angka dalam Rupiah. Urutan awal: hari tunggak terbanyak. Kotak Cari bisa mencari nama, norek, CIF, atau alamat.</div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="dt-id.js"></script>
<script>
$(function () {
  var F = { tgl: document.getElementById('fTgl'), ao: document.getElementById('fAo'),
            kolek: document.getElementById('fKolek'), aging: document.getElementById('fAging') };
  var msgBox = document.getElementById('msg'), ringkas = document.getElementById('ringkas'), seq = 0;

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function numR(k) { return function (d, t, r) { return (t === 'sort' || t === 'type') ? r[k + '_raw'] : esc(d); }; }
  function fill(sel, opts, val, semua) {
    sel.innerHTML = (semua ? '<option value="">' + semua + '</option>' : '')
      + opts.map(function (o) { return '<option value="' + esc(o.v) + '"' + (o.v === val ? ' selected' : '') + '>' + esc(o.l) + '</option>'; }).join('');
  }
  function query() {
    var p = new URLSearchParams();
    Object.keys(F).forEach(function (k) { if (F[k].value) p.set(k, F[k].value); });
    return p.toString();
  }

  $.fn.dataTable.ext.errMode = 'none';
  var table = $('#tblDeb').DataTable({
    data: [], order: [[6, 'desc']], pageLength: 25, lengthMenu: [25, 50, 100, 250], language: window.DT_ID,
    columns: [
      { data: 'nama', render: function (d, t, r) {
          if (t !== 'display') return (r.nama + ' ' + r.norek + ' ' + r.cif).trim();   // urut & cari: nama, norek, CIF
          return (r.nama ? '<b>' + esc(r.nama) + '</b><br>' : '') + '<span class="muted">' + esc(r.norek) + ' · CIF ' + esc(r.cif) + '</span>';
      } },
      { data: 'alamat', render: function (d) { return d ? esc(d) : '<span class="muted">–</span>'; } },
      { data: 'ao', render: function (d) { return esc(d); } },
      { data: 'plafon', render: numR('plafon') },
      { data: 'bakidebet', render: numR('bakidebet') },
      { data: 'kolek', render: function (d, t) { return t === 'display' ? '<span class="' + (d >= 3 ? 'bad' : '') + '">' + esc(d) + '</span>' : d; } },
      { data: 'hari', render: function (d, t) { return t === 'display' ? '<span class="' + (d > 90 ? 'bad' : (d > 60 ? 'muted' : '')) + '">' + esc(d) + '</span>' : d; } },
      { data: 'ckpn', render: numR('ckpn') },
      { data: 'tagihan', render: numR('tagihan') },
      { data: 'bayar', render: numR('bayar') },
      { data: 'tempo', render: function (d, t, r) { return (t === 'sort' || t === 'type') ? r.tempo_raw : esc(d); } }
    ]
  });

  function load(qs, push) {
    var n = ++seq;
    ringkas.textContent = 'Memuat…';
    fetch('nasabah.php?data=1&' + qs, { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
      .then(function (d) {
        if (n !== seq) return;
        msgBox.innerHTML = '';
        if (d.empty) { table.clear().draw(); ringkas.textContent = 'Belum ada data. Upload nominatif dulu.'; return; }
        fill(F.tgl, d.dates, d.sel.tgl);
        fill(F.ao, d.aos, d.sel.ao, 'Semua AO');
        F.kolek.value = d.sel.kolek; F.aging.value = d.sel.aging;
        table.clear().rows.add(d.rows).draw();
        ringkas.innerHTML = '<b>' + esc(d.total.n) + ' debitur</b> · Bakidebet ' + esc(d.total.bakidebet) + ' · CKPN ' + esc(d.total.ckpn)
          + (d.terpotong ? ' · <span class="bad">hanya ' + esc(d.batas) + ' baris pertama yang ditampilkan, persempit filter</span>' : '');
        if (push) history.pushState(null, '', '?' + query());
      })
      .catch(function () {
        if (n !== seq) return;
        ringkas.textContent = '';
        msgBox.innerHTML = '<div class="flash err">Gagal memuat daftar debitur.</div>';
      });
  }

  Object.keys(F).forEach(function (k) { F[k].addEventListener('change', function () { load(query(), true); }); });
  document.getElementById('fReset').addEventListener('click', function () { F.ao.value = ''; F.kolek.value = ''; F.aging.value = ''; load(query(), true); });
  window.addEventListener('popstate', function () { load(location.search.slice(1), false); });
  load(location.search.slice(1), false);
});
</script>
<?php page_foot();