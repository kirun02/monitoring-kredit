<?php
require_once __DIR__ . '/../vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/../../../env');
$dotenv->load();

return [
    'base_url'          => $_ENV['MON_API_URL'],
    'path_token'        => '/api_monitoring/auth/access-token',  
    'path_data'         => '/api_monitoring/Login',              
    'path_monitoring'   => '/api_monitoring/Monitoring',
    'path_kunjungan'    => '/api_monitoring/kunjungan',
    'path_nasabah'      => '/api_monitoring/data_nasabah',
    'path_absensi'      => '/api_monitoring/absensi',
    'path_karyawan'     => '/api_monitoring/karyawan',
    'path_pengguna'     => '/api_monitoring/pengguna',
    'path_rencana'      => '/api_monitoring/rencana',
    'api_key'           => $_ENV['MON_API_KEY'],
    'secret_key'        => $_ENV['MON_API_SECRET'],
    'hmac_key'          => $_ENV['MON_API_HMAC'],
    'private_key'       => $_ENV['MON_PRIVATE_PEM'],
    'enc_key'           => $_ENV['MON_ENC_KEY'],  
    'timeout'           => 30,
    'path_upload'       => '/api_monitoring/Upload_nominatif',
    'timeout_upload'    => 300,   // detik; tahap "selesai" membaca Excel dan bisa lama 
    'foto_dir'          => $_ENV['MON_ENC_FOTOK'],
];
