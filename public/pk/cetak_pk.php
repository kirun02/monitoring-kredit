<?php
// cetak_pk.php - cetak Perjanjian Kredit (PK) Angsuran Jaminan SHM dari template Word
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/helpers.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

// Penting: karakter & < > " di data otomatis di-escape supaya file Word tidak rusak
Settings::setOutputEscapingEnabled(true);

// ---------------------------------------------------------------
// 1. AMBIL DATA DARI FORM (form_pk.html)
//    Kalau nanti mau dari database, isi $r dari query PDO di sini.
// ---------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: form_pk.html');
    exit;
}
function inp($k, $d = '') { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : $d; }
function angka($k) { return (int)preg_replace('/\D+/', '', inp($k, '0')); }
function dec($k) { return (float)str_replace(',', '.', inp($k, '0')); }

$kawin = inp('status_kawin') === 'menikah';
$jangka0 = max(1, (int)inp('jangka_waktu', '1'));
$jatuhTempo = inp('tgl_jatuh_tempo');
if ($jatuhTempo === '') {
    $jatuhTempo = (new DateTime(inp('tgl_mulai')))->modify('+' . $jangka0 . ' months')->format('Y-m-d');
}

$r = [
    'no_pk' => inp('no_pk'), 'tgl_pk' => inp('tgl_pk'),
    'direktur1_nama' => inp('direktur1_nama'), 'direktur1_jabatan' => inp('direktur1_jabatan'),
    'direktur2_nama' => inp('direktur2_nama'), 'direktur2_jabatan' => inp('direktur2_jabatan'),

    'nama_debitur' => inp('nama_debitur'), 'tempat_lahir' => inp('tempat_lahir'),
    'tgl_lahir' => inp('tgl_lahir', '1970-01-01') ?: '1970-01-01',
    'no_ktp' => inp('no_ktp'), 'alamat_debitur' => inp('alamat_debitur'),

    'hub_pasangan' => $kawin ? inp('hub_pasangan') : '',
    'nama_pasangan' => $kawin ? inp('nama_pasangan') : '',
    'tempat_lahir_pasangan' => $kawin ? inp('tempat_lahir_pasangan') : '',
    'tgl_lahir_pasangan' => $kawin ? inp('tgl_lahir_pasangan') : '',
    'ktp_pasangan' => $kawin ? inp('ktp_pasangan') : '',

    'tgl_permohonan' => inp('tgl_permohonan'), 'tgl_persetujuan' => inp('tgl_persetujuan'),
    'plafon' => angka('plafon'), 'jangka_waktu' => $jangka0,
    'tgl_mulai' => inp('tgl_mulai'), 'tgl_angsuran_pertama' => inp('tgl_angsuran_pertama'),
    'tgl_jatuh_tempo' => $jatuhTempo,

    'bunga_tahun' => dec('bunga_tahun'), 'penalty_persen' => dec('penalty_persen'),
    'provisi_persen' => dec('provisi_persen'), 'admin_persen' => dec('admin_persen'),
    'denda_persen' => dec('denda_persen'), 'materai' => angka('materai'),

    'agunan_deskripsi' => inp('agunan_deskripsi'),
];

// ---------------------------------------------------------------
// 2. HITUNG ANGKA & TERBILANG
// ---------------------------------------------------------------
$plafon        = (int)$r['plafon'];
$jangka        = (int)$r['jangka_waktu'];
$bungaBulan    = $r['bunga_tahun'] / 12;                       // 18% -> 1.5%
$bungaNominal  = (int)round($plafon * $bungaBulan / 100);      // 600.000 (flat, sesuai PK)
$angsuranPokok = (int)round($plafon / $jangka);                // 1.666.667
$angsuranTotal = $angsuranPokok + $bungaNominal;               // 2.266.667
$provisiNom    = (int)round($plafon * $r['provisi_persen'] / 100);
$adminNom      = (int)round($plafon * $r['admin_persen'] / 100);

