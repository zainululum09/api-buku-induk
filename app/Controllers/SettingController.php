<?php

namespace App\Controllers;

class SettingController extends ApiController
{
    public function getNamaSekolah()
    {
        try {
            $db = \Config\Database::connect();
            
            // Mengambil baris pertama dari tabel pengaturan/sekolah Anda
            // Sesuaikan nama tabel dan kolomnya dengan database Anda nanti (misal: tabel 'settings')
            $sekolah = $db->table('settings')
                          ->select('nama_sekolah')
                          ->get()
                          ->getRowArray();

            if ($sekolah && !empty($sekolah['nama_sekolah'])) {
                return $this->respondSuccess(['nama_sekolah' => $sekolah['nama_sekolah']], 'Data ditemukan.');
            }

            // Jika di tabel datanya masih kosong
            return $this->respondError('Nama sekolah belum diatur.', 404);
            
        } catch (\Exception $e) {
            // Jika tabel belum dibuat atau ada error database, biarkan frontend menghandle fallback-nya
            return $this->respondError('Database error.', 500);
        }
    }
}