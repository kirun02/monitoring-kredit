<?php

return [
    'base_url'          => getenv('MON_API_URL')      ?: 'https://kredit.bprdak.co.id',
    'path_token'        => '/api_monitoring/auth/access-token',  
    'path_data'         => '/api_monitoring/Login',              
    'path_monitoring'   => '/api_monitoring/Monitoring',
    'path_kunjungan'    => '/api_monitoring/kunjungan',
    'path_nasabah'      => '/api_monitoring/data_nasabah',
    'api_key'           => getenv('MON_API_KEY')      ?: 'rgz2CBF917e5oYzm70Pfl2RCry0x37',  // sama dengan jwt_key di API
    'secret_key'        => getenv('MON_API_SECRET')   ?: 'mRC5sOI58f0Zsjx3BJ3ceRmCry0x37',  // sama dengan jwt_secretKey di API
    'hmac_key'          => getenv('MON_API_HMAC')     ?: 'TheKirrCry0x3735632896758',  // kunci HMAC tanda tangan request
    'private_key'       => getenv('MON_PRIVATE_PEM')  ?: 'D:/xampp/keys/private.pem',
    'enc_key'           => getenv('MON_ENC_KEY')      ?: 'neXNoT2YpsAQPjGPc2g6NlvCIcApk34Wgvx1O/c39cM=',  
    'timeout'           => 30,
    'path_upload'       => '/api_monitoring/Upload_nominatif',
    'timeout_upload'    => 300,   // detik; tahap "selesai" membaca Excel dan bisa lama    
];
