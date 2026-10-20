<?php
// Endpoint JSON untuk dashboard. Contoh: api.php?banding=baseline&tgl=2026-10-19
// Angka sudah diformat di sini (rupiah penuh, persen, selisih), jadi JavaScript tinggal menampilkan.
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Rupiah penuh, mis. 'Rp 1.234.567.890' */
function rp($v): string { return 'Rp ' . fmt_int($v); }

try {
    echo json_encode(build_dashboard(), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('api.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Gagal memuat data']);
}

function build_dashboard(): array
{
    // banding: 'kemarin' (upload harian sebelumnya), 'baseline' (awal periode), atau tanggal upload harian pilihan (Y-m-d)
    $banding = (string)($_GET['banding'] ?? 'kemarin');
    if ($banding !== 'baseline'
        && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $banding, $mm) || !checkdate((int)$mm[2], (int)$mm[3], (int)$mm[1]))) {
        $banding = 'kemarin';
    }
    $tgl = (string)($_GET['tgl'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tgl)) $tgl = '';

    // daftar tanggal hanya upload harian (baseline tidak ikut)
    $tanggalList = q("SELECT DISTINCT tgl_posisi FROM upload_batch WHERE jenis = 'harian' ORDER BY tgl_posisi DESC LIMIT 90")->fetchAll();
    $cur = q("SELECT * FROM upload_batch WHERE jenis = 'harian'" . ($tgl ? ' AND tgl_posisi = ?' : '') .
             ' ORDER BY tgl_posisi DESC, id DESC LIMIT 1', $tgl ? [$tgl] : [])->fetch();
    if (!$cur) { // cadangan: belum ada upload harian, tampilkan apa adanya (mis. baru upload baseline)
        $cur = q('SELECT * FROM upload_batch ' . ($tgl ? 'WHERE tgl_posisi = ? ' : '') .
                 'ORDER BY tgl_posisi DESC, id DESC LIMIT 1', $tgl ? [$tgl] : [])->fetch();
    }
    if (!$cur) return ['empty' => true];
    if ($banding === $cur['tgl_posisi']) $banding = 'kemarin'; // tidak membandingkan dengan dirinya sendiri
    $bandingNama = in_array($banding, ['kemarin', 'baseline'], true) ? $banding : date('d/m/Y', strtotime($banding));

    $blId = q('SELECT baseline_batch_id FROM periode WHERE periode = ?', [$cur['periode']])->fetchColumn() ?: null;
    if ($banding === 'baseline') {
        $prvId = $blId;
    } elseif ($banding !== 'kemarin') {
        $prvId = q("SELECT id FROM upload_batch WHERE jenis = 'harian' AND tgl_posisi = ? LIMIT 1", [$banding])->fetchColumn() ?: null;
    } else {
        $prvId = q("SELECT id FROM upload_batch WHERE jenis = 'harian' AND tgl_posisi < ? ORDER BY tgl_posisi DESC LIMIT 1",
                   [$cur['tgl_posisi']])->fetchColumn() ?: null;
    }
    $hasPrv = $prvId !== null; $hasBl = $blId !== null;

    $tagExpr = $hasBl ? 'COALESCE(b.kw_bln_pkk + b.kw_bln_bg, 0)' : 'c.kw_bln_pkk + c.kw_bln_bg';
    $sqlAo = "
WITH cur AS (SELECT * FROM nominatif WHERE batch_id = :cur),
prv AS (SELECT * FROM nominatif WHERE batch_id = :prv),
bl  AS (SELECT * FROM nominatif WHERE batch_id = :bl),
rc AS (
  SELECT c.kode_ao, c.nama_ao, c.bakidebet, c.kolek, c.ckpn, $tagExpr AS tagihan,
    c.byr_blnpkk + c.byr_blnbg AS bayar,
    CASE WHEN p.norek IS NOT NULL AND (c.t_byr_pkk + c.t_byr_bg) > (p.t_byr_pkk + p.t_byr_bg) AND c.ckpn < p.ckpn
         THEN p.ckpn - c.ckpn ELSE 0 END AS turun
  FROM cur c LEFT JOIN prv p ON p.norek = c.norek LEFT JOIN bl b ON b.norek = c.norek)
SELECT kode_ao, MAX(nama_ao) AS nama_ao, COUNT(*) AS debitur, SUM(bakidebet) AS outstanding,
  COALESCE(SUM(bakidebet) FILTER (WHERE kolek >= 3), 0) AS npl, SUM(tagihan) AS tagihan,
  COALESCE(SUM(bayar) FILTER (WHERE tagihan > 0), 0) AS realisasi,
  COALESCE(SUM(bayar) FILTER (WHERE tagihan = 0), 0) AS bayar_lain,
  SUM(ckpn) AS ckpn, SUM(turun) AS turun
