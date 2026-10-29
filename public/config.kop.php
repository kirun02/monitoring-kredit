<?php
/**
 * Kop dan logo perusahaan untuk semua laporan cetak (Excel dan PDF). Letak: sejajar dengan export_lib.php.
 *
 * - nama  : baris pertama kop (huruf besar tebal). Wajib diisi, kalau kosong laporan dicetak tanpa kop.
 * - baris : sampai 3 baris keterangan di bawah nama (alamat, telepon, email, dst.).
 * - logo  : jalur berkas di SERVER (bukan URL). PNG atau JPEG, maksimal 1 MB. Kosongkan bila tidak ada logo.
 *           Saran: PNG latar transparan, tinggi sekitar 150-300 piksel.
 *
 * Hapus file ini bila ingin laporan tanpa kop.
 */
return [
    'nama'  => 'PT. BPR DELTA ARTHA KENCANA',                                   // GANTI
    'baris' => [
        'Jl. Raya Kebonagung No. 23, Kec. Pakisaji, Kabupaten Malang',                     // GANTI
        'Telp. (0341)-831831 |  Email: info@bprdeltaarthakencana.com',          // GANTI
    ],
    'logo'  => __DIR__ . '/assets/Logo.png',                             // taruh logo di sini, atau ubah jalurnya
];
