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
     * 5. METHOD BARU: AUDIT LOG SINKRONISASI
     * Disentralisasi agar bisa dipanggil oleh semua modul sync
     */
    private function auditSync(string $module, string $status, int $volume, ?string $message = null)
    {
        try {
            $this->db->table('sync_logs')->insert([
                'module'     => $module,
                'status'     => $status,
                'volume'     => $volume,
                'message'    => $message,
                'created_at' => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Biarkan lewat agar jika log gagal, proses utama API tidak ikut macet/interupt
            log_message('error', 'Gagal menulis audit log: ' . $e->getMessage());
        }
    }

    /**
     * FUNGSI PEMBANTU: Mengambil data dari WebService Dapodik Lokal via CURL
     */
    private function fetchFromDapodik($endpoint, $customUrl = null, $customToken = null)
    {
        $setting = $this->db->table('settings')->get()->getRow();
        
        $baseUrl = $customUrl ?: ($setting->dapodik_url ?? 'http://127.0.0.1:5774');
        $token   = $customToken ?: ($setting->dapodik_token ?? '');
        $npsn    = $setting->npsn ?? '20231052';

        $baseUrl = rtrim($baseUrl, '/');
        
        $separator = (strpos($endpoint, '?') !== false) ? '&' : '?';
        $fullUrl   = $baseUrl . $endpoint . $separator . 'npsn=' . $npsn;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fullUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $token,
            'Accept: application/json'
        ]);

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            throw new \Exception("Koneksi ke WebService Dapodik gagal.");
        }

        return json_decode($response, true);
    }

    /**
     * 1. SINKRONISASI DATA SEKOLAH
     */
    public function syncSekolah()
    {
        $jsonInput = $this->request->getJSON(true);
        $vueUrl    = $jsonInput['dapodik_url'] ?? null;
        $vueToken  = $jsonInput['dapodik_token'] ?? null;

        try {
            $dapodikData = $this->fetchFromDapodik('/WebService/getSekolah', $vueUrl, $vueToken);
            $dataSekolah = null;

            if (isset($dapodikData['rows'])) {
                if (isset($dapodikData['rows']['sekolah_id'])) {
                    $dataSekolah = $dapodikData['rows'];
                } elseif (isset($dapodikData['rows'][0])) {
                    $dataSekolah = $dapodikData['rows'][0];
                }
            }

            if (!$dataSekolah) {
                $msg = 'Data profil sekolah tidak ditemukan atau struktur tidak sesuai.';
                $this->auditSync('sekolah', 'failed', 0, $msg);
                return $this->fail($msg, 404);
            }

            $this->db->transStart();
            $sekolahId = $dataSekolah['sekolah_id'];
            
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
                'is_sks'                   => !empty($dataSekolah['is_sks']) ? 1 : 0,
                'lintang'                  => $dataSekolah['lintang'] ?? null,
                'bujur'                    => $dataSekolah['bujur'] ?? null,
                'dusun'                    => $dataSekolah['dusun'] ?? null,
                'desa_kelurahan'           => $dataSekolah['desa_kelurahan'],
                'kecamatan'                => $dataSekolah['kecamatan'],
                'kabupaten_kota'           => $dataSekolah['kabupaten_kota'],
                'provinsi'                 => $dataSekolah['provinsi'],
            ];

            $exist = $this->db->table('settings')->where('sekolah_id', $sekolahId)->get()->getRow();

            if ($exist) {
                $this->db->table('settings')->where('sekolah_id', $sekolahId)->update($prepareData);
            } else {
                $anyExist = $this->db->table('settings')->get()->getRow();
                if ($anyExist) {
                    $this->db->table('settings')->limit(1)->update($prepareData);
                } else {
                    $this->db->table('settings')->insert($prepareData);
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                $msg = 'Gagal menembus transaksi database lokal.';
                $this->auditSync('sekolah', 'failed', 0, $msg);
                return $this->fail($msg, 500);
            }

            // AUDIT BERHASIL
            $successMsg = 'Data sekolah (' . $dataSekolah['nama'] . ') berhasil disinkronisasi.';
            $this->auditSync('sekolah', 'success', 1, $successMsg);

            return $this->respond([
                'status'  => 'success', 
                'message' => $successMsg,
                'volume'  => 1
            ], 200);

        } catch (\Exception $e) {
            $this->auditSync('sekolah', 'failed', 0, $e->getMessage());
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * 2. SINKRONISASI DATA GTK
     */
    public function syncGtk()
    {
        $jsonInput = $this->request->getJSON(true);
        $vueUrl    = $jsonInput['dapodik_url'] ?? null;
        $vueToken  = $jsonInput['dapodik_token'] ?? null;

        try {
            $dapodikData = $this->fetchFromDapodik('/WebService/getGtk', $vueUrl, $vueToken);
            $rows = $dapodikData['rows'] ?? [];

            if (empty($rows)) {
                $this->auditSync('gtk', 'success', 0, 'Sinkronisasi selesai berjalan, data kosong dari Dapodik.');
                return $this->respond(['status' => 'success', 'message' => 'Tidak ada data GTK.', 'volume' => 0], 200);
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
                    'jabatan_ptk_id'            => $gtk['jabatan_ptk_id'] ?? null,
                    'jabatan_ptk_id_str'        => $gtk['jabatan_ptk_id_str'] ?? null,
                    'status_kepegawaian_id'     => $gtk['status_kepegawaian_id'],
                    'status_kepegawaian_id_str' => $gtk['status_kepegawaian_id_str'],
                    'nip'                       => $gtk['nip'],
                    'pendidikan_terakhir'       => $gtk['pendidikan_terakhir'] ?? null,
                    'bidang_studi_terakhir'     => $gtk['bidang_studi_terakhir'] ?? null,
                    'pangkat_golongan_terakhir' => $gtk['pangkat_golongan_terakhir'] ?? null
                ];

                $existGtk = $this->db->table('gtk')->where('ptk_terdaftar_id', $ptkTerdaftarId)->get()->getRow();
                if ($existGtk) {
                    $this->db->table('gtk')->where('ptk_terdaftar_id', $ptkTerdaftarId)->update($prepareGtk);
                } else {
                    $this->db->table('gtk')->insert($prepareGtk);
                }

                if (!empty($gtk['rwy_pend_formal'])) {
                    foreach ($gtk['rwy_pend_formal'] as $rwy) {
                        $rwyId = $rwy['riwayat_pendidikan_formal_id'];
                        $prepareRwy = [
                            'riwayat_pendidikan_formal_id' => $rwyId,
                            'ptk_terdaftar_id'             => $ptkTerdaftarId,
                            'satuan_pendidikan_formal'     => $rwy['satuan_pendidikan_formal'],
                            'fakultas'                     => $rwy['fakultas'] ?? null,
                            'kependidikan'                 => $rwy['kependidikan'],
                            'tahun_masuk'                  => $rwy['tahun_masuk'],
                            'tahun_lulus'                  => $rwy['tahun_lulus'],
                            'nim'                          => $rwy['nim'] ?? null,
                            'status_kuliah'                => $rwy['status_kuliah'] ?? null,
                            'semester'                     => $rwy['semester'] ?? null,
                            'ipk'                          => !empty($rwy['ipk']) ? $rwy['ipk'] : 0.00,
                            'prodi'                        => $rwy['prodi'] ?? null,
                            'id_reg_pd'                    => $rwy['id_reg_pd'] ?? null,
                            'bidang_studi_id_str'          => $rwy['bidang_studi_id_str'] ?? null,
                            'jenjang_pendidikan_id_str'    => $rwy['jenjang_pendidikan_id_str'] ?? null,
                            'gelar_akademik_id_str'        => $rwy['gelar_akademik_id_str'] ?? null
                        ];

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
                $msg = 'Gagal menyimpan batch data GTK.';
                $this->auditSync('gtk', 'failed', 0, $msg);
                return $this->fail($msg, 500);
            }

            // AUDIT BERHASIL
            $successMsg = count($rows) . ' data GTK berhasil disinkronisasi.';
            $this->auditSync('gtk', 'success', count($rows), $successMsg);

            return $this->respond([
                'status'  => 'success', 
                'message' => $successMsg,
                'volume'  => count($rows)
            ], 200);

        } catch (\Exception $e) {
            $this->auditSync('gtk', 'failed', 0, $e->getMessage());
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * 3. SINKRONISASI DATA ROMBONGAN BELAJAR
     */
    public function syncRombonganBelajar()
    {
        $jsonInput = $this->request->getJSON(true);
        $vueUrl    = $jsonInput['dapodik_url'] ?? null;
        $vueToken  = $jsonInput['dapodik_token'] ?? null;

        try {
            $dapodikData = $this->fetchFromDapodik('/WebService/getRombonganBelajar', $vueUrl, $vueToken);
            $rows = $dapodikData['rows'] ?? [];

            if (empty($rows)) {
                $this->auditSync('rombel', 'success', 0, 'Sinkronisasi selesai berjalan, data rombel kosong.');
                return $this->respond(['status' => 'success', 'message' => 'Data Rombel kosong.', 'volume' => 0], 200);
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

                $existRombel = $this->db->table('rombongan_belajar')->where('rombongan_belajar_id', $rombelId)->get()->getRow();
                if ($existRombel) {
                    $this->db->table('rombongan_belajar')->where('rombongan_belajar_id', $rombelId)->update($prepareRombel);
                } else {
                    $this->db->table('rombongan_belajar')->insert($prepareRombel);
                }

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
                $msg = 'Gagal melakukan transaksi batch data Rombel.';
                $this->auditSync('rombel', 'failed', 0, $msg);
                return $this->fail($msg, 500);
            }

            // AUDIT BERHASIL
            $successMsg = count($rows) . ' data Rombel berhasil disinkronisasi.';
            $this->auditSync('rombel', 'success', count($rows), $successMsg);

            return $this->respond([
                'status'  => 'success', 
                'message' => $successMsg,
                'volume'  => count($rows)
            ], 200);

        } catch (\Exception $e) {
            $this->auditSync('rombel', 'failed', 0, $e->getMessage());
            return $this->fail($e->getMessage(), 500);
        }
    }

    /**
     * 4. SINKRONISASI DATA SISWA
     */
    public function syncSiswa()
    {
        $jsonInput = $this->request->getJSON(true);
        $vueUrl    = $jsonInput['dapodik_url'] ?? null;
        $vueToken  = $jsonInput['dapodik_token'] ?? null;

        try {
            $dapodikData = $this->fetchFromDapodik('/WebService/getPesertaDidik', $vueUrl, $vueToken);
            $rows = $dapodikData['rows'] ?? [];

            if (empty($rows)) {
                $this->auditSync('siswa', 'success', 0, 'Sinkronisasi selesai berjalan, data siswa kosong.');
                return $this->respond(['status' => 'success', 'message' => 'Data Siswa kosong.', 'volume' => 0], 200);
            }

            $this->db->transStart();

            foreach ($rows as $siswa) {
                $nisn = $siswa['nisn'];
                // if (empty($nisn)) continue;

                // A. Master Siswa
                $prepareSiswa = [
                    'nisn'             => $nisn,
                    'peserta_didik_id' => $siswa['peserta_didik_id'],
                    'nipd'             => $siswa['nipd'] ?? null,
                    'nik'              => $siswa['nik'] ?? null,
                    'nama_lengkap'     => $siswa['nama'],
                    'jenis_kelamin'    => $siswa['jenis_kelamin'],
                    'tempat_lahir'     => $siswa['tempat_lahir'],
                    'tanggal_lahir'    => !empty($siswa['tanggal_lahir']) ? $siswa['tanggal_lahir'] : null,
                    'agama'            => $siswa['agama_id_str'] ?? null,
                    'anak_keberapa'    => $siswa['anak_keberapa'] ?? 1,
                    'tinggi_badan'     => $siswa['tinggi_badan'] ?? 0,
                    'berat_badan'      => $siswa['berat_badan'] ?? 0,
                    'no_hp'            => $siswa['nomor_telepon_seluler'] ?? null,
                    'email'            => $siswa['email'] ?? null,
                    'status_siswa'     => 'Aktif'
                ];

                $existSiswa = $this->db->table('siswa')->where('nisn', $nisn)->get()->getRow();
                if ($existSiswa) {
                    $this->db->table('siswa')->where('nisn', $nisn)->update($prepareSiswa);
                } else {
                    $this->db->table('siswa')->insert($prepareSiswa);
                }

                // B. Orang Tua
                $prepareOrangTua = [
                    'nisn'           => $nisn,
                    'nama_ayah'      => $siswa['nama_ayah'] ?? null,
                    'pekerjaan_ayah' => $siswa['pekerjaan_ayah_id_str'] ?? null,
                    'nama_ibu'       => $siswa['nama_ibu'] ?? null,
                    'pekerjaan_ibu'  => $siswa['pekerjaan_ibu_id_str'] ?? null,
                    'nama_wali'      => $siswa['nama_wali'] ?? null,
                    'pekerjaan_wali' => $siswa['pekerjaan_wali_id_str'] ?? null,
                ];

                $existOrtu = $this->db->table('siswa_orangtua')->where('nisn', $nisn)->get()->getRow();
                if ($existOrtu) {
                    $this->db->table('siswa_orangtua')->where('nisn', $nisn)->update($prepareOrangTua);
                } else {
                    $this->db->table('siswa_orangtua')->insert($prepareOrangTua);
                }

                // C. Registrasi Riwayat Sekolah
                $semesterId = $siswa['semester_id'] ?? '20252'; 
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

                $existReg = $this->db->table('siswa_registrasi')
                                     ->where('nisn', $nisn)
                                     ->where('semester_id', $semesterId)
                                     ->get()->getRow();
                if ($existReg) {
                    $this->db->table('siswa_registrasi')->where('id', $existReg->id)->update($prepareRegistrasi);
                } else {
                    $this->db->table('siswa_registrasi')->insert($prepareRegistrasi);
                }
            }

            $this->db->transComplete();

            if ($this->db->transStatus() === false) {
                $msg = 'Gagal melakukan transaksi database siswa.';
                $this->auditSync('siswa', 'failed', 0, $msg);
                return $this->fail($msg, 500);
            }

            // AUDIT BERHASIL
            $successMsg = count($rows) . ' data Siswa komplit berhasil disinkronisasi.';
            $this->auditSync('siswa', 'success', count($rows), $successMsg);

            return $this->respond([
                'status'  => 'success', 
                'message' => $successMsg,
                'volume'  => count($rows)
            ], 200);

        } catch (\Exception $e) {
            $this->auditSync('siswa', 'failed', 0, $e->getMessage());
            return $this->fail($e->getMessage(), 500);
        }
    }
}