$data = [
    'no_pk'      => $r['no_pk'],
    'hari_pk'    => hari_indo($r['tgl_pk']),
    'tgl_pk'     => tgl_indo($r['tgl_pk']),

    'direktur1_nama' => $r['direktur1_nama'], 'direktur1_jabatan' => $r['direktur1_jabatan'],
    'direktur2_nama' => $r['direktur2_nama'], 'direktur2_jabatan' => $r['direktur2_jabatan'],

    'nama_debitur'   => $r['nama_debitur'],
    'tempat_lahir'   => $r['tempat_lahir'],
    'tgl_lahir'      => tgl_indo($r['tgl_lahir']),
    'no_ktp'         => $r['no_ktp'],
    'alamat_debitur' => $r['alamat_debitur'],

    'hub_pasangan'   => $r['hub_pasangan'],
    'nama_pasangan'  => $r['nama_pasangan'],
    'tempat_lahir_pasangan' => $r['tempat_lahir_pasangan'],
    'tgl_lahir_pasangan'    => $r['tgl_lahir_pasangan'] ? tgl_indo($r['tgl_lahir_pasangan']) : '',
    'ktp_pasangan'          => $r['ktp_pasangan'],

    'tgl_permohonan'  => $r['tgl_permohonan'] ? tgl_indo($r['tgl_permohonan']) : '',
    'tgl_persetujuan' => tgl_indo($r['tgl_persetujuan']),

    'plafon'           => rp($plafon),
    'plafon_terbilang' => terbilang_rp($plafon),
    'jangka_waktu'     => $jangka,
    'jangka_waktu_terbilang' => ucwords(terbilang($jangka)),
    'tgl_mulai'        => tgl_indo($r['tgl_mulai']),
    'tgl_jatuh_tempo'  => tgl_indo($r['tgl_jatuh_tempo']),

    'penalty_persen'   => $r['penalty_persen'],
    'penalty_terbilang'=> strtolower(terbilang_persen($r['penalty_persen'])),

    'bunga_tahun'          => number_format($r['bunga_tahun'], 2, '.', ''),
    'bunga_tahun_terbilang'=> terbilang_persen($r['bunga_tahun']),
    'bunga_bulan'          => number_format($bungaBulan, 2, '.', ''),
    'bunga_bulan_terbilang'=> terbilang_persen($bungaBulan),
    'bunga_nominal'          => rp($bungaNominal),
    'bunga_nominal_terbilang'=> terbilang_rp($bungaNominal),

    'provisi_persen'   => $r['provisi_persen'],
    'provisi_terbilang'=> strtolower(terbilang_persen($r['provisi_persen'])),
    'provisi_nominal'  => rp($provisiNom),
    'provisi_nominal_terbilang' => $provisiNom ? terbilang_rp($provisiNom) : 'Nol',

    'admin_persen'     => $r['admin_persen'],
    'admin_terbilang'  => strtolower(terbilang_persen($r['admin_persen'])),
    'admin_nominal'    => rp($adminNom),
    'admin_nominal_terbilang' => $adminNom ? terbilang_rp($adminNom) : 'Nol',

    'materai'          => rp($r['materai']),

    'angsuran_pokok'            => rp($angsuranPokok),
    'angsuran_pokok_terbilang'  => strtolower(terbilang_rp($angsuranPokok)),
    'angsuran_total'            => rp($angsuranTotal),
    'angsuran_total_terbilang'  => terbilang_rp($angsuranTotal),

    'tgl_angsuran'            => (new DateTime($r['tgl_angsuran_pertama']))->format('j'),
    'tgl_angsuran_terbilang'  => ucwords(terbilang((new DateTime($r['tgl_angsuran_pertama']))->format('j'))),
    'tgl_angsuran_pertama'    => tgl_indo($r['tgl_angsuran_pertama']),

    'denda_persen'     => $r['denda_persen'],
    'agunan_deskripsi' => $r['agunan_deskripsi'],
];

// ---------------------------------------------------------------
// 3. ISI TEMPLATE & KIRIM KE BROWSER
// ---------------------------------------------------------------
$tpl = new TemplateProcessor(__DIR__ . '/template/pk_angsuran_shm.docx');
foreach ($data as $key => $val) {
    $tpl->setValue($key, (string)$val);
}

$tmp = tempnam(sys_get_temp_dir(), 'pk_');
$tpl->saveAs($tmp);

$namaFile = 'PK_' . preg_replace('/[^A-Za-z0-9]+/', '_', $r['nama_debitur']) . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $namaFile . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
