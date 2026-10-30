<?php
/**
 * Proxy rencana kunjungan untuk halaman rencana.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi).
 * Letak: folder api/
 *
 *   GET  ?jenis=daftar&tgl1=&tgl2=&status=&id_user=   -> {rows:[...], rekap:{total,belum,monitoring,dikunjungi}, terpotong}
 *   GET  ?jenis=daftar_user                           -> {data:[{id,nama,tipe}]}   (admin/pimpinan; AO dapat daftar kosong)
 *   GET  ?jenis=template                              -> berkas Excel contoh untuk unggahan
 *   POST jenis=insert (multipart)                     -> tambah rencana (tanggal = hari ini)
 *   POST jenis=upload (multipart, file .xlsx)         -> unggah rencana massal
 *
 * AO selalu dipaksa memakai akunnya sendiri (id dari sesi, bukan dari browser); admin/pimpinan boleh memilih AO.
 */
require __DIR__ . '/../lib.php';           // wajib login + CSRF untuk POST
require_once __DIR__ . '/api_client.php';
require_once __DIR__ . '/../export_lib.php';
date_default_timezone_set('Asia/Jakarta');
if (function_exists('akses_atau_403')) akses_atau_403('web_rencana');   // hak akses menu (bila paket hak akses terpasang)

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

function tgl_valid($v)
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', (string)$v, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/** AO selalu dirinya sendiri; admin/pimpinan memilih (0 = belum dipilih / semua). */
function id_pilihan($ao, $idUser, $nilai)
{
    return $ao ? $idUser : max(0, (int)$nilai);
}

/** Tanggal dari sel Excel: angka seri Excel, 'yyyy-mm-dd', atau 'dd/mm/yyyy'. '' bila tidak valid. */
function baca_tanggal($v)
{
    if ($v === null || $v === '') return '';
    if (is_numeric($v)) {
        $ts = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToTimestamp((float)$v);
        $t  = date('Y-m-d', $ts);
    } else {
        $s = trim((string)$v);
        if (preg_match('~^(\d{4})-(\d{1,2})-(\d{1,2})$~', $s, $m))                    $t = sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
        elseif (preg_match('~^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})$~', $s, $m))     $t = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        else return '';
    }
    $y = (int)substr($t, 0, 4);
    return (tgl_valid($t) && $y >= 2000 && $y <= 2100) ? $t : '';
}

/** Nomor rekening dari sel Excel (angka besar tidak boleh jadi notasi ilmiah). */
function baca_rekening($v)
{
    if ($v === null) return '';
    if (is_int($v) || is_float($v)) return sprintf('%.0f', $v);
    return trim((string)$v);
}

$u      = auth_user() ?? [];
$idUser = (int)($u['id'] ?? ($u['id_user'] ?? 0));
$ao     = (string)($u['staff'] ?? '') === 'ao';
if ($idUser < 1) keluar(['error' => 'Akun tidak valid. Silakan masuk ulang.'], 403);

$cfg  = api_cfg();
$path = (string)($cfg['path_rencana'] ?? '');
if ($path === '') keluar(['error' => 'path_rencana belum diatur di config.api.php.'], 500);

$post  = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));
$STATUS = ['belum', 'monitoring whatsapp', 'dikunjungi'];

