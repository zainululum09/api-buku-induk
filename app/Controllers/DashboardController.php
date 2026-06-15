<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class DashboardController extends BaseController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * Mengambil seluruh ringkasan statistik dan log audit riwayat 
     * dalam 1 kali request (High Performance) untuk Dashboard Vue 3
     */
    public function getSummary()
    {
        try {
            // =========================================================================
            // 1. STATISTIK DATA SEKOLAH
            // =========================================================================
            $sekolah = $this->db->table('settings')
                                ->select('nama_sekolah, npsn')
                                ->get()
                                ->getRowArray();
            
            $statSekolah = [
                'nama' => $sekolah['nama_sekolah'] ?? 'Belum Sinkronisasi',
                'npsn' => $sekolah['npsn'] ?? '-'
            ];

            // =========================================================================
            // 2. STATISTIK GURU / GTK
            // =========================================================================
            $totalGtk = $this->db->table('gtk')->countAllResults();
            $indukGtk = $this->db->table('gtk')->where('ptk_induk', 1)->countAllResults();
            
            $statGtk = [
                'total' => $totalGtk,
                'induk' => $indukGtk
            ];

            // =========================================================================
            // 3. STATISTIK ROMBEL / KELAS
            // =========================================================================
            $totalRombel = $this->db->table('rombongan_belajar')->countAllResults();
            $activeRombel = $this->db->table('rombongan_belajar')
                                     ->select('semester_id')
                                     ->orderBy('semester_id', 'DESC')
                                     ->get()
                                     ->getRowArray();
            
            $statRombel = [
                'total' => $totalRombel,
                'active_semester' => $activeRombel['semester_id'] ?? '-'
            ];

            // =========================================================================
            // 4. STATISTIK SISWA BUKU INDUK (Berdasarkan Jenis Kelamin)
            // =========================================================================
            $totalSiswa = $this->db->table('siswa')->countAllResults();
            $siswaLaki  = $this->db->table('siswa')->where('jenis_kelamin', 'L')->countAllResults();
            $siswaPerem = $this->db->table('siswa')->where('jenis_kelamin', 'P')->countAllResults();
            
            $statSiswa = [
                'total' => $totalSiswa,
                'laki'  => $siswaLaki,
                'perempuan' => $siswaPerem
            ];

            // =========================================================================
            // 5. PERBAIKAN: AMBIL RIWAYAT LOG AUDIT SINKRONISASI (10 Terakhir)
            // Mengubah nama tabel ke 'sync_logs' dan kolom order ke 'created_at'
            // =========================================================================
            $historySync = $this->db->table('sync_logs')
                                    ->orderBy('created_at', 'DESC')
                                    ->limit(10)
                                    ->get()
                                    ->getResultArray();

            // =========================================================================
            // 6. AMBIL RIWAYAT AUDIT LOGIN USER (10 Terakhir)
            // =========================================================================
            $historyLoginRaw = $this->db->table('audit_login')
                                       ->orderBy('login_at', 'DESC')
                                       ->limit(10)
                                       ->get()
                                       ->getResultArray();
            
            // Kita tandai mana session login yang sedang dipakai oleh user saat ini
            $currentIp = $this->request->getIPAddress();
            $historyLogin = [];
            foreach ($historyLoginRaw as $login) {
                $login['is_current'] = ($login['ip_address'] === $currentIp);
                
                // Rapikan string user agent agar pendek di dashboard
                if (strpos($login['user_agent'], 'Chrome') !== false) {
                    $login['user_agent'] = 'Chrome Browser';
                } elseif (strpos($login['user_agent'], 'Firefox') !== false) {
                    $login['user_agent'] = 'Mozilla Firefox';
                } else {
                    $login['user_agent'] = 'Web Browser';
                }
                
                $historyLogin[] = $login;
            }

            // =========================================================================
            // KOMPILASI DATA RESPONS GABUNGAN
            // =========================================================================
            return $this->respond([
                'status'  => 'success',
                'message' => 'Ringkasan data dashboard berhasil dimuat.',
                'data'    => [
                    'stats'         => [
                        'sekolah' => $statSekolah,
                        'gtk'     => $statGtk,
                        'rombel'  => $statRombel,
                        'siswa'   => $statSiswa
                    ],
                    'history_sync'  => $historySync,
                    'history_login' => $historyLogin
                ]
            ], 200);

        } catch (\Exception $e) {
            return $this->fail('Kesalahan database internal: ' . $e->getMessage(), 500);
        }
    }
}