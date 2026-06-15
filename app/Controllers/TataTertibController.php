<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class TataTertibController extends BaseController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * GET All Data Aturan (Digunakan Vue untuk list Dropdown)
     * URL: GET /api/v1/tatatertib
     */
    public function index()
    {
        $builder = $this->db->table('master_tata_tertib');
        $builder->orderBy('tingkat', 'ASC');
        $builder->orderBy('pasal', 'ASC');
        
        $query  = $builder->get();
        $aturan = $query->getResultArray();

        return $this->respond([
            'status'  => 200,
            'message' => 'Daftar master tata tertib berhasil dimuat',
            'data'    => $aturan
        ], 200);
    }

    /**
     * STORE / INSERT Aturan Baru
     * URL: POST /api/v1/tatatertib/store
     */
    public function store()
    {
        $rules = [
            'tingkat'               => 'required|numeric',
            'pasal'                 => 'required|numeric',
            'deskripsi_pelanggaran' => 'required',
            'sanksi_bawaan'         => 'required'
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $data = [
            'tingkat'               => $this->request->getVar('tingkat'),
            'pasal'                 => $this->request->getVar('pasal'),
            'deskripsi_pelanggaran' => $this->request->getVar('deskripsi_pelanggaran'),
            'sanksi_bawaan'         => $this->request->getVar('sanksi_bawaan'),
        ];

        $this->db->table('master_tata_tertib')->insert($data);

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Aturan tata tertib baru berhasil ditambahkan'
        ]);
    }

    /**
     * UPDATE Aturan Eksis
     * URL: PUT /api/v1/tatatertib/update/(:num)
     */
    public function update($id = null)
    {
        $builder = $this->db->table('master_tata_tertib');
        
        // Cek data eksis
        $cek = $builder->getWhere(['id' => $id])->getRow();
        if (!$cek) {
            return $this->failNotFound('Data aturan tidak ditemukan.');
        }

        $data = [
            'tingkat'               => $this->request->getVar('tingkat') ?? $cek->tingkat,
            'pasal'                 => $this->request->getVar('pasal') ?? $cek->pasal,
            'deskripsi_pelanggaran' => $this->request->getVar('deskripsi_pelanggaran') ?? $cek->deskripsi_pelanggaran,
            'sanksi_bawaan'         => $this->request->getVar('sanksi_bawaan') ?? $cek->sanksi_bawaan,
        ];

        $builder->where('id', $id)->update($data);

        return $this->respond([
            'status'  => 200,
            'message' => 'Aturan tata tertib berhasil diperbarui'
        ], 200);
    }

    /**
     * DELETE Aturan
     * URL: DELETE /api/v1/tatatertib/delete/(:num)
     */
    public function delete($id = null)
    {
        $builder = $this->db->table('master_tata_tertib');
        
        $cek = $builder->getWhere(['id' => $id])->getRow();
        if (!$cek) {
            return $this->failNotFound('Data aturan tidak ditemukan.');
        }

        try {
            $builder->where('id', $id)->delete();
            return $this->respondDeleted([
                'status'  => 200,
                'message' => 'Aturan tata tertib berhasil dihapus dari sistem'
            ]);
        } catch (\Exception $e) {
            // Menangkap error jika record diblokir foreign key karena sudah terikat ke tabel kasus_siswa
            return $this->fail('Aturan gagal dihapus karena sudah memiliki histori catatan kasus pada siswa.', 400);
        }
    }
}