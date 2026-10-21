<?php

return [
    'base_url'    => getenv('MON_API_URL')      ?: 'https://kredit.bprdak.co.id',
    'path_token'  => '/api_monitoring/auth/access-token',   // path yang DILIHAT API (bagian setelah domain)
    'path_data'   => '/api_monitoring/Login',               // ganti per endpoint, mis. /api_mobile/Monitoring
    'path_monitoring' => '/api_monitoring/Monitoring',
    'api_key'     => getenv('MON_API_KEY')      ?: 'rgz2CBF917e5oYzm70Pfl2RCry0x37',  // sama dengan jwt_key di API
    'secret_key'  => getenv('MON_API_SECRET')   ?: 'mRC5sOI58f0Zsjx3BJ3ceRmCry0x37',  // sama dengan jwt_secretKey di API
    'hmac_key'    => getenv('MON_API_HMAC')     ?: 'TheKirrCry0x3735632896758',  // kunci HMAC tanda tangan request (jangan 'TheKirrCry0x37' lagi)
    'private_key' => getenv('MON_PRIVATE_PEM')  ?: 'D:/xampp/keys/private.pem',
    'enc_key'     => getenv('MON_ENC_KEY')      ?: 'neXNoT2YpsAQPjGPc2g6NlvCIcApk34Wgvx1O/c39cM=',  // 32 byte base64: openssl rand -base64 32 (sama di API dan hosting)
    'timeout'     => 30,
];
