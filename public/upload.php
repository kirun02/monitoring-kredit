<?php
require __DIR__ . '/lib.php';

$ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';

/** Selesai: balas JSON (AJAX) atau flash + redirect (form biasa / tanpa JavaScript). */
function done(bool $ajax, string $cat, string $msg): void {
    if ($ajax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $cat === 'ok', 'msg' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }
    flash($cat, $msg);
    redirect('upload.php');
}

// ---------- data riwayat untuk DataTables ----------
if (($_GET['data'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $aktif = array_column(q('SELECT baseline_batch_id FROM periode')->fetchAll(), 'baseline_batch_id');
    $data = [];
    foreach (q('SELECT * FROM upload_batch ORDER BY tgl_posisi DESC, id DESC LIMIT 100')->fetchAll() as $b) {
        $data[] = [
            'id' => (int)$b['id'],
            'tgl' => date('d/m/Y', strtotime($b['tgl_posisi'])), 'tgl_iso' => $b['tgl_posisi'],
            'jenis' => ucfirst($b['jenis']), 'periode' => $b['periode'], 'nama_file' => $b['nama_file'],
            'baris' => fmt_int($b['jumlah_baris']), 'baris_raw' => (int)$b['jumlah_baris'],
            'aktif' => in_array($b['id'], $aktif),
        ];
    }
    echo json_encode(['data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? 'upload';
    if ($act === 'baseline') {
        $b = q('SELECT periode FROM upload_batch WHERE id = ?', [(int)$_POST['id']])->fetch();
        if (!$b) done($ajax, 'err', 'Upload tidak ditemukan.');
        set_baseline($b['periode'], (int)$_POST['id']);
        done($ajax, 'ok', 'Baseline periode ' . $b['periode'] . ' diganti.');
    }
    if ($act === 'hapus') {
        q('DELETE FROM upload_batch WHERE id = ?', [(int)$_POST['id']]);
        done($ajax, 'ok', 'Upload dihapus.');
    }
    // upload file
    $tgl = $_POST['tgl_posisi'] ?? ''; $jenis = $_POST['jenis'] ?? ''; $periode = $_POST['periode'] ?? '';
    $f = $_FILES['file'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !$tgl || !$periode || !in_array($jenis, ['harian', 'baseline'], true)) {
        done($ajax, 'err', 'Lengkapi file, tanggal posisi, jenis upload, dan periode tagihan. Ukuran file maksimal 64 MB.');
    }
    if (strtolower(pathinfo($f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
        done($ajax, 'err', 'Format file harus .xlsx. Simpan ulang dari Excel dengan Save As .xlsx.');
    }
    try {
        $dobel = 0;
        $optAda = [];
        $rows = parse_excel($f['tmp_name'], $dobel, $optAda);
        if (!$rows) throw new RuntimeException('tidak ada baris data');
    } catch (Throwable $e) {
        done($ajax, 'err', 'File tidak bisa dibaca: ' . $e->getMessage());
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('DELETE FROM upload_batch WHERE tgl_posisi = ? AND jenis = ?', [$tgl, $jenis]);
        $bid = (int)q('INSERT INTO upload_batch (tgl_posisi, jenis, periode, nama_file, jumlah_baris) VALUES (?,?,?,?,?) RETURNING id',
                      [$tgl, $jenis, $periode, $f['name'], count($rows)])->fetchColumn();
        copy_rows($pdo, $bid, $rows);
        if ($jenis === 'baseline') set_baseline($periode, $bid);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        done($ajax, 'err', 'Gagal menyimpan: ' . $e->getMessage());
    }
    done($ajax, 'ok', 'Tersimpan: ' . fmt_int(count($rows)) . ' debitur' . ($jenis === 'baseline' ? ', dijadikan baseline periode ' . $periode . '.' : '.')
        . ($dobel ? ' (' . fmt_int($dobel) . ' baris dobel norek dibuang)' : '')
        . (array_diff(OPTF, $optAda) ? ' Kolom ' . implode(' dan ', array_diff(OPTF, $optAda)) . ' tidak ditemukan di file ini, jadi kosong.' : ''));
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
                        b += '<button type="button" class="btn" data-act="hapus" data-id="' + r.id + '">Hapus</button>';
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
            if (act === 'hapus' && !confirm('Hapus upload ini beserta datanya?')) return;
            var fd = new FormData();
            fd.append('action', act);
            fd.append('id', this.getAttribute('data-id'));
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