<?php
// Daftar debitur satu AO pada satu batch. Contoh: debitur.php?batch=12&ao=A01
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    $batch = (int)($_GET['batch'] ?? 0);
    $ao = (string)($_GET['ao'] ?? '');
    $b = q('SELECT id, tgl_posisi FROM upload_batch WHERE id = ?', [$batch])->fetch();
    if (!$b) { http_response_code(404); echo json_encode(['error' => 'Batch tidak ditemukan']); exit; }

    $rows = q('SELECT norek, cif, TRIM(nama_nasabah) AS nama_nasabah, alamat, plafon, bakidebet, kolek, COALESCE(hari_tung, 0) AS hari_tung, ckpn, tgl_tempo,
                      kw_bln_pkk + kw_bln_bg AS tagihan, byr_blnpkk + byr_blnbg AS bayar,
                      MAX(nama_ao) OVER () AS nama_ao
               FROM nominatif WHERE batch_id = ? AND kode_ao = ?
               ORDER BY hari_tung DESC NULLS LAST, ckpn DESC LIMIT 5000', [$batch, $ao])->fetchAll();

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'norek' => $r['norek'], 'cif' => $r['cif'],
            'nama' => (string)($r['nama_nasabah'] ?? ''), 'alamat' => (string)($r['alamat'] ?? ''),
            'plafon' => fmt_int($r['plafon']), 'plafon_raw' => (float)$r['plafon'],
            'bakidebet' => fmt_int($r['bakidebet']), 'bakidebet_raw' => (float)$r['bakidebet'],
            'kolek' => (int)$r['kolek'],
            'hari' => (int)$r['hari_tung'],
            'ckpn' => fmt_int($r['ckpn']), 'ckpn_raw' => (float)$r['ckpn'],
            'tagihan' => fmt_int($r['tagihan']), 'tagihan_raw' => (float)$r['tagihan'],
            'bayar' => fmt_int($r['bayar']), 'bayar_raw' => (float)$r['bayar'],
            'tempo' => $r['tgl_tempo'] ? date('d/m/Y', strtotime($r['tgl_tempo'])) : '–',
            'tempo_raw' => $r['tgl_tempo'] ?: '',
        ];
    }
    echo json_encode(['ao' => $rows ? ($rows[0]['nama_ao'] ?: $ao) : $ao, 'tgl' => date('d/m/Y', strtotime($b['tgl_posisi'])), 'rows' => $out],
                     JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (Throwable $e) {
    http_response_code(500);
    error_log('debitur.php: ' . $e->getMessage());
    echo json_encode(['error' => 'Gagal memuat debitur']);
}