<?php
header('Content-Type: text/plain; charset=utf-8');
session_start();
require __DIR__ . '/api_client.php';

echo "1) Ambil token...\n";
try {
    $t = api_token(true);
    echo "   OK (token diterima, panjang " . strlen($t) . ")\n";
} catch (Throwable $e) {
    exit('   GAGAL: ' . $e->getMessage() . "\n");
}

echo "2) Panggil endpoint login (user palsu)...\n";
try {
    $r = api_call('login', ['username' => 'tes_tidak_ada', 'password' => 'x']);
    echo '   OK, API membalas status: ' . ($r['status'] ?? '?') . "\n";
} catch (Throwable $e) {
    echo '   GAGAL: ' . $e->getMessage() . "\n";
}