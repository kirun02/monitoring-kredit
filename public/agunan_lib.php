<?php
/**
 * Pendukung upload agunan di HOSTING (sisi klien). Letak: folder yang sama dengan export_lib.php. Dipakai api/api_agunan_upload.php.
 * Hosting membaca Excel (.xlsx) lalu mengirim baris-barisnya (terenkripsi) ke API backend (Agunan.php, jenis_req agunan_upload).
 *
 *   agn_baca_xlsx()  pembaca .xlsx sheet pertama tanpa library (ZipArchive + XMLReader), satu baris per iterasi, hemat memori
 *   agn_muat()       baca file, cari baris judul, susun baris kiriman (+ _baris), buang duplikat (norek + kodereg: yang terakhir dipakai)
 *   agn_kirim()      kirim ke API per AGN_CHUNK baris, gabungkan hasilnya
 * Butuh ekstensi PHP: zip dan xmlreader (di XAMPP: extension=zip di php.ini).
 */

const AGN_CHUNK       = 2000;       // baris per kiriman ke API (batas API 5.000)
const AGN_MAKS_FILE   = 100000;     // baris data maksimal per file
const AGN_MAKS_GAGAL  = 200;        // daftar baris gagal yang dikembalikan ke halaman
const AGN_KOLOM       = ['account', 'kodereg', 'pmilik', 'rincian', 'n_bank', 'n_ht', 'n_njop', 'tgl_penilaian', 'alamat_agunan', 'jnsikat'];

function agn_kolom_ke_indeks(string $ref): int
{
    preg_match('/^[A-Za-z]+/', $ref, $m);
    $s = strtoupper($m[0] ?? 'A');
    $n = 0;
    for ($i = 0, $l = strlen($s); $i < $l; $i++) $n = $n * 26 + (ord($s[$i]) - 64);
    return $n - 1;
}

/** Angka berbentuk ilmiah (1.5E+7) dikembalikan ke digit penuh; lainnya apa adanya. */
function agn_sel_angka(string $v): string
{
    if (preg_match('/^-?\d+(\.\d+)?[eE][+-]?\d+$/', $v)) return rtrim(rtrim(sprintf('%.6F', (float)$v), '0'), '.');
    return $v;
}

/** Generator: tiap iterasi menghasilkan [nomorBarisExcel => [indeksKolom => teks]]. */
function agn_baca_xlsx(string $path)
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('ekstensi PHP zip belum aktif (aktifkan extension=zip di php.ini)');
    if (!class_exists('XMLReader'))  throw new RuntimeException('ekstensi PHP xmlreader belum aktif');

    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('file bukan .xlsx yang valid');

    // lokasi sheet pertama
    $lokasi = 'xl/worksheets/sheet1.xml';
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb !== false && $rels !== false) {
        $x = @simplexml_load_string($wb);
        $r = @simplexml_load_string($rels);
        if ($x && $r && isset($x->sheets->sheet[0])) {
            $rid = (string)$x->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            foreach ($r->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $t = (string)$rel['Target'];
                    $lokasi = ($t !== '' && $t[0] === '/') ? ltrim($t, '/') : 'xl/' . $t;
                    break;
                }
            }
        }
    }
    if ($zip->locateName($lokasi) === false) throw new RuntimeException('sheet pertama tidak ditemukan di file');
    $stat = $zip->statName($lokasi);
    if ($stat && $stat['size'] > 800 * 1024 * 1024) throw new RuntimeException('isi sheet terlalu besar');

    // tabel teks bersama
    $ss = [];
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml !== false) {
        $z = new XMLReader();
        $z->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT);
        $cur = null;
        while ($z->read()) {
            if ($z->nodeType === XMLReader::ELEMENT) {
                if ($z->localName === 'si') { if ($z->isEmptyElement) { $ss[] = ''; } else { $cur = ''; } }
                elseif ($z->localName === 't' && $cur !== null) { $cur .= $z->readString(); }
            } elseif ($z->nodeType === XMLReader::END_ELEMENT && $z->localName === 'si' && $cur !== null) {
                $ss[] = $cur;
                $cur  = null;
            }
        }
        $z->close();
        unset($xml);
    }

    // sheet dibaca bertahap
    $z = new XMLReader();
    if (!@$z->open('zip://' . $path . '#' . $lokasi, null, LIBXML_NONET | LIBXML_COMPACT)) {
        $data = $zip->getFromName($lokasi);
        if ($data === false) throw new RuntimeException('sheet tidak bisa dibaca');
        $z->XML($data, null, LIBXML_NONET | LIBXML_COMPACT);
        unset($data);
    }

    $row = null; $no = 0; $col = 0; $tipe = ''; $val = null; $dalamC = false;
    while ($z->read()) {
        if ($z->nodeType === XMLReader::ELEMENT) {
            $n = $z->localName;
            if ($n === 'row') {
                $row = [];
                $r = $z->getAttribute('r');
                $no = ($r !== null && $r !== '') ? (int)$r : $no + 1;
            } elseif ($n === 'c') {
                $col    = agn_kolom_ke_indeks((string)$z->getAttribute('r'));
                $tipe   = (string)$z->getAttribute('t');
                $val    = null;
                $dalamC = !$z->isEmptyElement;
            } elseif ($n === 'v' && $dalamC) {
                $val = $z->readString();
            } elseif ($n === 't' && $dalamC) {                 // inlineStr
                $val = ($val ?? '') . $z->readString();
            }
        } elseif ($z->nodeType === XMLReader::END_ELEMENT) {
            $n = $z->localName;
            if ($n === 'c' && $row !== null) {
                if ($val !== null) {
                    if ($tipe === 's')                  $val = $ss[(int)$val] ?? '';
                    elseif ($tipe === 'b')              $val = ($val === '1') ? 'TRUE' : 'FALSE';
                    elseif ($tipe === 'e')              $val = null;                  // sel galat (#N/A dsb.)
                    elseif ($tipe === '' || $tipe === 'n') $val = agn_sel_angka($val);
                    if ($val !== null) $row[$col] = $val;
                }
                $dalamC = false;
            } elseif ($n === 'row' && $row !== null) {
                yield $no => $row;
                $row = null;
            }
        }
    }
    $z->close();
    $zip->close();
}

