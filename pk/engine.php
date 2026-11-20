<?php
// engine.php - mesin umum cetak PK berbasis template Word (PHP 7.1+).
// Satu jenis PK = satu file di pk/jenis/<slug>.php + satu template di template/pk/<slug>.docx
// File ini DI LUAR folder public. Dipanggil SETELAH lib.php (butuh fungsi h()) oleh public/pk.php dan public/api/export_pk.php.

// Template Word juga di luar public supaya tidak bisa diunduh lewat URL.
// <root project>/pk/engine.php -> <root project>/template/pk
define('PK_TEMPLATE_DIR', dirname(__DIR__) . '/template/pk');

// ---------------------------------------------------------------
// Memuat definisi jenis PK
// ---------------------------------------------------------------
function pk_muat($slug)
{
    if (!is_string($slug) || !preg_match('/^[a-z0-9_]+$/', $slug)) return null;   // cegah path traversal
    $f = __DIR__ . '/jenis/' . $slug . '.php';
    if (!is_file($f)) return null;
    $def = require $f;
    $def['slug'] = $slug;
    return $def;
}

function pk_daftar()
{
    $out = [];
    foreach (glob(__DIR__ . '/jenis/*.php') as $f) {
        $slug = basename($f, '.php');
        $d = pk_muat($slug);
        if ($d) $out[$slug] = $d;
    }
    return $out;
}

function pk_gagal($pesan)
{
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit($pesan);
}

// Bagian "Pihak BANK" yang dipakai bersama semua jenis PK
function pk_bagian_bank()
{
    return ['judul' => 'Pihak BANK', 'field' => [
        ['nama' => 'direktur1_nama',    'label' => 'Nama direktur 1', 'nilai' => 'Joeliar Adiwinarno, Se'],
        ['nama' => 'direktur1_jabatan', 'label' => 'Jabatan 1',       'nilai' => 'Direktur Utama'],
        ['nama' => 'direktur2_nama',    'label' => 'Nama direktur 2', 'nilai' => 'Drs. Ec. Teguh'],
        ['nama' => 'direktur2_jabatan', 'label' => 'Jabatan 2',       'nilai' => 'Direktur'],
    ]];
}

// ---------------------------------------------------------------
// Membuat HTML isian dari definisi field
// Kunci field: nama, label, tipe (text|date|rupiah|angka|desimal|area|pilih|radio),
//   wajib, nilai, maks, min, opsi [nilai=>label], pola, hint, lebar ('full'),
//   muncul_jika ['nama_field'=>'nilai']
// ---------------------------------------------------------------
function pk_field_html(array $f)
{
    $n    = $f['nama'];
    $tipe = isset($f['tipe']) ? $f['tipe'] : 'text';
    $wajib = !empty($f['wajib']);
    $lbl  = h($f['label']) . ($wajib ? ' *' : '');
    $req  = $wajib ? ' required' : '';
    $val  = isset($f['nilai']) ? h((string)$f['nilai']) : '';
    $cls  = 'f' . (!empty($f['lebar']) ? ' full' : '');
    $jika = '';
    if (!empty($f['muncul_jika'])) {
        $p = [];
        foreach ($f['muncul_jika'] as $k => $v) $p[] = $k . '=' . $v;
        $jika = ' data-jika="' . h(implode('&', $p)) . '" hidden';
    }
    $title = isset($f['hint']) ? ' title="' . h($f['hint']) . '"' : '';
    $nm = h($n);

    switch ($tipe) {
        case 'date':
            $in = '<input type="date" name="' . $nm . '"' . $req . $title . '>';
            break;
        case 'rupiah':
            $in = '<input type="text" name="' . $nm . '" class="rp" inputmode="numeric"' . $req . $title . '>';
            break;
        case 'angka':
            $in = '<input type="number" name="' . $nm . '" step="1"'
                . (isset($f['min']) ? ' min="' . (int)$f['min'] . '"' : '')
                . (isset($f['maks']) ? ' max="' . (int)$f['maks'] . '"' : '')
                . ($val !== '' ? ' value="' . $val . '"' : '') . $req . $title . '>';
            break;
        case 'desimal':
            $in = '<input type="text" name="' . $nm . '" inputmode="decimal"'
                . ($val !== '' ? ' value="' . $val . '"' : '') . $req . $title . '>';
            break;
        case 'area':
            $in = '<textarea name="' . $nm . '" maxlength="' . (int)(isset($f['maks']) ? $f['maks'] : 2000) . '"'
                . $req . $title . '>' . $val . '</textarea>';
            break;
        case 'pilih':
            $in = '<select name="' . $nm . '"' . $req . '>';
            foreach ($f['opsi'] as $k => $v) {
                $sel = ((string)$k === (string)(isset($f['nilai']) ? $f['nilai'] : '')) ? ' selected' : '';
                $in .= '<option value="' . h((string)$k) . '"' . $sel . '>' . h($v) . '</option>';
            }
            $in .= '</select>';
            break;
        case 'radio':
            $in = '<div class="rp-opsi">';
            foreach ($f['opsi'] as $k => $v) {
                $chk = ((string)$k === (string)(isset($f['nilai']) ? $f['nilai'] : '')) ? ' checked' : '';
                $in .= '<label><input type="radio" name="' . $nm . '" value="' . h((string)$k) . '"' . $chk . '> ' . h($v) . '</label>';
            }
            $in .= '</div>';
            return '<div class="' . $cls . '"' . $jika . '>' . $lbl . $in . '</div>';
        default: // text
            $in = '<input type="text" name="' . $nm . '" maxlength="' . (int)(isset($f['maks']) ? $f['maks'] : 250) . '"'
                . ($val !== '' ? ' value="' . $val . '"' : '')
                . (isset($f['pola']) ? ' pattern="' . h($f['pola']) . '"' : '') . $req . $title . '>';
    }
    return '<label class="' . $cls . '"' . $jika . '>' . $lbl . $in . '</label>';
}