try {
    // ---------- daftar + rekap ----------
    if (!$post && $jenis === 'daftar') {
        $tgl1 = (string)($_GET['tgl1'] ?? date('Y-m-01'));
        $tgl2 = (string)($_GET['tgl2'] ?? date('Y-m-d'));
        if (!tgl_valid($tgl1) || !tgl_valid($tgl2) || $tgl1 > $tgl2) keluar(['error' => 'Rentang tanggal tidak valid.'], 422);
        if (strtotime($tgl2) - strtotime($tgl1) > 92 * 86400)         keluar(['error' => 'Rentang tanggal maksimal 3 bulan.'], 422);
        $status  = in_array((string)($_GET['status'] ?? ''), $STATUS, true) ? (string)$_GET['status'] : '';
        $idPilih = id_pilihan($ao, $idUser, $_GET['id_user'] ?? 0);

        $r = api_call('preview', ['tgl1' => $tgl1, 'tgl2' => $tgl2, 'id_user' => $idPilih > 0 ? (string)$idPilih : '', 'status' => $status], $path);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Daftar tidak dapat dimuat.')], 502);

        $semua = $r['rows'] ?? [];
        $rekap = ['total' => count($semua), 'belum' => 0, 'monitoring' => 0, 'dikunjungi' => 0];
        foreach ($semua as $x) {
            $s = (string)($x['status'] ?? '');
            if ($s === 'belum') $rekap['belum']++;
            elseif ($s === 'monitoring whatsapp') $rekap['monitoring']++;
            elseif ($s === 'dikunjungi') $rekap['dikunjungi']++;
        }
        $batas = 3000;
        $rows  = [];
        foreach (array_slice($semua, 0, $batas) as $x) {
            $rows[] = [
                'tanggal'         => (string)($x['tanggal'] ?? ''),
                'rekening'        => (string)($x['rekening'] ?? ''),
                'nama'            => (string)($x['nama'] ?? ''),
                'nmuser'          => (string)($x['nmuser'] ?? ''),
                'status'          => (string)($x['status'] ?? ''),
                'keterangan'      => (string)($x['keterangan'] ?? ''),
                'waktu_kunjungan' => (string)($x['waktu_kunjungan'] ?? ''),
                'ket_kunjungan'   => (string)($x['ket_kunjungan'] ?? ''),
            ];
        }
        keluar(['rows' => $rows, 'rekap' => $rekap, 'terpotong' => count($semua) > $batas]);
    }

    // ---------- daftar AO (pilihan untuk admin/pimpinan) ----------
    if (!$post && $jenis === 'daftar_user') {
        if ($ao) keluar(['data' => []]);
        $r = api_call('daftar_user', [], $path);
        if (($r['status'] ?? '') !== 'success') keluar(['data' => []]);
        $out = [];
        foreach (($r['data'] ?? []) as $x) {
            $out[] = ['id' => (int)$x['id'], 'nama' => (string)($x['nmuser'] ?? ''), 'tipe' => (string)($x['staff_type'] ?? ($x['role'] ?? ''))];
        }
        keluar(['data' => $out]);
    }

    // ---------- template Excel ----------
    if (!$post && $jenis === 'template') {
        export_siap();
        $ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $ws = $ss->getActiveSheet();
        $ws->setTitle('Rencana');
        $ws->fromArray(['Tanggal', 'Rekening', 'Nama', 'Alamat', 'Keterangan'], null, 'A1');
        $ws->setCellValueExplicit('A2', \PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel(time()), \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_NUMERIC);
        $ws->getStyle('A2')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $ws->setCellValueExplicit('B2', '1234567890', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('C2', 'Contoh Nama Debitur', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('D2', 'Alamat debitur', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->setCellValueExplicit('E2', 'Tagih angsuran bulan ini', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        $ws->getStyle('A1:E1')->getFont()->setBold(true);
        foreach (['A' => 13, 'B' => 18, 'C' => 28, 'D' => 36, 'E' => 36] as $c => $w) $ws->getColumnDimension($c)->setWidth($w);
        while (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_rencana_kunjungan.xlsx"');
        header('Cache-Control: no-store');
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($ss))->save('php://output');
        exit;
    }

    // ---------- tambah rencana ----------
    if ($post && $jenis === 'insert') {
        $rek    = trim((string)($_POST['rekening'] ?? ''));
        $nama   = trim((string)($_POST['nama'] ?? ''));
        $alamat = trim((string)($_POST['alamat'] ?? ''));
        $ket    = trim((string)($_POST['keterangan'] ?? ''));
        $target = id_pilihan($ao, $idUser, $_POST['id_user'] ?? 0);

        if ($target < 1)                                      keluar(['error' => 'Pilih AO untuk rencana ini.'], 422);
        if (!preg_match('/^[0-9A-Za-z]{1,15}$/', $rek))       keluar(['error' => 'Nomor rekening harus berupa huruf atau angka, maksimal 15 karakter.'], 422);
        if ($nama === '' || mb_strlen($nama) > 100)           keluar(['error' => 'Nama debitur wajib diisi (maksimal 100 karakter).'], 422);
        if (mb_strlen($alamat) > 500 || mb_strlen($ket) > 500) keluar(['error' => 'Alamat dan keterangan maksimal 500 karakter.'], 422);

        $r = api_call('insert', ['rekening' => $rek, 'nama' => $nama, 'alamat' => $alamat, 'keterangan' => $ket, 'id_user' => $target], $path);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Gagal menyimpan rencana.')], 422);
        keluar(['message' => 'Rencana kunjungan hari ini disimpan.']);
    }

    // ---------- unggah Excel ----------
    if ($post && $jenis === 'upload') {
        @ini_set('memory_limit', '256M');
        $target = id_pilihan($ao, $idUser, $_POST['id_user'] ?? 0);
        if ($target < 1) keluar(['error' => 'Pilih AO untuk rencana ini.'], 422);

        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) keluar(['error' => 'Pilih berkas Excel (.xlsx) terlebih dahulu.'], 422);
        if (($f['size'] ?? 0) > 2 * 1048576)                              keluar(['error' => 'Ukuran berkas maksimal 2 MB.'], 422);
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx' || !is_uploaded_file($f['tmp_name'])) {
            keluar(['error' => 'Berkas harus berformat .xlsx. Gunakan template yang disediakan.'], 422);
        }

        export_siap();
        try {
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx');   // hanya Xlsx, tanpa pendeteksi format otomatis
            $reader->setReadDataOnly(true);
            $tabel  = $reader->load($f['tmp_name'])->getSheet(0)->toArray(null, false, false, false);
        } catch (Throwable $e) {
            keluar(['error' => 'Berkas tidak bisa dibaca. Pastikan berformat .xlsx dan sesuai template.'], 422);
        }

        $idx = [];
        foreach (($tabel[0] ?? []) as $i => $h) {
            $k = strtolower(trim((string)$h));
            if (in_array($k, ['tanggal', 'rekening', 'nama', 'alamat', 'keterangan'], true)) $idx[$k] = $i;
        }
        if (!isset($idx['tanggal'], $idx['rekening'])) {
            keluar(['error' => 'Baris pertama harus berisi judul kolom: Tanggal, Rekening, Nama, Alamat, Keterangan. Unduh template.'], 422);
        }

        $ok = [];
        $galat = [];
        $jumlah = 0;
        for ($i = 1, $n = count($tabel); $i < $n; $i++) {
            $b = $tabel[$i];
            $ambil = function ($k) use ($b, $idx) { return isset($idx[$k]) ? ($b[$idx[$k]] ?? null) : null; };
            $rek = baca_rekening($ambil('rekening'));
            $tglRaw = $ambil('tanggal');
            if ($rek === '' && ($tglRaw === null || $tglRaw === '')) continue;      // baris kosong
            if (++$jumlah > 500) keluar(['error' => 'Maksimal 500 baris per unggahan. Pecah berkasnya.'], 413);

            $tgl = baca_tanggal($tglRaw);
            if ($tgl === '')                                  { $galat[] = 'Baris ' . ($i + 1) . ': tanggal tidak valid'; continue; }
            if (!preg_match('/^[0-9A-Za-z]{1,15}$/', $rek))   { $galat[] = 'Baris ' . ($i + 1) . ': rekening tidak valid'; continue; }
            $ok[] = [
                'tanggal'    => $tgl,
                'rekening'   => $rek,
                'nama'       => mb_substr(trim((string)$ambil('nama')), 0, 100),
                'alamat'     => mb_substr(trim((string)$ambil('alamat')), 0, 500),
                'keterangan' => mb_substr(trim((string)$ambil('keterangan')), 0, 500),
            ];
        }
        if (!$ok) keluar(['error' => $jumlah ? 'Tidak ada baris yang valid. ' . implode('; ', array_slice($galat, 0, 3)) : 'Berkas tidak berisi data.'], 422);

        $r = api_call('upload_excel', ['id_user' => $target, 'data' => $ok], $path);
        if (!in_array((string)($r['status'] ?? ''), ['ok', 'success'], true)) keluar(['error' => (string)($r['message'] ?? 'Unggahan gagal diproses.')], 422);

        keluar([
            'message'  => 'Unggahan selesai.',
            'inserted' => (int)($r['inserted'] ?? 0),
            'skipped'  => (int)($r['skipped'] ?? 0) + count($galat),
            'updated'  => (int)($r['updated_rencana'] ?? 0),
            'galat'    => array_slice($galat, 0, 5),
        ]);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('api_rencana: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
