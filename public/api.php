<?php
// Endpoint data dashboard: diteruskan ke API backend (tidak menyentuh database).
require __DIR__ . '/lib.php';
require_once __DIR__ . '/api/api_client.php';
api_proxy('dashboard', ['banding', 'tgl']);