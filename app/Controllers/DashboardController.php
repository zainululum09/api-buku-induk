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
            // [BARU] 4B. REKAPITULASI DETAIL DATA SISWA (PER KELAS & PER TINGKAT)
            // =========================================================================
            
            // 1. Rekap Per Kelas Paralel
            // Asumsi: tabel siswa memiliki relasi/kolom 'nama_kelas' atau 'rombongan_belajar'
            // Kita gunakan conditional aggregation (SUM CASE) untuk performa cepat database
            $rekapKelasRaw = $this->db->table('siswa')
                ->select("
                    siswa_registrasi.nama_rombel as nama_kelas,
                    SUM(CASE WHEN siswa.jenis_kelamin = 'L' THEN 1 ELSE 0 END) as laki,
                    SUM(CASE WHEN siswa.jenis_kelamin = 'P' THEN 1 ELSE 0 END) as perempuan,
                    COUNT(*) as total
                ")
                // Lakukan JOIN ke tabel siswa_registrasi (sesuaikan kolom 'siswa_id' jika nama kolomnya berbeda)
                ->join('siswa_registrasi', 'siswa_registrasi.nisn = siswa.nisn')
                ->groupBy('siswa_registrasi.nama_rombel')
                ->orderBy('siswa_registrasi.nama_rombel', 'ASC')
                ->get()
                ->getResultArray();

            $rekapKelas = [];
            $rekapTingkatTmp = [];

            foreach ($rekapKelasRaw as $row) {
                // Jika nama_rombel kosong/null, beri fallback 'Tanpa Kelas'
                $namaKelas = $row['nama_kelas'] ?? 'Tanpa Kelas';
                $laki      = (int)$row['laki'];
                $perempuan = (int)$row['perempuan'];
                $total     = (int)$row['total'];

                // Ambil karakter angka di depan nama rombel untuk menentukan tingkat (Misal: "7 A" atau "VII A" -> Ambil depannya)
                $tingkat = trim(substr($namaKelas, 0, 2)); 
                if (!is_numeric($tingkat)) {
                    $tingkat = substr($namaKelas, 0, 1); // Fallback jika format "7A" rapat
                }
                if (!is_numeric($tingkat)) {
                    // Jika sekolah menggunakan penamaan Romawi seperti "VII", "VIII", "IX"
                    if (strpos(strtoupper($namaKelas), 'VII') === 0) { $tingkat = "7"; }
                    elseif (strpos(strtoupper($namaKelas), 'VIII') === 0) { $tingkat = "8"; }
                    elseif (strpos(strtoupper($namaKelas), 'IX') === 0) { $tingkat = "9"; }
                    else { $tingkat = "Lainnya"; }
                }

                $rekapKelas[] = [
                    'nama_kelas' => $namaKelas,
                    'tingkat'    => $tingkat,
                    'laki'       => $laki,
                    'perempuan'  => $perempuan,
                    'total'      => $total
                ];

                // Akumulasikan data ke rekap tingkat secara real-time di memory array
                if (!isset($rekapTingkatTmp[$tingkat])) {
                    $rekapTingkatTmp[$tingkat] = [
                        'tingkat'    => $tingkat,
                        'laki'       => 0,
                        'perempuan'  => 0,
                        'total'      => 0
                    ];
                }
                $rekapTingkatTmp[$tingkat]['laki']      += $laki;
                $rekapTingkatTmp[$tingkat]['perempuan'] += $perempuan;
                $rekapTingkatTmp[$tingkat]['total']     += $total;
            }

            // Urutkan rekap tingkat agar rapi dari kelas 7, 8, ke 9
            ksort($rekapTingkatTmp);
            $rekapTingkat = array_values($rekapTingkatTmp);

            // Rekap Keseluruhan Global
            $rekapKeseluruhan = [
                'laki'      => $siswaLaki,
                'perempuan' => $siswaPerem,
                'total'     => $totalSiswa
            ];

            // =========================================================================
            // 5. AMBIL RIWAYAT LOG AUDIT SINKRONISASI (10 Terakhir)
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
            
            $currentIp = $this->request->getIPAddress();
            $historyLogin = [];
            foreach ($historyLoginRaw as $login) {
                $login['is_current'] = ($login['ip_address'] === $currentIp);
                
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
            // KOMPILASI DATA RESPONS GABUNGAN (MEMENUHI PAYLOAD VUE)
            // =========================================================================
            return $this->respond([
                'status'  => 'success',
                'message' => 'Ringkasan data dashboard berhasil dimuat.',
                'data'    => [
                    'stats'          => [
                        'sekolah' => $statSekolah,
                        'gtk'      => $statGtk,
                        'rombel'  => $statRombel,
                        'siswa'   => $statSiswa
                    ],
                    'siswa_rekap'   => [
                        'per_kelas'   => $rekapKelas,
                        'per_tingkat' => $rekapTingkat,
                        'keseluruhan' => $rekapKeseluruhan
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