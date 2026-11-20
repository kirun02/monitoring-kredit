<?php
// Definisi PK: Angsuran, jaminan SHM.  Template: template/pk/angsuran_shm.docx
// Berisi: (1) 'bagian' = isian form, (2) 'hitung' = isi placeholder ${...} di template Word.
return [
    'nama'      => 'PK Angsuran Jaminan SHM',
    'template'  => 'angsuran_shm.docx',
    'nama_file' => function ($in) { return 'PK_' . $in['nama']; },

    'bagian' => [
        ['judul' => 'Nomor & tanggal PK', 'field' => [
            ['nama' => 'no_pk', 'label' => 'Nomor PK', 'wajib' => true, 'maks' => 60],
            ['nama' => 'tgl_pk', 'label' => 'Tanggal PK', 'tipe' => 'date', 'wajib' => true],
            ['nama' => 'tgl_permohonan', 'label' => 'Tanggal permohonan', 'tipe' => 'date'],
            ['nama' => 'tgl_persetujuan', 'label' => 'Tanggal persetujuan', 'tipe' => 'date', 'wajib' => true],
        ]],

        pk_bagian_bank(),

        ['judul' => 'Data debitur (PEMINJAM)', 'field' => [
            ['nama' => 'nama', 'label' => 'Nama debitur', 'wajib' => true, 'maks' => 100],
            ['nama' => 'tempat_lahir', 'label' => 'Tempat lahir', 'maks' => 60],
            ['nama' => 'tgl_lahir', 'label' => 'Tanggal lahir', 'tipe' => 'date'],
            ['nama' => 'nik', 'label' => 'NIK (16 digit)', 'maks' => 16, 'pola' => '\d{16}', 'hint' => 'NIK terdiri dari 16 digit angka'],
            ['nama' => 'alamat', 'label' => 'Alamat', 'tipe' => 'area', 'maks' => 250, 'lebar' => 'full'],
            ['nama' => 'status', 'label' => 'Status perkawinan', 'tipe' => 'radio', 'nilai' => 'belum',
                'opsi' => ['belum' => 'Belum Menikah', 'menikah' => 'Menikah', 'lainnya' => 'Lainnya']],
            ['nama' => 'hub_pasangan', 'label' => 'Hubungan', 'tipe' => 'pilih', 'muncul_jika' => ['status' => 'menikah'],
                'opsi' => ['istrinya' => 'Istri', 'suaminya' => 'Suami']],
            ['nama' => 'nama_pasangan', 'label' => 'Nama pasangan', 'maks' => 100, 'muncul_jika' => ['status' => 'menikah']],
            ['nama' => 'tempat_lahir_pasangan', 'label' => 'Tempat lahir pasangan', 'maks' => 60, 'muncul_jika' => ['status' => 'menikah']],
            ['nama' => 'tgl_lahir_pasangan', 'label' => 'Tanggal lahir pasangan', 'tipe' => 'date', 'muncul_jika' => ['status' => 'menikah']],
            ['nama' => 'ktp_pasangan', 'label' => 'NIK pasangan', 'maks' => 16, 'pola' => '\d{16}', 'muncul_jika' => ['status' => 'menikah']],
        ]],

        ['judul' => 'Kredit', 'field' => [
            ['nama' => 'plafon', 'label' => 'Plafon (Rp)', 'tipe' => 'rupiah', 'wajib' => true],
            ['nama' => 'tenor', 'label' => 'Jangka waktu (bulan)', 'tipe' => 'angka', 'wajib' => true, 'min' => 1, 'maks' => 360],
            ['nama' => 'bunga', 'label' => 'Suku bunga (% per tahun, flat)', 'tipe' => 'desimal', 'wajib' => true, 'min' => 0, 'maks' => 100],
            ['nama' => 'tgl_mulai', 'label' => 'Tanggal mulai berlaku', 'tipe' => 'date', 'wajib' => true],
            ['nama' => 'tgl_angsuran_pertama', 'label' => 'Tanggal angsuran pertama', 'tipe' => 'date', 'wajib' => true],
            ['nama' => 'tgl_jatuh_tempo', 'label' => 'Tanggal jatuh tempo', 'tipe' => 'date', 'hint' => 'Kosongkan: dihitung dari tanggal mulai + jangka waktu'],
            ['nama' => 'penalty_persen', 'label' => 'Penalty pelunasan (%)', 'tipe' => 'desimal', 'nilai' => 3],
            ['nama' => 'provisi_persen', 'label' => 'Provisi (%)', 'tipe' => 'desimal', 'nilai' => 1],
            ['nama' => 'admin_persen', 'label' => 'Administrasi (%)', 'tipe' => 'desimal', 'nilai' => 2],
            ['nama' => 'denda_persen', 'label' => 'Denda keterlambatan (%)', 'tipe' => 'desimal', 'nilai' => 6],
            ['nama' => 'materai', 'label' => 'Biaya materai (Rp)', 'tipe' => 'rupiah'],
        ]],

        ['judul' => 'Agunan (Pasal 9)', 'field' => [
            ['nama' => 'agunan', 'label' => 'Uraian agunan / bukti kepemilikan', 'tipe' => 'area', 'maks' => 2000, 'lebar' => 'full'],
        ]],
    ],

    // $in = isian form yang sudah bersih. Kembalikan ['placeholder' => 'isi'].
    'hitung' => function (array $in) {
        $plafon = $in['plafon'];
        $tenor  = $in['tenor'];
        $bunga  = $in['bunga'];

        // bunga flat; selisih pembulatan ditampung di angsuran terakhir (sama dengan simulasi RIPLAY)
        $totalBunga    = (int)round($plafon * ($bunga / 100) * ($tenor / 12));
        $angsuranPokok = intdiv($plafon, $tenor);
        $bungaBulan    = intdiv($totalBunga, $tenor);
        $angsuranTotal = $angsuranPokok + $bungaBulan;
        $bungaPersenBulan = $bunga / 12;

        $provNom  = (int)round($plafon * $in['provisi_persen'] / 100);
        $adminNom = (int)round($plafon * $in['admin_persen'] / 100);
        $nol = function ($n) { return $n > 0 ? terbilang_rp($n) : 'Nol'; };

        $jt = $in['tgl_jatuh_tempo'] !== '' ? $in['tgl_jatuh_tempo']
            : (new DateTime($in['tgl_mulai']))->modify('+' . $tenor . ' months')->format('Y-m-d');
        $hariAngs = (int)(new DateTime($in['tgl_angsuran_pertama']))->format('j');

        return [
            'no_pk' => $in['no_pk'], 'hari_pk' => hari_indo($in['tgl_pk']), 'tgl_pk' => tgl_indo($in['tgl_pk']),
            'direktur1_nama' => $in['direktur1_nama'], 'direktur1_jabatan' => $in['direktur1_jabatan'],
            'direktur2_nama' => $in['direktur2_nama'], 'direktur2_jabatan' => $in['direktur2_jabatan'],

            'nama_debitur' => $in['nama'], 'tempat_lahir' => $in['tempat_lahir'], 'tgl_lahir' => pk_tgl($in['tgl_lahir']),
            'no_ktp' => $in['nik'], 'alamat_debitur' => $in['alamat'],

            'hub_pasangan' => $in['hub_pasangan'], 'nama_pasangan' => $in['nama_pasangan'],
            'tempat_lahir_pasangan' => $in['tempat_lahir_pasangan'], 'tgl_lahir_pasangan' => pk_tgl($in['tgl_lahir_pasangan']),
            'ktp_pasangan' => $in['ktp_pasangan'],

            'tgl_permohonan' => pk_tgl($in['tgl_permohonan']), 'tgl_persetujuan' => tgl_indo($in['tgl_persetujuan']),

            'plafon' => rp($plafon), 'plafon_terbilang' => terbilang_rp($plafon),
            'jangka_waktu' => $tenor, 'jangka_waktu_terbilang' => ucwords(terbilang($tenor)),
            'tgl_mulai' => tgl_indo($in['tgl_mulai']), 'tgl_jatuh_tempo' => tgl_indo($jt),

            'penalty_persen' => $in['penalty_persen'], 'penalty_terbilang' => strtolower(terbilang_persen($in['penalty_persen'])),

            'bunga_tahun' => number_format($bunga, 2, '.', ''), 'bunga_tahun_terbilang' => terbilang_persen($bunga),
            'bunga_bulan' => number_format($bungaPersenBulan, 2, '.', ''), 'bunga_bulan_terbilang' => terbilang_persen($bungaPersenBulan),
            'bunga_nominal' => rp($bungaBulan), 'bunga_nominal_terbilang' => terbilang_rp($bungaBulan),

            'provisi_persen' => $in['provisi_persen'], 'provisi_terbilang' => strtolower(terbilang_persen($in['provisi_persen'])),
            'provisi_nominal' => rp($provNom), 'provisi_nominal_terbilang' => $nol($provNom),
            'admin_persen' => $in['admin_persen'], 'admin_terbilang' => strtolower(terbilang_persen($in['admin_persen'])),
            'admin_nominal' => rp($adminNom), 'admin_nominal_terbilang' => $nol($adminNom),
            'materai' => rp($in['materai']),

            'angsuran_pokok' => rp($angsuranPokok), 'angsuran_pokok_terbilang' => strtolower(terbilang_rp($angsuranPokok)),
            'angsuran_total' => rp($angsuranTotal), 'angsuran_total_terbilang' => terbilang_rp($angsuranTotal),

            'tgl_angsuran' => $hariAngs, 'tgl_angsuran_terbilang' => ucwords(terbilang($hariAngs)),
            'tgl_angsuran_pertama' => tgl_indo($in['tgl_angsuran_pertama']),

            'denda_persen' => $in['denda_persen'],
            'agunan_deskripsi' => $in['agunan'],
        ];
    },
];
