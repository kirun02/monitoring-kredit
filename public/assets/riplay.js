/* Pembantu formulir RIPLAY (dipakai halaman RIPLAY produk). Rumus simulasi HARUS sama dengan riplay_simulasi() di export_lib_riplay.php. */
window.RIPLAY = (function() {
    var nf = new Intl.NumberFormat('id-ID');

    function angka(v) { var s = String(v || '').replace(/\D+/g, ''); return s ? parseInt(s.slice(0, 13), 10) : 0; }
    function desimal(v) { var x = parseFloat(String(v || '').replace(',', '.')); return isFinite(x) ? x : 0; }
    function rp(v) { return 'Rp ' + nf.format(Math.round(v)); }

    // isian rupiah (input.rp): tampil dengan titik ribuan, lalu panggil sesudah()
    function formatRupiah(form, sesudah) {
        Array.prototype.forEach.call(form.querySelectorAll('input.rp'), function(el) {
            el.addEventListener('input', function() {
                var n = angka(el.value);
                el.value = n ? nf.format(n) : '';
                if (sesudah) sesudah();
            });
        });
    }

    function simulasi(P, tenor, bunga, metode, per) {
        var n = Math.max(1, Math.ceil(tenor / Math.max(1, per)));
        var i = (bunga / 100) * per / 12;
        var rows = [], saldo = P, pokokDasar = Math.floor(P / n), k, pokok, bg;
        if (metode === 'flat') {
            var totalBunga = Math.round(P * (bunga / 100) * (tenor / 12)), bungaDasar = Math.floor(totalBunga / n);
            for (k = 1; k <= n; k++) {
                pokok = pokokDasar + (k === n ? P - pokokDasar * n : 0);
                bg = bungaDasar + (k === n ? totalBunga - bungaDasar * n : 0);
                saldo -= pokok;
                rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
            }
        } else if (metode === 'efektif') {
            for (k = 1; k <= n; k++) {
                pokok = pokokDasar + (k === n ? P - pokokDasar * n : 0);
                bg = Math.round(saldo * i);
                saldo -= pokok;
                rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
            }
        } else {
            var a = i > 0 ? Math.round(P * i / (1 - Math.pow(1 + i, -n))) : Math.round(P / n);
            for (k = 1; k <= n; k++) {
                bg = Math.round(saldo * i);
                pokok = (k === n || a - bg > saldo) ? saldo : a - bg;
                saldo -= pokok;
                rows.push({ no: k, pokok: pokok, bunga: bg, angsuran: pokok + bg, saldo: saldo });
            }
        }
        var tp = 0, tb = 0;
        rows.forEach(function(r) { tp += r.pokok; tb += r.bunga; });
        return { rows: rows, n: n, angs1: rows[0].angsuran, tp: tp, tb: tb, ta: tp + tb };
    }

    // tanggal (yyyy-mm-dd) + N bulan; hari yang tidak ada di bulan tujuan jadi hari terakhir bulan itu. Hasil dd/mm/yyyy.
    function tambahBulan(iso, n) {
        var p = String(iso).split('-');
        if (p.length !== 3) return '';
        var d = +p[2], t = new Date(+p[0], +p[1] - 1 + n, 1), akhir = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate();
        t.setDate(Math.min(d, akhir));
        return ('0' + t.getDate()).slice(-2) + '/' + ('0' + (t.getMonth() + 1)).slice(-2) + '/' + t.getFullYear();
    }

    // sama seperti tambahBulan, tetapi hasilnya yyyy-mm-dd (untuk isi input tanggal)
    function tambahBulanIso(iso, n) {
        var p = String(iso).split('-');
        if (p.length !== 3) return '';
        var d = +p[2], t = new Date(+p[0], +p[1] - 1 + n, 1), akhir = new Date(t.getFullYear(), t.getMonth() + 1, 0).getDate();
        t.setDate(Math.min(d, akhir));
        return t.getFullYear() + '-' + ('0' + (t.getMonth() + 1)).slice(-2) + '-' + ('0' + t.getDate()).slice(-2);
    }

    return { angka: angka, desimal: desimal, rp: rp, nf: nf, formatRupiah: formatRupiah, simulasi: simulasi, tambahBulan: tambahBulan, tambahBulanIso: tambahBulanIso };
})();
