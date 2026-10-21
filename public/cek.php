<?php
header('Content-Type: text/plain');
$c = require __DIR__ . '/config.api.php';
foreach (['base_url','api_key','secret_key','hmac_key','private_key','enc_key'] as $k) {
    echo str_pad($k, 12) . ': ' . ($c[$k] === '' ? 'KOSONG' : 'terisi (panjang ' . strlen($c[$k]) . ')') . "\n";
}
echo 'file private key ada: ' . (is_readable($c['private_key']) ? 'ya' : 'TIDAK') . "\n";


echo 'path: [' . $c['private_key'] . "]\n";
echo 'ada: ' . (file_exists($c['private_key']) ? 'ya' : 'tidak') . ' | bisa dibaca: ' . (is_readable($c['private_key']) ? 'ya' : 'tidak') . "\n";
echo 'open_basedir: ' . (ini_get('open_basedir') ?: '(tidak dibatasi)') . "\n";