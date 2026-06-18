<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait; // 1. Pastikan ini di-import

class SettingController extends BaseController
{
    use ResponseTrait; // 2. Pastikan trait ini digunakan di dalam class

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function getNamaSekolah()
    {
        try {       
            // PERBAIKAN: Gunakan $this->db (bukan $db polos)
            $sekolah = $this->db->table('settings')
                                ->select('nama_sekolah')
                                ->get()
                                ->getRowArray();

            if ($sekolah && !empty($sekolah['nama_sekolah'])) {
                // PERBAIKAN: Gunakan respond() standar CI4
                return $this->respond([
                    'status'  => 'success',
                    'message' => 'Data ditemukan.',
                    'data'    => ['nama_sekolah' => $sekolah['nama_sekolah']]
                ], 200);
            }

            // PERBAIKAN: Gunakan fail() bawaan CI4 untuk error 404
            return $this->fail('Nama sekolah belum diatur.', 404);
            
        } catch (\Exception $e) {
            // PERBAIKAN: Gunakan fail() bawaan CI4 untuk error 500
            // Anda juga bisa menyelipkan $e->getMessage() jika ingin melacak log error-nya saat debug
            return $this->fail('Database error atau tabel settings belum siap.', 500);
        }
    }

    /**
     * Ambil Konfigurasi Sekolah + Dapodik
     */
    public function getSetting()
    {
        $setting = $this->db->table('settings')->get()->getRowArray();
        
        return $this->respond([
            'status' => 'success',
            'data'   => $setting
        ], 200);
        }
        
    public function getSyncHistory()
    {
            
            $sync_logs = $this->db->table('sync_logs')
                        ->orderBy("created_at","DESC")
                        ->get()->getResult();
            
            return $this->respond([
                'status' => 'success',
                'data'   => $sync_logs
            ], 200);
    }

    /**
     * Simpan/Update Konfigurasi Dapodik secara Otomatis
     */
    public function saveDapodikConfig()
    {
        $json = $this->request->getJSON();
        
        $url   = $json->dapodik_url ?? '';
        $npsn = $json->npsn ?? '';
        $token = $json->dapodik_token ?? '';

        if (empty($url) || empty($token)) {
            return $this->fail('URL dan Token Dapodik tidak boleh kosong.', 400);
        }

        // 1. Cek apakah sudah ada baris data di tabel settings
        $builder = $this->db->table('settings');
        $check   = $builder->get()->getRow();

        $dataPayload = [
            'dapodik_url'   => $url,
            'npsn' => $npsn,
            'dapodik_token' => $token
        ];

        if ($check) {
            // 2. JIKA SUDAH ADA DATA: Lakukan update spesifik ke id tersebut (misal id = 1)
            // Jika primary key Anda bukan 'id', sesuaikan dengan nama kolom primary key tabel settings Anda
            $primaryKey = isset($check->id) ? 'id' : (isset($check->id_setting) ? 'id_setting' : null);
            
            if ($primaryKey) {
                $builder->where($primaryKey, $check->$primaryKey)->update($dataPayload);
            } else {
                // Jika tidak ada primary key sama sekali, paksa update row pertama yang ditemukan
                $builder->limit(1)->update($dataPayload);
            }
            
            $message = 'Konfigurasi WebService Dapodik berhasil diperbarui.';
        } else {
            // 3. JIKA TABEL MASIH KOSONG MELONGPONG: Lakukan insert record pertama
            $builder->insert($dataPayload);
            $message = 'Konfigurasi WebService Dapodik berhasil ditambahkan sebagai record baru.';
        }

        return $this->respond([
            'status'  => 'success',
            'message' => $message
        ], 200);
    }

}