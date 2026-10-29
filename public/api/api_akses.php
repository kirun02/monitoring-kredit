<?php
/**
 * Proxy hak akses menu untuk halaman akses.php. TIDAK menyentuh database: semuanya lewat API backend (terenkripsi, endpoint Login).
 *
 *   GET  ?jenis=pengguna               -> {rows:[{id, username, nama, role}]}
 *   GET  ?jenis=menu&user_id=N         -> {menus:[...semua menu aktif], granted:[id menu yang sudah diberikan]}
 *   POST jenis=simpan user_id menu_ids[] -> ganti seluruh hak akses pengguna tersebut
 *
 * Perubahan berlaku saat pengguna yang bersangkutan masuk ulang.
 */
require __DIR__ . '/../lib.php';           // wajib login + CSRF untuk POST
require_once __DIR__ . '/api_client.php';
akses_atau_403('web_akses');

function keluar($data, $kode = 200)
{
    http_response_code($kode);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
    exit;
}

$idSaya = (int)((auth_user() ?? [])['id'] ?? 0);
$post   = $_SERVER['REQUEST_METHOD'] === 'POST';
$jenis  = (string)($post ? ($_POST['jenis'] ?? '') : ($_GET['jenis'] ?? ''));

try {
    // ---------- daftar pengguna ----------
    if (!$post && $jenis === 'pengguna') {
        //$r = api_call('daftar', [], api_cfg()['path_pengguna']);
        $r = api_call('daftar', [], api_cfg()['path_pengguna']);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => 'Daftar pengguna tidak dapat dimuat.'], 502);
        $rows = [];
        foreach (($r['rows'] ?? []) as $x) {
            $rows[] = ['id' => (int)$x['id'], 'username' => (string)($x['username'] ?? ''), 'nama' => (string)($x['nama'] ?? ''), 'role' => (string)($x['role'] ?? '')];
        }
        keluar(['rows' => $rows]);
    }

    // ---------- menu + hak akses seorang pengguna ----------
    if (!$post && $jenis === 'menu') {
        $uid = (int)($_GET['user_id'] ?? 0);
        if ($uid <= 0) keluar(['error' => 'Pengguna tidak valid.'], 422);
        $r = api_call('akses_menu', ['user_id' => $uid]);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Hak akses tidak dapat dimuat.')], 502);
        $menus = [];
        foreach (($r['menus'] ?? []) as $m) {
            $menus[] = [
                'id'        => (int)$m['id'],
                'parent_id' => (int)($m['parent_id'] ?? 0),
                'kode'      => (string)($m['kode'] ?? ''),
                'label'     => (string)($m['label'] ?? ''),
                'url'       => (string)($m['url'] ?? ''),
            ];
        }
        keluar(['menus' => $menus, 'granted' => array_map('intval', $r['granted'] ?? [])]);
    }

    // ---------- simpan ----------
    if ($post && $jenis === 'simpan') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $ids = array_values(array_unique(array_map('intval', (array)($_POST['menu_ids'] ?? []))));
        if ($uid <= 0) keluar(['error' => 'Pilih pengguna terlebih dahulu.'], 422);

        // jangan sampai admin mencabut akses halaman Hak Akses dari akunnya sendiri (tidak ada yang bisa memulihkan)
        if ($uid === $idSaya) {
            $m = api_call('akses_menu', ['user_id' => $uid]);
            $idAkses = 0;
            foreach (($m['menus'] ?? []) as $x) if (($x['kode'] ?? '') === 'web_akses') $idAkses = (int)$x['id'];
            if ($idAkses > 0 && !in_array($idAkses, $ids, true)) {
                keluar(['error' => 'Kamu tidak boleh mencabut akses menu Hak Akses dari akunmu sendiri.'], 422);
            }
        }

        $r = api_call('akses_simpan', ['user_id' => $uid, 'menu_ids' => $ids]);
        if (($r['status'] ?? '') !== 'success') keluar(['error' => (string)($r['message'] ?? 'Hak akses gagal disimpan.')], 422);
        keluar(['message' => (string)($r['message'] ?? 'Hak akses disimpan.') . ' Berlaku saat pengguna masuk ulang.']);
    }

    keluar(['error' => 'Permintaan tidak dikenal.'], 400);
} catch (Throwable $e) {
    error_log('api_akses: ' . get_class($e) . ': ' . substr($e->getMessage(), 0, 200));
    keluar(['error' => 'Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.'], 502);
}
