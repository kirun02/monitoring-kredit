<?php
// Membuat file Word PK dari template. Dipanggil lewat POST dari pk.php. Data TIDAK disimpan di server.
// lib.php sudah: wajib login + csrf_check() untuk setiap POST, jadi tidak dicek ulang di sini.
require __DIR__ . '/../lib.php';
require __DIR__ . '/../../pk/engine.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pk_gagal('Metode tidak diizinkan.');

$def = pk_muat(isset($_POST['jenis']) ? $_POST['jenis'] : '');
if (!$def) pk_gagal('Jenis PK tidak ditemukan.');

$in    = pk_ambil($def, $_POST);                 // isian bersih sesuai definisi
$hasil = $def['hitung']($in);                    // placeholder untuk template
$tabel = isset($hasil['_tabel']) ? $hasil['_tabel'] : [];
unset($hasil['_tabel']);

$nama = isset($def['nama_file']) ? $def['nama_file']($in) : 'PK_' . $def['slug'];
pk_kirim($def['slug'], $def['template'], $hasil, $tabel, $nama);