/**
 * Baca file, kembalikan ['rows' => [...baris kiriman...], 'baris_file' => n, 'duplikat' => n, 'kolom_tidak_ada' => [...]].
 * Baris judul dicari di 20 baris pertama (harus memuat kolom account dan kodereg). Nama kolom tidak membedakan huruf besar/kecil.
 * Kolom lain di Excel diabaikan. Bila $abaikanKosong = false, SEMUA kolom harus ada (kolom yang hilang akan mengosongkan data lama).
 */
function agn_muat(string $path, bool $abaikanKosong): array
{
    $idx = null; $rows = []; $total = 0; $dup = 0; $hilang = [];
    foreach (agn_baca_xlsx($path) as $no => $cells) {
        if ($idx === null) {
            if ($no > 20) break;
            $peta = [];
            foreach ($cells as $i => $teks) {
                $k = strtolower(trim((string)$teks));
                if (in_array($k, AGN_KOLOM, true) && !isset($peta[$i]) && !in_array($k, $peta, true)) $peta[$i] = $k;
            }
            if (!in_array('account', $peta, true) || !in_array('kodereg', $peta, true)) continue;   // bukan baris judul
            $idx = $peta;
            $hilang = array_values(array_diff(AGN_KOLOM, $idx));
            if ($hilang && !$abaikanKosong) {
                throw new RuntimeException('kolom tidak ada di file: ' . implode(', ', $hilang)
                    . '. Lengkapi kolomnya, atau centang "sel kosong tidak menimpa data lama" bila memang hanya sebagian kolom yang diperbarui.');
            }
            continue;
        }
        $r = ['_baris' => $no];
        $isi = false;
        foreach ($idx as $i => $nama) {
            $v = isset($cells[$i]) ? trim((string)$cells[$i]) : '';
            $r[$nama] = $v === '' ? null : $v;
            if ($v !== '') $isi = true;
        }
        if (!$isi) continue;                                                   // baris kosong
        if (++$total > AGN_MAKS_FILE) throw new RuntimeException('file terlalu besar (maksimal ' . number_format(AGN_MAKS_FILE, 0, ',', '.') . ' baris data)');
        $key = ($r['account'] !== null && $r['kodereg'] !== null) ? $r['account'] . "\x1F" . $r['kodereg'] : '#' . $no;
        if (isset($rows[$key])) { $dup++; unset($rows[$key]); }                // norek + kodereg sama: baris yang terakhir dipakai
        $rows[$key] = $r;
    }
    if ($idx === null) throw new RuntimeException('baris judul tidak ditemukan (harus ada kolom account dan kodereg di 20 baris pertama)');
    if (!$rows) throw new RuntimeException('tidak ada baris data di bawah baris judul');
    return ['rows' => array_values($rows), 'baris_file' => $total, 'duplikat' => $dup, 'kolom_tidak_ada' => $hilang];
}

/** Kirim ke API per AGN_CHUNK baris dan gabungkan hasilnya. $dry = uji coba (API membatalkan transaksi). Galat: melempar RuntimeException berpesan aman ditampilkan. */
function agn_kirim(array $rows, int $uid, bool $dry, bool $abaikanKosong): array
{
    $path = (string)(api_cfg()['path_agunan'] ?? '');
    if ($path === '') throw new RuntimeException("'path_agunan' belum diisi di config.api.php");

    $h = ['kiriman' => 0, 'valid' => 0, 'baru' => 0, 'diperbarui' => 0, 'tidak_berubah' => 0, 'duplikat_api' => 0, 'dipotong' => 0, 'gagal_n' => 0, 'gagal' => []];
    $params = ['uid' => $uid, 'dry_run' => $dry ? '1' : '0', 'abaikan_kosong' => $abaikanKosong ? '1' : '0'];
    $chunks = array_chunk($rows, AGN_CHUNK);
    foreach ($chunks as $i => $chunk) {
        $r = api_call('agunan_upload', ['params' => $params, 'rows' => $chunk], $path, 180);
        if (($r['status'] ?? '') !== 'success') {
            throw new RuntimeException('API tidak merespons pada kiriman ' . ($i + 1) . ' dari ' . count($chunks) . '. ' . ($dry ? '' : 'Kiriman sebelumnya (' . $h['kiriman'] . ') sudah tersimpan.'));
        }
        $d = (array)($r['data'] ?? []);
        if (!empty($d['error'])) {
            throw new RuntimeException((string)$d['error'] . (!$dry && $h['kiriman'] > 0 ? ' Kiriman sebelumnya (' . $h['kiriman'] . ') sudah tersimpan.' : ''));
        }
        $h['kiriman']++;
        foreach (['valid', 'baru', 'diperbarui', 'tidak_berubah', 'dipotong', 'gagal_n'] as $k) $h[$k] += (int)($d[$k] ?? 0);
        $h['duplikat_api'] += (int)($d['duplikat_dalam_file'] ?? 0);
        foreach ((array)($d['gagal'] ?? []) as $g) if (count($h['gagal']) < AGN_MAKS_GAGAL) $h['gagal'][] = $g;
    }
    return $h;
}
