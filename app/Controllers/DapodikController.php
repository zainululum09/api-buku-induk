<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class DapodikController extends BaseController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * 1. SINKRONISASI DATA SEKOLAH (Single Object)
     */
    public function syncSekolah()
    {
        // Mengambil input JSON mentah dari request Vue.js
        $json = $this->request->getJSON(true);
        
        // Dapodik getSekolah mengembalikan single object di dalam properti 'rows'
        $dataSekolah = $json['rows'] ?? null;

        if (!$dataSekolah) {
            return $this->fail('Format data sekolah tidak valid atau data kosong.', 400);
        }

        $this->db->transStart();

        $sekolahId = $dataSekolah['sekolah_id'];
        
        // Siapkan data sesuai struktur tabel settings kita
        $prepareData = [
            'sekolah_id'               => $sekolahId,
            'nama_sekolah'             => $dataSekolah['nama'],
            'nss'                      => $dataSekolah['nss'],
            'npsn'                     => $dataSekolah['npsn'],
            'bentuk_pendidikan_id'     => $dataSekolah['bentuk_pendidikan_id'],
            'bentuk_pendidikan_id_str' => $dataSekolah['bentuk_pendidikan_id_str'],
            'status_sekolah'           => $dataSekolah['status_sekolah'],
            'status_sekolah_str'       => $dataSekolah['status_sekolah_str'],
            'alamat_jalan'             => $dataSekolah['alamat_jalan'],
            'rt'                       => $dataSekolah['rt'],
            'rw'                       => $dataSekolah['rw'],
            'kode_wilayah'             => $dataSekolah['kode_wilayah'],
            'kode_pos'                 => $dataSekolah['kode_pos'],
            'nomor_telepon'            => $dataSekolah['nomor_telepon'],
            'nomor_fax'                => $dataSekolah['nomor_fax'],
            'email'                    => $dataSekolah['email'],
            'website'                  => $dataSekolah['website'],
            'is_sks'                   => $dataSekolah['is_sks'] ? 1 : 0,
            'lintang'                  => $dataSekolah['lintang'],
            'bujur'                    => $dataSekolah['bujur'],
            'dusun'                    => $dataSekolah['dusun'],
            'desa_kelurahan'           => $dataSekolah['desa_kelurahan'],
            'kecamatan'                => $dataSekolah['kecamatan'],
            'kabupaten_kota'           => $dataSekolah['kabupaten_kota'],
            'provinsi'                 => $dataSekolah['provinsi'],
        ];

        // Cek apakah data sekolah sudah pernah ada
        $exist = $this->db->table('settings')->where('sekolah_id', $sekolahId)->get()->getRow();

        if ($exist) {
            // Jika sudah ada -> UPDATE
            $this->db->table('settings')->where('sekolah_id', $sekolahId)->update($prepareData);
        } else {
            // Jika belum ada -> CREATE
            $this->db->table('settings')->insert($prepareData);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Gagal melakukan sinkronisasi data sekolah.', 500);
        }

        return $this->respond(['status' => 'success', 'message' => 'Data sekolah berhasil disinkronisasi.'], 200);
    }

    /**
     * 2. SINKRONISASI DATA GTK (Array of Object + Riwayat Pendidikan)
     */
    public function syncGtk()
    {
        $json = $this->request->getJSON(true);
        $rows = $json['rows'] ?? [];

        if (empty($rows)) {
            return $this->fail('Data GTK kosong.', 400);
        }

        $this->db->transStart();

        foreach ($rows as $gtk) {
            $ptkTerdaftarId = $gtk['ptk_terdaftar_id'];

            $prepareGtk = [
                'ptk_terdaftar_id'          => $ptkTerdaftarId,
                'ptk_id'                    => $gtk['ptk_id'],
                'tahun_ajaran_id'           => $gtk['tahun_ajaran_id'],
                'ptk_induk'                 => $gtk['ptk_induk'],
                'tanggal_surat_tugas'       => !empty($gtk['tanggal_surat_tugas']) ? $gtk['tanggal_surat_tugas'] : null,
                'nama'                      => $gtk['nama'],
                'jenis_kelamin'             => $gtk['jenis_kelamin'],
                'tempat_lahir'              => $gtk['tempat_lahir'],
                'tanggal_lahir'             => !empty($gtk['tanggal_lahir']) ? $gtk['tanggal_lahir'] : null,
                'agama_id'                  => $gtk['agama_id'],
                'agama_id_str'              => $gtk['agama_id_str'],
                'nuptk'                     => $gtk['nuptk'],
                'nik'                       => $gtk['nik'],
                'jenis_ptk_id'              => $gtk['jenis_ptk_id'],
                'jenis_ptk_id_str'          => $gtk['jenis_ptk_id_str'],
                'jabatan_ptk_id'            => $gtk['jabatan_ptk_id'],
                'jabatan_ptk_id_str'        => $gtk['jabatan_ptk_id_str'],
                'status_kepegawaian_id'     => $gtk['status_kepegawaian_id'],
                'status_kepegawaian_id_str' => $gtk['status_kepegawaian_id_str'],
                'nip'                       => $gtk['nip'],
                'pendidikan_terakhir'       => $gtk['pendidikan_terakhir'],
                'bidang_studi_terakhir'     => $gtk['bidang_studi_terakhir'],
                'pangkat_golongan_terakhir' => $gtk['pangkat_golongan_terakhir']
            ];

            // LOGIKA UPSERT UTAMA GTK
            $existGtk = $this->db->table('gtk')->where('ptk_terdaftar_id', $ptkTerdaftarId)->get()->getRow();
            if ($existGtk) {
                $this->db->table('gtk')->where('ptk_terdaftar_id', $ptkTerdaftarId)->update($prepareGtk);
            } else {
                $this->db->table('gtk')->insert($prepareGtk);
            }

            // PROSES SUBSIDIARY: Riwayat Pendidikan Formal
            if (!empty($gtk['rwy_pend_formal'])) {
                foreach ($gtk['rwy_pend_formal'] as $rwy) {
                    $rwyId = $rwy['riwayat_pendidikan_formal_id'];

                    $prepareRwy = [
                        'riwayat_pendidikan_formal_id' => $rwyId,
                        'ptk_terdaftar_id'             => $ptkTerdaftarId,
                        'satuan_pendidikan_formal'     => $rwy['satuan_pendidikan_formal'],
                        'fakultas'                     => $rwy['fakultas'],
                        'kependidikan'                 => $rwy['kependidikan'],
                        'tahun_masuk'                  => $rwy['tahun_masuk'],
                        'tahun_lulus'                  => $rwy['tahun_lulus'],
                        'nim'                          => $rwy['nim'],
                        'status_kuliah'                => $rwy['status_kuliah'],
                        'semester'                     => $rwy['semester'],
                        'ipk'                          => !empty($rwy['ipk']) ? $rwy['ipk'] : 0.00,
                        'prodi'                        => $rwy['prodi'],
                        'id_reg_pd'                    => $rwy['id_reg_pd'],
                        'bidang_studi_id_str'          => $rwy['bidang_studi_id_str'],
                        'jenjang_pendidikan_id_str'    => $rwy['jenjang_pendidikan_id_str'],
                        'gelar_akademik_id_str'        => $rwy['gelar_akademik_id_str']
                    ];

                    // LOGIKA UPSERT ANAK: Riwayat Pendidikan
                    $existRwy = $this->db->table('gtk_riwayat_pendidikan')->where('riwayat_pendidikan_formal_id', $rwyId)->get()->getRow();
                    if ($existRwy) {
                        $this->db->table('gtk_riwayat_pendidikan')->where('riwayat_pendidikan_formal_id', $rwyId)->update($prepareRwy);
                    } else {
                        $this->db->table('gtk_riwayat_pendidikan')->insert($prepareRwy);
                    }
                }
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Gagal melakukan sinkronisasi batch data GTK.', 500);
        }

        return $this->respond(['status' => 'success', 'message' => count($rows) . ' data GTK berhasil disinkronisasi.'], 200);
    }

    /**
     * 3. SINKRONISASI DATA ROMBONGAN BELAJAR (+ Anggota Rombel)
     */
    public function syncRombonganBelajar()
    {
        $json = $this->request->getJSON(true);
        $rows = $json['rows'] ?? [];

        if (empty($rows)) {
            return $this->fail('Data Rombongan Belajar kosong.', 400);
        }

        $this->db->transStart();

        foreach ($rows as $rombel) {
            $rombelId = $rombel['rombongan_belajar_id'];

            $prepareRombel = [
                'rombongan_belajar_id'      => $rombelId,
                'nama'                      => $rombel['nama'],
                'tingkat_pendidikan_id'     => $rombel['tingkat_pendidikan_id'],
                'tingkat_pendidikan_id_str' => $rombel['tingkat_pendidikan_id_str'],
                'semester_id'               => $rombel['semester_id'],
                'jenis_rombel'              => $rombel['jenis_rombel'],
                'jenis_rombel_str'          => $rombel['jenis_rombel_str'],
                'kurikulum_id'              => $rombel['kurikulum_id'],
                'kurikulum_id_str'          => $rombel['kurikulum_id_str'],
                'id_ruang'                  => $rombel['id_ruang'],
                'id_ruang_str'              => $rombel['id_ruang_str'],
                'moving_class'              => $rombel['moving_class'],
                'ptk_id'                    => $rombel['ptk_id'],
                'ptk_id_str'                => $rombel['ptk_id_str']
            ];

            // UPSERT Rombel
            $existRombel = $this->db->table('rombongan_belajar')->where('rombongan_belajar_id', $rombelId)->get()->getRow();
            if ($existRombel) {
                $this->db->table('rombongan_belajar')->where('rombongan_belajar_id', $rombelId)->update($prepareRombel);
            } else {
                $this->db->table('rombongan_belajar')->insert($prepareRombel);
            }

            // UPSERT Anggota Rombel (Siswa yang masuk kelas ini)
            if (!empty($rombel['anggota_rombel'])) {
                foreach ($rombel['anggota_rombel'] as $anggota) {
                    $anggotaRombelId = $anggota['anggota_rombel_id'];

                    $prepareAnggota = [
                        'anggota_rombel_id'    => $anggotaRombelId,
                        'rombongan_belajar_id' => $rombelId,
                        'peserta_didik_id'     => $anggota['peserta_didik_id'],
                        'jenis_pendaftaran_id' => $anggota['jenis_pendaftaran_id'],
                        'jenis_pendaftaran_id_str' => $anggota['jenis_pendaftaran_id_str']
                    ];

                    $existAnggota = $this->db->table('anggota_rombel')->where('anggota_rombel_id', $anggotaRombelId)->get()->getRow();
                    if ($existAnggota) {
                        $this->db->table('anggota_rombel')->where('anggota_rombel_id', $anggotaRombelId)->update($prepareAnggota);
                    } else {
                        $this->db->table('anggota_rombel')->insert($prepareAnggota);
                    }
                }
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Gagal melakukan sinkronisasi batch data Rombel.', 500);
        }

        return $this->respond(['status' => 'success', 'message' => count($rows) . ' data Rombel & Anggota Kelas berhasil disinkronisasi.'], 200);
    }

    /**
     * 4. SINKRONISASI DATA SISWA (3 Tabel Relasi)
     */
    public function syncSiswa()
    {
        $json = $this->request->getJSON(true);
        $rows = $json['rows'] ?? [];

        if (empty($rows)) {
            return $this->fail('Data Siswa kosong.', 400);
        }

        $this->db->transStart();

        foreach ($rows as $siswa) {
            // Karena primary key lokal kita adalah NISN
            $nisn = $siswa['nisn'];

            // A. Siapkan data Master Siswa
            $prepareSiswa = [
                'nisn'             => $nisn,
                'peserta_didik_id' => $siswa['peserta_didik_id'],
                'nipd'             => $siswa['nipd'],
                'nik'              => $siswa['nik'],
                'nama_lengkap'     => $siswa['nama'], // memetakan field 'nama' dari Dapodik
                'jenis_kelamin'    => $siswa['jenis_kelamin'],
                'tempat_lahir'     => $siswa['tempat_lahir'],
                'tanggal_lahir'    => !empty($siswa['tanggal_lahir']) ? $siswa['tanggal_lahir'] : null,
                'agama'            => $siswa['agama_id_str'] ?? null,
                'anak_keberapa'    => $siswa['anak_ke_berapa'] ?? 1,
                'tinggi_badan'     => $siswa['tinggi_badan'] ?? 0,
                'berat_badan'      => $siswa['berat_badan'] ?? 0,
                'no_hp'            => $siswa['nomor_telepon_seluler'] ?? null,
                'email'            => $siswa['email'] ?? null,
                'status_siswa'     => 'Aktif'
            ];

            // UPSERT Tabel Siswa Utama
            $existSiswa = $this->db->table('siswa')->where('nisn', $nisn)->get()->getRow();
            if ($existSiswa) {
                $this->db->table('siswa')->where('nisn', $nisn)->update($prepareSiswa);
            } else {
                $this->db->table('siswa')->insert($prepareSiswa);
            }

            // B. Siapkan data Orang Tua
            $prepareOrangTua = [
                'nisn'           => $nisn,
                'nama_ayah'      => $siswa['nama_ayah'] ?? null,
                'pekerjaan_ayah' => $siswa['pekerjaan_ayah_id_str'] ?? null,
                'nama_ibu'       => $siswa['nama_ibu'] ?? null,
                'pekerjaan_ibu'  => $siswa['pekerjaan_ibu_id_str'] ?? null,
                'nama_wali'      => $siswa['nama_wali'] ?? null,
                'pekerjaan_wali' => $siswa['pekerjaan_wali_id_str'] ?? null,
            ];

            // UPSERT Tabel Orang Tua
            $existOrtu = $this->db->table('siswa_orangtua')->where('nisn', $nisn)->get()->getRow();
            if ($existOrtu) {
                $this->db->table('siswa_orangtua')->where('nisn', $nisn)->update($prepareOrangTua);
            } else {
                $this->db->table('siswa_orangtua')->insert($prepareOrangTua);
            }

            // C. Siapkan data Registrasi Riwayat Sekolah Siswa
            $semesterId = $siswa['semester_id'] ?? '20252'; // default fallback jika tidak ada
            $prepareRegistrasi = [
                'nisn'                 => $nisn,
                'registrasi_id'        => $siswa['registrasi_id'] ?? 'REG-' . $nisn,
                'jenis_pendaftaran'    => $siswa['jenis_pendaftaran_id_str'] ?? null,
                'tanggal_masuk_sekolah'=> !empty($siswa['tanggal_masuk_sekolah']) ? $siswa['tanggal_masuk_sekolah'] : null,
                'sekolah_asal'         => $siswa['sekolah_asal'] ?? null,
                'nama_rombel'          => $siswa['nama_rombel'] ?? null,
                'semester_id'          => $semesterId,
                'rombongan_belajar_id' => $siswa['rombongan_belajar_id'] ?? null,
                'kurikulum_nama'       => $siswa['kurikulum_id_str'] ?? null,
            ];

            // UPSERT Tabel Registrasi per Semester (mencegah duplikat dengan unique key gabungan)
            $existReg = $this->db->table('siswa_registrasi')
                                 ->where('nisn', $nisn)
                                 ->where('semester_id', $semesterId)
                                 ->get()->getRow();
            if ($existReg) {
                $this->db->table('siswa_registrasi')
                         ->where('id', $existReg->id)
                         ->update($prepareRegistrasi);
            } else {
                $this->db->table('siswa_registrasi')->insert($prepareRegistrasi);
            }
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === false) {
            return $this->fail('Gagal melakukan sinkronisasi batch data Siswa.', 500);
        }

        return $this->respond(['status' => 'success', 'message' => count($rows) . ' data Siswa komplit berhasil disinkronisasi.'], 200);
    }
}