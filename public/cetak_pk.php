<?php
// Cetak Perjanjian Kredit (PK). Menu: pk (daftarkan di akses_lib.php seperti kredit_arthamas).
// Jenis PK dipilih di halaman ini; isian dibentuk otomatis dari pk/jenis/<jenis>.php.
// Isian diketik manual, dikirim lewat POST ke api/export_pk.php untuk dibuatkan file Word.
// Data TIDAK disimpan di server dan TIDAK masuk URL (POST).
require __DIR__ . '/lib.php';
require __DIR__ . '/../pk/engine.php';

$slug   = isset($_GET['jenis']) && is_string($_GET['jenis']) ? $_GET['jenis'] : '';
$def    = $slug !== '' ? pk_muat($slug) : null;
$daftar = pk_daftar();

page_head('Cetak PK', 'pk');
?>
<style>
    #fPk [hidden] { display: none !important; }
    #fPk .rp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(230px, 1fr)); gap: 14px 18px; }
    #fPk .rp-grid .full { grid-column: 1 / -1; }
    #fPk input[type=text], #fPk input[type=date], #fPk input[type=number], #fPk textarea, #fPk select {
        width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #c5d3d1; border-radius: 8px; font: inherit; background: #fff;
    }
    #fPk textarea { min-height: 90px; resize: vertical; }
    #fPk input:focus, #fPk textarea:focus, #fPk select:focus { outline: 3px solid rgba(15, 107, 99, .25); border-color: #0F6B63; }
    #pkJenis { max-width: 460px; width: 100%; box-sizing: border-box; padding: 9px 11px; border: 1px solid #c5d3d1; border-radius: 8px; font: inherit; background: #fff; }
    .rp-opsi { display: flex; flex-wrap: wrap; gap: 8px 18px; padding-top: 6px; }
    .rp-opsi label { display: inline-flex; align-items: center; gap: 6px; cursor: pointer; }
    .rp-judul { margin: 0 0 12px; font-size: 1.05rem; color: #0F6B63; }
    .rp-aksi { display: flex; flex-wrap: wrap; gap: 12px; align-items: center; }
</style>

<div><h1>Cetak Perjanjian Kredit (PK)</h1></div>

<div class="card">
    <label class="f">Jenis PK
        <select id="pkJenis">
            <option value="">- pilih jenis PK -</option>
            <?php foreach ($daftar as $k => $d): ?>
                <option value="<?= h($k) ?>"<?= $k === $slug ? ' selected' : '' ?>><?= h($d['nama']) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <?php if ($slug !== '' && !$def): ?><div class="flash err" style="margin-top:10px">Jenis PK tidak ditemukan.</div><?php endif; ?>
</div>

<?php if ($def): ?>
<form id="fPk" method="post" action="api/export_pk.php" target="_blank" autocomplete="off">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="jenis" value="<?= h($def['slug']) ?>">

    <?php foreach ($def['bagian'] as $i => $b): ?>
    <div class="card">
        <h2 class="rp-judul"><?= h(chr(65 + $i) . '. ' . $b['judul']) ?></h2>
        <div class="rp-grid">
            <?php foreach ($b['field'] as $f) echo pk_field_html($f), "\n"; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="card rp-aksi">
        <div class="bg">
            <button type="submit" class="bg-btn bg-pdf" title="Unduh PK (Word)"><svg class="ic" aria-hidden="true"><use href="assets/ikon.svg#i-pdf"/></svg>Cetak PK (Word)</button>
        </div>
        <button type="reset" class="btn" id="fKosong">Kosongkan formulir</button>
        <span class="muted">Data hanya dipakai untuk membuat file dan tidak disimpan di server.</span>
    </div>
</form>
<?php endif; ?>

<script>
    (function() {
        // ganti jenis PK
        document.getElementById('pkJenis').addEventListener('change', function() {
            location.href = this.value ? 'pk.php?jenis=' + encodeURIComponent(this.value) : 'pk.php';
        });

        var form = document.getElementById('fPk');
        if (!form) return;
        var nf = new Intl.NumberFormat('id-ID');
        function angka(v) { var s = String(v || '').replace(/\D+/g, ''); return s ? parseInt(s.slice(0, 13), 10) : 0; }

        // isian rupiah: tampil dengan titik ribuan
        Array.prototype.forEach.call(form.querySelectorAll('input.rp'), function(el) {
            el.addEventListener('input', function() { var n = angka(el.value); el.value = n ? nf.format(n) : ''; });
        });

        // field bersyarat: data-jika="status=menikah&x=y"
        function atur() {
            Array.prototype.forEach.call(form.querySelectorAll('[data-jika]'), function(box) {
                var tampil = box.getAttribute('data-jika').split('&').every(function(p) {
                    var kv = p.split('=');
                    return form.elements[kv[0]] && form.elements[kv[0]].value === kv[1];
                });
                box.hidden = !tampil;
                Array.prototype.forEach.call(box.querySelectorAll('input,select,textarea'), function(el) { el.disabled = !tampil; });
            });
        }
        form.addEventListener('change', atur);
        document.getElementById('fKosong').addEventListener('click', function() { setTimeout(atur, 0); });
        atur();
    })();
</script>
<?php page_foot();
