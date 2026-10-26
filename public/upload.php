<?php
// HOSTING: tidak menyentuh database. File .xlsx dipotong-potong dan dikirim ke API backend
// (terenkripsi, bertanda tangan, sama seperti login dan dashboard); API yang membaca Excel dan menyimpan ke database.
require __DIR__ . '/lib.php';
require_once __DIR__ . '/api/api_client.php';

const UPLOAD_BAGIAN = 1572864;   // 1,5 MB per kiriman ke API

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
$data = ($_GET['data'] ?? '') === '1';

/** Selesai: balas JSON untuk halaman (ok = true/false, msg = teks untuk pengguna). */
function done(string $cat, string $msg): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => $cat === 'ok', 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Panggil API lalu rapikan hasilnya: [berhasil?, pesan, data]. */
function api_aksi(string $jenis, array $isi): array {
    $r = api_call($jenis, $isi);
    if (($r['status'] ?? '') === 'success') {
        return [true, (string)($r['data']['msg'] ?? ''), (array)($r['data'] ?? [])];
    }
    return [false, (string)($r['message'] ?? 'Gagal.'), []];
}

// ---------- hanya admin yang boleh mengunggah atau mengubah data ----------
if ((string)(auth_user()['staff'] ?? '') !== 'admin') {
    http_response_code(403);
    if ($ajax || $data) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'msg' => 'Hanya admin yang boleh mengunggah nominatif.', 'data' => []]);
    } else {
        echo 'Halaman ini hanya untuk admin.';
    }
    exit;
}

// ---------- data riwayat untuk DataTables ----------
if ($data) {
    api_proxy('upload_riwayat', []);          // API membalas {"data":[...]}; api_proxy meneruskannya apa adanya
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? 'upload';
    try {
        if ($act === 'baseline' || $act === 'hapus') {
            [$ok, $pesan] = api_aksi('upload_' . $act, ['id' => (int)($_POST['id'] ?? 0)]);
            done($ok ? 'ok' : 'err', $pesan);
        }

        // ---------- upload file ----------
        $tgl = (string)($_POST['tgl_posisi'] ?? ''); $jenis = (string)($_POST['jenis'] ?? ''); $periode = (string)($_POST['periode'] ?? '');
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !$tgl || !$periode || !in_array($jenis, ['harian', 'baseline'], true)) {
            done('err', 'Lengkapi file, tanggal posisi, jenis upload, dan periode tagihan. Ukuran file maksimal 64 MB.');
        }
        if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            done('err', 'Format file harus .xlsx. Simpan ulang dari Excel dengan Save As .xlsx.');
        }
        $path = $f['tmp_name'];
        $fh   = is_uploaded_file($path) ? fopen($path, 'rb') : false;
        if (!$fh) done('err', 'File tidak bisa dibaca di server.');
        if (fread($fh, 4) !== "PK\x03\x04") done('err', 'File bukan .xlsx yang valid.');   // .xlsx = arsip zip
        rewind($fh);

        session_write_close();                 // jangan menahan sesi selama unggah yang panjang
        set_time_limit(0);
        ignore_user_abort(true);

        [$ok, $pesan, $d] = api_aksi('upload_mulai', [
            'tgl_posisi' => $tgl, 'jenis' => $jenis, 'periode' => $periode,
            'nama_file' => (string)$f['name'], 'ukuran' => (int)filesize($path),
        ]);
        if (!$ok || empty($d['id'])) done('err', $pesan ?: 'Gagal memulai unggah.');
        $id = (string)$d['id'];

        $no = 0;
        while (!feof($fh)) {
            $potong = fread($fh, UPLOAD_BAGIAN);
            if ($potong === false || $potong === '') break;
            [$ok, $pesan] = api_aksi('upload_bagian', ['id' => $id, 'no' => $no, 'b64' => base64_encode($potong)]);
            if (!$ok) done('err', $pesan);
            $no++;
        }
        fclose($fh);

        [$ok, $pesan] = api_aksi('upload_selesai', ['id' => $id, 'sha256' => hash_file('sha256', $path), 'bagian' => $no]);
        done($ok ? 'ok' : 'err', $pesan);
    } catch (Throwable $e) {
        error_log('upload: ' . $e->getMessage());
        done('err', 'Layanan penyimpanan tidak tersedia. Coba lagi nanti.');
    }
}

page_head('Upload nominatif', 'upload');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.11/css/jquery.dataTables.min.css">
<div>
    <h1>Upload nominatif</h1>
    <div class="sub">Baseline ditentukan oleh jenis upload dan periodenya, bukan oleh tanggal di file</div>
</div>

<div id="msg" aria-live="polite"></div>