// ---------------------------------------------------------------
// Membaca & memvalidasi isian POST sesuai definisi -> array nilai bersih
// ---------------------------------------------------------------
function pk_ambil(array $def, array $post)
{
    $in = [];
    foreach ($def['bagian'] as $b) {
        foreach ($b['field'] as $f) {
            $n    = $f['nama'];
            $tipe = isset($f['tipe']) ? $f['tipe'] : 'text';
            $isNum = in_array($tipe, ['rupiah', 'angka', 'desimal'], true);

            // field bersyarat yang tidak aktif -> kosong
            if (!empty($f['muncul_jika'])) {
                $aktif = true;
                foreach ($f['muncul_jika'] as $k => $v) {
                    if (!isset($in[$k]) || (string)$in[$k] !== (string)$v) $aktif = false;
                }
                if (!$aktif) { $in[$n] = $isNum ? 0 : ''; continue; }
            }

            $raw = isset($post[$n]) ? trim((string)$post[$n]) : '';
            if ($raw === '' && $tipe !== 'radio' && $tipe !== 'pilih' && !empty($f['wajib'])) {
                pk_gagal('Wajib diisi: ' . $f['label']);
            }

            switch ($tipe) {
                case 'date':
                    if ($raw !== '') {
                        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                            pk_gagal('Format tanggal salah: ' . $f['label']);
                        }
                    }
                    $in[$n] = $raw;
                    break;
                case 'rupiah':
                    $in[$n] = (int)preg_replace('/\D+/', '', $raw);
                    if (!empty($f['wajib']) && $in[$n] <= 0) pk_gagal('Wajib diisi: ' . $f['label']);
                    break;
                case 'angka':
                    $in[$n] = (int)$raw;
                    if ($raw !== '' && ((isset($f['min']) && $in[$n] < $f['min']) || (isset($f['maks']) && $in[$n] > $f['maks']))) {
                        pk_gagal('Nilai di luar batas: ' . $f['label']);
                    }
                    break;
                case 'desimal':
                    $in[$n] = (float)str_replace(',', '.', $raw);
                    if ($raw !== '' && ((isset($f['min']) && $in[$n] < $f['min']) || (isset($f['maks']) && $in[$n] > $f['maks']))) {
                        pk_gagal('Nilai di luar batas: ' . $f['label']);
                    }
                    break;
                case 'pilih':
                case 'radio':
                    if ($raw === '') $raw = isset($f['nilai']) ? (string)$f['nilai'] : '';
                    if ($raw === '' && !empty($f['wajib'])) pk_gagal('Wajib diisi: ' . $f['label']);
                    if ($raw !== '' && !array_key_exists($raw, $f['opsi'])) pk_gagal('Pilihan tidak valid: ' . $f['label']);
                    $in[$n] = $raw;
                    break;
                default: // text, area
                    $maks = isset($f['maks']) ? (int)$f['maks'] : ($tipe === 'area' ? 2000 : 250);
                    $raw = mb_substr($raw, 0, $maks);
                    if ($raw !== '' && isset($f['pola']) && !preg_match('/^' . $f['pola'] . '$/', $raw)) {
                        pk_gagal('Format salah: ' . $f['label']);
                    }
                    $in[$n] = $raw;
            }
        }
    }
    return $in;
}

