CREATE TABLE IF NOT EXISTS upload_batch (
  id SERIAL PRIMARY KEY,
  tgl_posisi DATE NOT NULL,
  jenis TEXT NOT NULL CHECK (jenis IN ('harian','baseline')),
  periode TEXT NOT NULL,              -- format YYYY-MM (periode tagihan)
  nama_file TEXT,
  jumlah_baris INTEGER NOT NULL DEFAULT 0,
  created_at TIMESTAMPTZ NOT NULL DEFAULT now(),
  UNIQUE (tgl_posisi, jenis)
);
CREATE TABLE IF NOT EXISTS periode (
  periode TEXT PRIMARY KEY,
  baseline_batch_id INTEGER REFERENCES upload_batch(id) ON DELETE SET NULL
);
CREATE TABLE IF NOT EXISTS nominatif (
  batch_id INTEGER NOT NULL REFERENCES upload_batch(id) ON DELETE CASCADE,
  kode_ao TEXT, nama_ao TEXT, cif TEXT, norek TEXT NOT NULL,
  plafon NUMERIC(20,2), bakidebet NUMERIC(20,2), tot_bunga NUMERIC(20,2),
  tgl_pinjam DATE, tgl_tempo DATE, hari_tung INTEGER, kolek INTEGER,
  kw_bln_pkk NUMERIC(20,2), kw_bln_bg NUMERIC(20,2),
  t_byr_pkk NUMERIC(20,2), t_byr_bg NUMERIC(20,2),
  byr_blnpkk NUMERIC(20,2), byr_blnbg NUMERIC(20,2),
  ppka NUMERIC(20,2), ckpn NUMERIC(20,2)
);
CREATE INDEX IF NOT EXISTS ix_nom_batch_ao ON nominatif (batch_id, kode_ao);
CREATE INDEX IF NOT EXISTS ix_nom_batch_norek ON nominatif (batch_id, norek);