<form id="fUpload" class="card" method="post" enctype="multipart/form-data" style="display:flex;flex-wrap:wrap;gap:32px">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div style="flex:1 1 320px;display:flex;flex-direction:column;gap:12px;justify-content:center">
        <label class="f" for="file">File Excel nominatif (.xlsx)</label>
        <input id="file" type="file" name="file" accept=".xlsx" required style="font:inherit">
        <div class="muted">Semua debitur dalam satu file, baris pertama berisi nama kolom seperti nominatif CBS.</div>
    </div>
    <div style="flex:1 1 380px;display:flex;flex-direction:column;gap:16px">
        <label class="f">Tanggal posisi<input type="date" name="tgl_posisi" value="<?= date('Y-m-d') ?>" required></label>
        <fieldset style="border:0;padding:0;margin:0">
            <legend class="muted" style="padding:0;margin-bottom:4px;font-size:13px">Jenis upload</legend>
            <label style="display:flex;gap:10px;align-items:center;min-height:44px"><input type="radio" name="jenis" value="harian" checked style="width:18px;height:18px"> Harian (posisi terbaru)</label>
            <label style="display:flex;gap:10px;align-items:center;min-height:44px"><input type="radio" name="jenis" value="baseline" style="width:18px;height:18px"> Baseline (data awal bulan, belum ada pembayaran)</label>
        </fieldset>
        <label class="f">Periode tagihan<input type="month" name="periode" value="<?= date('Y-m') ?>" required></label>
        <button class="btn primary" style="justify-content:center;height:48px">Simpan upload</button>
    </div>
</form>

<div class="card"><b>Riwayat upload</b>
    <div class="scroll">
        <table id="riwayat" class="tl" style="width:100%">
            <thead>
                <tr>
                    <th>Tanggal posisi</th>
                    <th>Jenis</th>
                    <th>Periode</th>
                    <th>Nama file</th>
                    <th>Baris</th>
                    <th>Status</th>
                    <th>Aksi</th>
                </tr>
            </thead>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.datatables.net/1.13.11/js/jquery.dataTables.min.js"></script>
<script src="dt-id.js"></script>
<script>
    $(function() {
        var msgBox = document.getElementById('msg'),
            form = document.getElementById('fUpload');

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        function msg(ok, t) {
            msgBox.innerHTML = '<div class="flash ' + (ok ? 'ok' : 'err') + '">' + esc(t) + '</div>';
        }

        $.fn.dataTable.ext.errMode = 'none';
        var table = $('#riwayat').on('error.dt', function() {
            msg(false, 'Gagal memuat riwayat upload.');
        }).DataTable({
            ajax: {
                url: 'upload.php?data=1',
                dataSrc: 'data',
                cache: false
            },
            order: [
                [0, 'desc']
            ],
            pageLength: 10,
            lengthMenu: [10, 25, 50, 100],
            language: window.DT_ID,
            columns: [{
                    data: 'tgl',
                    render: function(d, t, r) {
                        return (t === 'sort' || t === 'type') ? r.tgl_iso : esc(d);
                    }
                },
                {
                    data: 'jenis',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'periode',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'nama_file',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'baris',
                    render: function(d, t, r) {
                        return (t === 'sort' || t === 'type') ? r.baris_raw : esc(d);
                    }
                },
                {
                    data: 'aktif',
                    render: function(d, t) {
                        if (t === 'display') return d ? '<span class="badge">Baseline aktif</span>' : 'Tersimpan';
                        return d ? 'Baseline aktif' : 'Tersimpan';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function(d, t, r) {
                        var b = '';
                        if (!r.aktif) b += '<button type="button" class="btn" data-act="baseline" data-id="' + r.id + '">Jadikan baseline</button>';
                        b += '<button type="button" class="btn" data-act="hapus" data-id="' + r.id + '"' + (r.aktif ? ' data-aktif="1"' : '') + '>Hapus</button>';
                        return '<div style="display:flex;gap:8px">' + b + '</div>';
                    }
                }
            ]
        });

        // kirim ke upload.php lewat fetch; mengembalikan {ok, msg}
        function post(fd, btn) {
            if (btn) btn.disabled = true;
            return fetch('upload.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin',
                    headers: {
                        'X-Requested-With': 'fetch'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    msg(d.ok, d.msg);
                    table.ajax.reload(null, false);
                    return d;
                })
                .catch(function() {
                    msg(false, 'Permintaan gagal atau server tidak merespons dengan benar.');
                    return {
                        ok: false
                    };
                })
                .then(function(d) {
                    if (btn) btn.disabled = false;
                    return d;
                });
        }

        // jadikan baseline / hapus
        $('#riwayat').on('click', 'button[data-act]', function() {
            var act = this.getAttribute('data-act');
            if (act === 'hapus' && !confirm(this.getAttribute('data-aktif')
                    ? 'Upload ini adalah BASELINE AKTIF. Kalau dihapus, pembanding "baseline" tidak tersedia sampai baseline baru ditentukan. Hapus beserta datanya?'
                    : 'Hapus upload ini beserta datanya?')) return;
            var fd = new FormData();
            fd.append('action', act);
            fd.append('id', this.getAttribute('data-id'));
            var tok = form.querySelector('input[name="_csrf"]'); // token dari formulir; tag <meta csrf-token> tidak selalu dicetak page_head()
            fd.append('_csrf', tok ? tok.value : '');
            post(fd, this);
        });

        // upload file
        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = form.querySelector('button'),
                label = btn.textContent;
            btn.textContent = 'Menyimpan…';
            post(new FormData(form), btn).then(function(d) {
                btn.textContent = label;
                if (d.ok) form.reset();
            });
        });
    });
</script>
<?php page_foot();