FROM rc GROUP BY kode_ao";
    $rows = q($sqlAo, ['cur' => $cur['id'], 'prv' => $prvId ?? -1, 'bl' => $blId ?? -1])->fetchAll();

    $prv = [];
    foreach (q("SELECT kode_ao, SUM(bakidebet) AS outstanding, COALESCE(SUM(bakidebet) FILTER (WHERE kolek >= 3), 0) AS npl,
                SUM(ckpn) AS ckpn FROM nominatif WHERE batch_id = ? GROUP BY kode_ao", [$prvId ?? -1])->fetchAll() as $r) $prv[$r['kode_ao']] = $r;

    $kolek = q('SELECT LEAST(GREATEST(kolek, 1), 5) AS kolek, SUM(bakidebet) AS total FROM nominatif WHERE batch_id = ? GROUP BY 1 ORDER BY 1', [$cur['id']])->fetchAll();
    $trendRows = q("SELECT b.tgl_posisi, COALESCE(SUM(n.bakidebet) FILTER (WHERE n.kolek >= 3), 0) * 100.0 / NULLIF(SUM(n.bakidebet), 0) AS npl
      FROM upload_batch b JOIN nominatif n ON n.batch_id = b.id
      WHERE b.jenis = 'harian' AND b.tgl_posisi <= :d1 AND b.tgl_posisi > CAST(:d2 AS date) - 30
      GROUP BY b.tgl_posisi ORDER BY b.tgl_posisi", ['d1' => $cur['tgl_posisi'], 'd2' => $cur['tgl_posisi']])->fetchAll();

    // ---------- total & selisih ----------
    $sum = function (array $a, string $k) { $t = 0.0; foreach ($a as $r) $t += (float)$r[$k]; return $t; };
    $out = $sum($rows, 'outstanding'); $npl = $sum($rows, 'npl'); $tagihan = $sum($rows, 'tagihan'); $real = $sum($rows, 'realisasi');
    $ckpn = $sum($rows, 'ckpn'); $turun = $sum($rows, 'turun'); $lain = $sum($rows, 'bayar_lain'); $debitur = (int)$sum($rows, 'debitur');
    $nplPct = $out ? $npl * 100 / $out : 0;
    $pOut = $sum($prv, 'outstanding'); $pNpl = $sum($prv, 'npl'); $pCkpn = $sum($prv, 'ckpn');
    $ach = $tagihan > 0 ? $real * 100 / $tagihan : null;

    $dOut = dlt($hasPrv && $pOut ? ($out - $pOut) * 100 / $pOut : null, '', false);
    $dNpl = dlt($hasPrv && $pOut ? $nplPct - $pNpl * 100 / $pOut : null, '');
    $dCkpn = dlt($hasPrv ? $ckpn - $pCkpn : null, '');
    $hasNum = function (array $d) { return !in_array($d['txt'], ['–', 'tetap'], true); };
    if ($hasPrv && $pOut) {
        if ($hasNum($dOut)) $dOut['txt'] .= pct(abs(($out - $pOut) * 100 / $pOut), 1) . '%';
        if ($hasNum($dNpl)) $dNpl['txt'] .= pct(abs($nplPct - $pNpl * 100 / $pOut), 2) . ' poin';
    }
    if ($hasPrv && $hasNum($dCkpn)) $dCkpn['txt'] .= rp(abs($ckpn - $pCkpn));

    foreach ($rows as &$r) {
        $o = (float)$r['outstanding']; $t = (float)$r['tagihan']; $p = $prv[$r['kode_ao']] ?? null;
        $r['npl_pct'] = $o ? (float)$r['npl'] * 100 / $o : 0;
        $r['ach'] = $t > 0 ? (float)$r['realisasi'] * 100 / $t : null;
        $d = $p ? (float)$r['ckpn'] - (float)$p['ckpn'] : null;
        $r['d_ckpn'] = dlt($d, ''); $r['d_val'] = $d === null ? 0 : abs($d); $r['d_raw'] = $d ?? 0.0;
    }
    unset($r);
    usort($rows, function ($a, $b) { return $b['npl_pct'] <=> $a['npl_pct']; });

    $ktot = 0.0; foreach ($kolek as $k) $ktot += (float)$k['total']; $ktot = $ktot ?: 1;
    $warna = ['#0F5F57', '#7A9F99', '#B7791F', '#9C3D1A', '#6E1B1B'];

    // ---------- grafik tren ----------
    $trend = null;
    if (count($trendRows) > 1) {
        $vals = []; foreach ($trendRows as $r) $vals[] = (float)$r['npl'];
        $lo = min($vals); $hi = max($vals);
        if ($hi - $lo < 0.2) { $lo -= 0.1; $hi += 0.1; }
        $pad = ($hi - $lo) * 0.1; $lo -= $pad; $hi += $pad;
        $n = count($vals); $pts = [];
        foreach ($vals as $i => $v) $pts[] = [round(40 + 590 * $i / ($n - 1), 1), round(180 - 150 * ($v - $lo) / ($hi - $lo), 1)];
        $ticks = [];
        foreach (range(0, 3) as $k) $ticks[] = [round(180 - 150 * $k / 3, 1), pct($lo + ($hi - $lo) * $k / 3, 2)];
        $trend = ['pts' => $pts, 'ticks' => $ticks,
                  'first' => date('d/m', strtotime($trendRows[0]['tgl_posisi'])),
                  'end' => date('d/m', strtotime($trendRows[$n - 1]['tgl_posisi']))];
    }

    // ---------- susun respons (semua angka sudah berformat) ----------
    $notes = [];
    if (!$hasPrv) {
        $notes[] = $banding === 'kemarin'
            ? 'Pembanding kemarin belum tersedia. Perlu minimal dua upload harian.'
            : ($banding === 'baseline'
                ? 'Pembanding baseline belum tersedia untuk periode ini. Tandai satu upload sebagai baseline di halaman Upload.'
                : 'Data pembanding tanggal ' . $bandingNama . ' tidak ditemukan.');
    }
    if (!$hasBl) {
        $notes[] = 'Baseline periode ' . $cur['periode'] . ' belum ada, jadi tagihan diambil dari file posisi ini.';
    }

    $kolekOut = [];
    foreach ($kolek as $k) {
        $p = (float)$k['total'] * 100 / $ktot;
        $kolekOut[] = ['nama' => KOLEK_NAMA[$k['kolek']], 'pct' => pct($p, 1) . '%',
                       'w' => max($p, 0.8), 'color' => $warna[$k['kolek'] - 1]];
    }

    $rowsOut = [];
    foreach ($rows as $r) {
        $c = $r['npl_pct'] >= 5 ? 'bad' : ($r['npl_pct'] >= 3 ? 'muted' : 'good');
        $dc = $r['d_ckpn'];
        $rowsOut[] = [
            'ao' => $r['nama_ao'] ?: $r['kode_ao'],
            'debitur' => fmt_int($r['debitur']),
            'out' => fmt_int($r['outstanding']),
            'npl' => fmt_int($r['npl']),
            'nplPct' => pct($r['npl_pct'], 1) . '%', 'nplCls' => $c,
            'tagihan' => fmt_int($r['tagihan']),
            'real' => fmt_int($r['realisasi']),
            'ach' => pct($r['ach'], 1) . ($r['ach'] !== null ? '%' : ''),
            'ckpn' => fmt_int($r['ckpn']),
            'dTxt' => $dc['txt'] . (in_array($dc['cls'], ['good', 'bad'], true) ? ' ' . fmt_int($r['d_val']) : ''),
            'dCls' => $dc['cls'],
            'turun' => (float)$r['turun'] > 0 ? '▼ ' . fmt_int($r['turun']) : '–',
            // nilai mentah, dipakai DataTables untuk mengurutkan
            'raw' => [
                'debitur' => (int)$r['debitur'], 'out' => (float)$r['outstanding'], 'npl' => (float)$r['npl'],
                'nplPct' => round($r['npl_pct'], 4), 'tagihan' => (float)$r['tagihan'], 'real' => (float)$r['realisasi'],
                'ach' => $r['ach'] === null ? -1 : round($r['ach'], 4), 'ckpn' => (float)$r['ckpn'],
                'd' => (float)$r['d_raw'], 'turun' => (float)$r['turun'],
            ],
        ];
    }

    $dates = [];
    foreach ($tanggalList as $t) $dates[] = ['v' => $t['tgl_posisi'], 'l' => date('d M Y', strtotime($t['tgl_posisi']))];
    if (!in_array($cur['tgl_posisi'], array_column($dates, 'v'), true)) { // mis. batch cadangan di atas
        array_unshift($dates, ['v' => $cur['tgl_posisi'], 'l' => date('d M Y', strtotime($cur['tgl_posisi']))]);
    }

    return [
        'empty' => false,
        'banding' => $banding,
        'tgl' => $cur['tgl_posisi'],
        'periode' => $cur['periode'],
        'dates' => $dates,
        'sub' => 'Posisi ' . date('d/m/Y', strtotime($cur['tgl_posisi'])) . ' · periode tagihan ' . $cur['periode']
               . ' · ' . fmt_int($debitur) . ' debitur · ' . count($rows) . ' AO',
        'bandingNama' => $bandingNama,
        'notes' => $notes,
        'kpi' => [
            'out'  => ['v' => rp($out), 'd' => $dOut],
            'npl'  => ['v' => pct($nplPct, 2) . '%', 'd' => $dNpl, 'rp' => rp($npl)],
            'ach'  => ['v' => pct($ach, 1) . ($ach !== null ? '%' : ''), 'real' => rp($real), 'tagihan' => rp($tagihan), 'lain' => rp($lain)],
            'ckpn' => ['v' => rp($ckpn), 'd' => $dCkpn, 'turun' => rp($turun)],
        ],
        'trend' => $trend,
        'kolek' => $kolekOut,
        'rows' => $rowsOut,
    ];
}