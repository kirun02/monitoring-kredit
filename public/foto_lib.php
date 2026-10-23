<?php
/**
 * Penyimpanan foto kunjungan di HOSTING.
 * API hanya menyimpan JALUR foto; berkasnya disimpan di sini, di luar folder web (diakses lewat foto.php yang mewajibkan login).
 *
 * config.api.php: 'foto_dir' => 'D:/xampp/storage/kunjungan'   (HARUS di luar htdocs / folder web)
 *
 * Foto yang diunggah TIDAK disimpan apa adanya. Gambar dibaca ulang, diputar sesuai EXIF, diperkecil, lalu ditulis sebagai JPEG
 * baru: semua metadata (EXIF, GPS, nama kamera) terbuang, dan berkas yang bukan gambar sungguhan (mis. skrip PHP berkedok .jpg) ditolak.
 */
const FOTO_MAKS_BYTE   = 12 * 1024 * 1024;   // ukuran unggahan
const FOTO_MAKS_PIKSEL = 16000000;           // ~16 megapiksel (mencegah memori habis saat membuka gambar)
const FOTO_SISI_MAKS   = 1600;               // sisi terpanjang hasil
const FOTO_POLA_JALUR  = '~^\d{6}/u\d{1,5}_[a-f0-9]{16}\.jpg$~';

function foto_dir()
{
    $d = (string)(api_cfg()['foto_dir'] ?? '');
    if ($d === '') throw new RuntimeException("'foto_dir' belum diisi di config.api.php");
    return rtrim(str_replace('\\', '/', $d), '/');
}

function foto_pesan_unggah($kode)
{
    switch ($kode) {
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'Foto terlalu besar. Pilih foto yang lebih kecil.';
        case UPLOAD_ERR_PARTIAL:   return 'Foto hanya terunggah sebagian. Coba lagi.';
        case UPLOAD_ERR_NO_FILE:   return 'Foto kunjungan wajib diisi.';
        default:                   return 'Foto gagal diunggah. Coba lagi.';
    }
}

/** Proses unggahan ($_FILES['foto']) dan simpan. Mengembalikan jalur relatif 'YYYYMM/uID_xxxxxxxxxxxxxxxx.jpg'. */
function foto_simpan(array $f, $idUser)
{
    if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException(foto_pesan_unggah($f['error'] ?? UPLOAD_ERR_NO_FILE));
    if (!is_uploaded_file($f['tmp_name']))                     throw new RuntimeException('Foto tidak valid.');
    if ((int)$f['size'] <= 0 || (int)$f['size'] > FOTO_MAKS_BYTE) throw new RuntimeException('Ukuran foto harus di bawah ' . (FOTO_MAKS_BYTE / 1048576) . ' MB.');

    $info = @getimagesize($f['tmp_name']);
    if (!$info) throw new RuntimeException('Berkas bukan gambar yang valid.');
    list($w, $h, $tipe) = $info;
    if (!in_array($tipe, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Format foto harus JPG, PNG, atau WebP.');
    if ($w < 1 || $h < 1 || $w * $h > FOTO_MAKS_PIKSEL)                          throw new RuntimeException('Dimensi foto terlalu besar. Perkecil foto lalu coba lagi.');

    if ($tipe === IMAGETYPE_JPEG)      $img = @imagecreatefromjpeg($f['tmp_name']);
    elseif ($tipe === IMAGETYPE_PNG)   $img = @imagecreatefrompng($f['tmp_name']);
    else                               $img = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($f['tmp_name']) : false;
    if (!$img) throw new RuntimeException('Foto tidak dapat dibaca.');

    // putar sesuai EXIF (foto dari ponsel sering tersimpan "miring" + penanda orientasi)
    if ($tipe === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($f['tmp_name']);
        $sudut = [3 => 180, 6 => -90, 8 => 90][(int)($ex['Orientation'] ?? 1)] ?? 0;
        if ($sudut) { $r = imagerotate($img, $sudut, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }

    $w = imagesx($img); $h = imagesy($img);
    $skala = min(1, FOTO_SISI_MAKS / max($w, $h));
    $nw = max(1, (int)round($w * $skala)); $nh = max(1, (int)round($h * $skala));
    $out = imagecreatetruecolor($nw, $nh);
    imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));        // PNG transparan -> latar putih
    imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($img);

    $bulan = date('Ym');
    $dir   = foto_dir() . '/' . $bulan;
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Folder penyimpanan foto tidak dapat dibuat.');
    $nama = sprintf('u%d_%s.jpg', (int)$idUser, bin2hex(random_bytes(8)));  // nama acak; nama berkas dari pengguna tidak dipakai
    if (!@imagejpeg($out, $dir . '/' . $nama, 82)) throw new RuntimeException('Foto tidak dapat disimpan.');
    imagedestroy($out);
    @chmod($dir . '/' . $nama, 0640);
    return $bulan . '/' . $nama;
}

function foto_hapus($rel)
{
    if ($rel !== '' && preg_match(FOTO_POLA_JALUR, $rel)) @unlink(foto_dir() . '/' . $rel);
}

/**
 * Jalur berkas di disk bila $rel sah DAN boleh dilihat pemakai ini; selain itu null.
 * AO hanya boleh melihat fotonya sendiri; admin dan pimpinan boleh melihat semua.
 */
function foto_path($rel, $idUser, $role)
{
    if (!preg_match(FOTO_POLA_JALUR, $rel)) return null;
    if ($role === 'ao' && strpos(basename($rel), 'u' . (int)$idUser . '_') !== 0) return null;
    $p = foto_dir() . '/' . $rel;
    return is_file($p) ? $p : null;
}
