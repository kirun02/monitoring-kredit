# Monitoring Kredit Harian (XAMPP, PHP 7, PostgreSQL 10)

## Pasang
1. Ekstrak folder ini ke `C:\xampp\htdocs\monitoring-kredit\`.
2. Buat database kosong bernama `monitoring` di PostgreSQL (pgAdmin: klik kanan Databases > Create, atau
   `CREATE DATABASE monitoring;`). Tabel dibuat otomatis saat halaman pertama dibuka.
3. Buka `config.php`, isi `pass` dengan password PostgreSQL. Sesuaikan user/port/dbname kalau berbeda.
4. Buka `C:\xampp\php\php.ini`, pastikan baris ini aktif (tanpa titik koma di depan):
   `extension=pdo_pgsql`, `extension=pgsql`, `extension=zip`
   lalu ubah: `upload_max_filesize=64M`, `post_max_size=64M`, `memory_limit=768M`, `max_execution_time=300`
5. Restart Apache di XAMPP Control Panel.
6. Buka `http://localhost/monitoring-kredit/` (dari komputer lain: `http://IP-KOMPUTER/monitoring-kredit/`).

## Cara pakai
- **Upload nominatif**: pilih file .xlsx, tanggal posisi, jenis (Harian/Baseline), dan periode tagihan.
- Baseline = nominatif awal bulan sebelum ada pembayaran. Upload jenis Baseline otomatis jadi baseline periodenya;
  upload harian bisa dijadikan baseline lewat tombol **Jadikan baseline**.
- Upload ulang dengan tanggal dan jenis yang sama menimpa data sebelumnya.
- Dashboard punya dua pembanding: **Vs kemarin** dan **Vs baseline**.

## Rumus
- NPL = bakidebet kolek 3, 4, 5 dibanding total bakidebet.
- Tagihan = kw_bln_pkk + kw_bln_bg (dari baseline periode; kalau belum ada, dari file posisi itu).
- Realisasi = byr_blnpkk + byr_blnbg, hanya untuk debitur yang punya tagihan bulan itu.
  Pembayaran debitur tanpa tagihan (jatuh tempo/menunggak) ditampilkan terpisah.
- CKPN turun karena angsuran = debitur yang ada di kedua file, total bayar (t_byr_pkk + t_byr_bg) bertambah
  dan CKPN berkurang: selisih CKPN-nya dijumlahkan.

## Backup
`C:\Program Files\PostgreSQL\10\bin\pg_dump -U postgres monitoring > backup.sql`

Tanpa login. Jalankan hanya di jaringan kantor. Format file harus .xlsx (bukan .xls).
