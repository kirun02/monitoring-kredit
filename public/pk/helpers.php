<?php
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