// ---------------------------------------------------------------
// Isi template Word & kirim ke browser
// $nilai  : ['placeholder' => 'isi', ...]
// $tabel  : [ ['kunci' => 'no_angsuran', 'baris' => [ ['no_angsuran'=>1,'pokok'=>'..'], ... ]], ... ]
// ---------------------------------------------------------------
function pk_kirim($slug, $templateFile, array $nilai, array $tabel, $namaFile)
{
    require_once __DIR__ . '/../vendor/autoload.php';
    \PhpOffice\PhpWord\Settings::setOutputEscapingEnabled(true);   // &, <, > aman

    $tplPath = PK_TEMPLATE_DIR . '/' . $templateFile;
    if (!is_file($tplPath)) pk_gagal('Template PK tidak ditemukan: ' . $tplPath);

    $tpl = new \PhpOffice\PhpWord\TemplateProcessor($tplPath);

    foreach ($nilai as $k => $v) {
        $tpl->setValue($k, (string)$v);
    }
    foreach ($tabel as $t) {
        $n = count($t['baris']);
        if ($n === 0) { $tpl->deleteRow($t['kunci']); continue; }
        $tpl->cloneRow($t['kunci'], $n);
        foreach ($t['baris'] as $i => $baris) {
            foreach ($baris as $k => $v) {
                $tpl->setValue($k . '#' . ($i + 1), (string)$v);
            }
        }
    }

    $tmp = tempnam(sys_get_temp_dir(), 'pk_');
    $tpl->saveAs($tmp);

    $namaFile = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $namaFile), '_') . '.docx';
    header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    header('Content-Disposition: attachment; filename="' . $namaFile . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

// ---------------------------------------------------------------
// Fungsi bantu terbilang, rupiah, tanggal
// ---------------------------------------------------------------
function pk_tgl($ymd) { return $ymd === '' ? '' : tgl_indo($ymd); }

// helpers.php - fungsi bantu untuk cetak PK (kompatibel PHP 7.1+)

function terbilang($x)
{
    $x = (int)$x;
    $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    if ($x < 12)   return $s[$x];
    if ($x < 20)   return terbilang($x - 10) . ' belas';
    if ($x < 100)  return trim(terbilang(intdiv($x, 10)) . ' puluh ' . terbilang($x % 10));
    if ($x < 200)  return trim('seratus ' . terbilang($x - 100));
    if ($x < 1000) return trim(terbilang(intdiv($x, 100)) . ' ratus ' . terbilang($x % 100));
    if ($x < 2000) return trim('seribu ' . terbilang($x - 1000));
    if ($x < 1000000)       return trim(terbilang(intdiv($x, 1000)) . ' ribu ' . terbilang($x % 1000));
    if ($x < 1000000000)    return trim(terbilang(intdiv($x, 1000000)) . ' juta ' . terbilang($x % 1000000));
    if ($x < 1000000000000) return trim(terbilang(intdiv($x, 1000000000)) . ' miliar ' . terbilang($x % 1000000000));
    return trim(terbilang(intdiv($x, 1000000000000)) . ' triliun ' . terbilang($x % 1000000000000));
}

// 40000000 -> "Empat Puluh Juta Rupiah"
function terbilang_rp($n)
{
    $n = (int)round($n);
    $t = $n === 0 ? 'nol' : terbilang($n);
    return ucwords($t) . ' Rupiah';
}

// 18 -> "Delapan Belas", 1.5 -> "Satu Koma Lima Puluh" (mengikuti gaya PK lama)
function terbilang_persen($p)
{
    $parts = explode('.', number_format((float)$p, 2, '.', ''));
    $t = $parts[0] === '0' ? 'nol' : terbilang((int)$parts[0]);
    if ((int)$parts[1] > 0) {
        $t .= ' koma ' . terbilang((int)$parts[1]);
    }
    return ucwords($t);
}

// 40000000 -> "40.000.000"
function rp($n)
{
    return number_format((float)$n, 0, ',', '.');
}

// "2016-09-29" -> "29 September 2016"
function tgl_indo($ymd)
{
    $bulan = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli',
              'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    $d = new DateTime($ymd);
    return $d->format('j') . ' ' . $bulan[(int)$d->format('n')] . ' ' . $d->format('Y');
}

// "2016-09-29" -> "Kamis"
function hari_indo($ymd)
{
    $h = [1 => 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu', 'Minggu'];
    return $h[(int)(new DateTime($ymd))->format('N')];
}
