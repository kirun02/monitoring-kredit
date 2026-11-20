<?php
// Membuat file Word PK Angsuran Jaminan SHM dari template. Dipanggil lewat POST dari pk_angsuran.php.
// Data TIDAK disimpan di server.
require __DIR__ . '/../lib.php';
require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/helpers.php';

use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\TemplateProcessor;

function pk_gagal($pesan)
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit($pesan);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    pk_gagal('Metode tidak diizinkan.');
}
// token CSRF dari pk_angsuran.php (csrf_token() dipakai juga oleh form RIPLAY)
if (!isset($_POST['_csrf']) || !hash_equals((string)csrf_token(), (string)$_POST['_csrf'])) {
    pk_gagal('Sesi tidak valid. Muat ulang halaman lalu coba lagi.');
}

function pk_in($k)      { return isset($_POST[$k]) ? trim((string)$_POST[$k]) : ''; }
function pk_num($k)     { return (int)preg_replace('/\D+/', '', pk_in($k)); }
function pk_dec($k)     { return (float)str_replace(',', '.', pk_in($k)); }
function pk_date($k, $wajib)
{
    $v = pk_in($k);
    if ($v === '') {
        if ($wajib) pk_gagal('Tanggal wajib diisi: ' . $k);
        return '';
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) pk_gagal('Format tanggal salah: ' . $k);
    return $v;
}
function pk_tgl($ymd)   { return $ymd === '' ? '' : tgl_indo($ymd); }

// ---------- ambil & validasi ----------
$plafon = pk_num('plafon');
$tenor  = (int)pk_in('tenor');
$bunga  = pk_dec('bunga');
if ($plafon <= 0 || $tenor < 1 || $tenor > 360 || $bunga < 0 || $bunga > 100) {
    pk_gagal('Plafon, jangka waktu, atau suku bunga tidak valid.');
}
if (pk_in('nama') === '' || pk_in('no_pk') === '') {
    pk_gagal('Nomor PK dan nama debitur wajib diisi.');
}

$tglPk      = pk_date('tgl_pk', true);
$tglMulai   = pk_date('tgl_mulai', true);
$tglAngs1   = pk_date('tgl_angsuran_pertama', true);
$tglSetuju  = pk_date('tgl_persetujuan', true);
$tglJT      = pk_date('tgl_jatuh_tempo', false);
if ($tglJT === '') {
    $tglJT = (new DateTime($tglMulai))->modify('+' . $tenor . ' months')->format('Y-m-d');
}

$menikah = pk_in('status') === 'menikah';

// ---------- hitung (bunga flat, sama dengan simulasi di pk_angsuran.php) ----------
$totalBunga    = (int)round($plafon * ($bunga / 100) * ($tenor / 12));
$angsuranPokok = intdiv($plafon, $tenor);
$bungaBulan    = intdiv($totalBunga, $tenor);
$angsuranTotal = $angsuranPokok + $bungaBulan;
$bungaPersenBulan = $bunga / 12;

$provPct  = pk_dec('provisi_persen');
$adminPct = pk_dec('admin_persen');
$provNom  = (int)round($plafon * $provPct / 100);
$adminNom = (int)round($plafon * $adminPct / 100);
$penalty  = pk_dec('penalty_persen');
$denda    = pk_dec('denda_persen');
$hariAngs = (int)(new DateTime($tglAngs1))->format('j');

$nol = function ($n) { return $n > 0 ? terbilang_rp($n) : 'Nol'; };

$data = [
    'no_pk' => pk_in('no_pk'), 'hari_pk' => hari_indo($tglPk), 'tgl_pk' => tgl_indo($tglPk),
    'direktur1_nama' => pk_in('direktur1_nama'), 'direktur1_jabatan' => pk_in('direktur1_jabatan'),
    'direktur2_nama' => pk_in('direktur2_nama'), 'direktur2_jabatan' => pk_in('direktur2_jabatan'),

    'nama_debitur' => pk_in('nama'), 'tempat_lahir' => pk_in('tempat_lahir'),
    'tgl_lahir' => pk_tgl(pk_date('tgl_lahir', false)),
    'no_ktp' => pk_in('nik'), 'alamat_debitur' => pk_in('alamat'),

    'hub_pasangan'  => $menikah ? pk_in('hub_pasangan') : '',
    'nama_pasangan' => $menikah ? pk_in('nama_pasangan') : '',
    'tempat_lahir_pasangan' => $menikah ? pk_in('tempat_lahir_pasangan') : '',
    'tgl_lahir_pasangan'    => $menikah ? pk_tgl(pk_date('tgl_lahir_pasangan', false)) : '',
    'ktp_pasangan'          => $menikah ? pk_in('ktp_pasangan') : '',

    'tgl_permohonan'  => pk_tgl(pk_date('tgl_permohonan', false)),
    'tgl_persetujuan' => tgl_indo($tglSetuju),

    'plafon' => rp($plafon), 'plafon_terbilang' => terbilang_rp($plafon),
    'jangka_waktu' => $tenor, 'jangka_waktu_terbilang' => ucwords(terbilang($tenor)),
    'tgl_mulai' => tgl_indo($tglMulai), 'tgl_jatuh_tempo' => tgl_indo($tglJT),

    'penalty_persen' => $penalty, 'penalty_terbilang' => strtolower(terbilang_persen($penalty)),

    'bunga_tahun' => number_format($bunga, 2, '.', ''), 'bunga_tahun_terbilang' => terbilang_persen($bunga),
    'bunga_bulan' => number_format($bungaPersenBulan, 2, '.', ''), 'bunga_bulan_terbilang' => terbilang_persen($bungaPersenBulan),
    'bunga_nominal' => rp($bungaBulan), 'bunga_nominal_terbilang' => terbilang_rp($bungaBulan),

    'provisi_persen' => $provPct, 'provisi_terbilang' => strtolower(terbilang_persen($provPct)),
    'provisi_nominal' => rp($provNom), 'provisi_nominal_terbilang' => $nol($provNom),
    'admin_persen' => $adminPct, 'admin_terbilang' => strtolower(terbilang_persen($adminPct)),
    'admin_nominal' => rp($adminNom), 'admin_nominal_terbilang' => $nol($adminNom),
    'materai' => rp(pk_num('materai')),

    'angsuran_pokok' => rp($angsuranPokok), 'angsuran_pokok_terbilang' => strtolower(terbilang_rp($angsuranPokok)),
    'angsuran_total' => rp($angsuranTotal), 'angsuran_total_terbilang' => terbilang_rp($angsuranTotal),

    'tgl_angsuran' => $hariAngs, 'tgl_angsuran_terbilang' => ucwords(terbilang($hariAngs)),
    'tgl_angsuran_pertama' => tgl_indo($tglAngs1),

    'denda_persen' => $denda,
    'agunan_deskripsi' => pk_in('agunan'),
];

// ---------- isi template & kirim ----------
Settings::setOutputEscapingEnabled(true); // &, <, > di data tidak merusak file Word

//$tpl = new TemplateProcessor(__DIR__ . '/../template/pk_angsuran_shm.docx');
$tplFile = __DIR__ . '/../../template/pk_angsuran_shm.docx';
if (!is_file($tplFile)) {
    pk_gagal('Template PK tidak ditemukan: ' . $tplFile);
}
$tpl = new TemplateProcessor($tplFile);

foreach ($data as $k => $v) {
    $tpl->setValue($k, (string)$v);
}

$tmp = tempnam(sys_get_temp_dir(), 'pk_');
$tpl->saveAs($tmp);

$nama = 'PK_' . trim(preg_replace('/[^A-Za-z0-9]+/', '_', pk_in('nama')), '_') . '.docx';
header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
header('Content-Disposition: attachment; filename="' . $nama . '"');
header('Content-Length: ' . filesize($tmp));
readfile($tmp);
unlink($tmp);
