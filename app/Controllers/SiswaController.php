<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class SiswaController extends BaseController
{
    use ResponseTrait;

    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    /**
     * 1. Live Search Siswa (Disesuaikan dengan field induk Dapodik)
     * URL: GET /api/v1/siswa/search?q={keyword}
     */
    public function search()
    {
        $keyword = $this->request->getGet('q');

        if (empty($keyword) || strlen(trim($keyword)) <= 4) {
            return $this->respond([
                'status'  => 200,
                'message' => 'Kata kunci pencarian terlalu pendek (minimal 5 karakter)',
                'data'    => []
            ], 200);
        }

        // Membangun query pencarian join dengan siswa_registrasi untuk rombel
        $builder = $this->db->table('siswa s');
        $builder->select('s.peserta_didik_id, s.nisn, s.nama_lengkap, sr.nama_rombel');
        $builder->join('siswa_registrasi sr', 'sr.nisn = s.nisn', 'LEFT');
        $builder->where('s.status_siswa', 'Aktif'); // Hanya mencari siswa yang aktif
        
        // Grouping LIKE query agar filter status_siswa tidak terdistorsi oleh OR
        $builder->groupStart()
                ->like('s.nama_lengkap', $keyword)
                ->orLike('s.nisn', $keyword)
        ->groupEnd();
        
        $builder->limit(10);
        
        $siswa = $builder->get()->getResultArray();

        return $this->respond([
            'status'  => 200,
            'message' => 'Data pencarian siswa berhasil dimuat',
            'data'    => $siswa
        ], 200);
    }

    /**
     * 2. Ambil Semua Log Kasus Siswa (Join ke master_tata_tertib & siswa induk)
     * URL: GET /api/v1/siswa/kasus
     */
    public function getKasus()
    {
        $builder = $this->db->table('kasus_siswa ks');
        $builder->select('
            ks.id, 
            ks.peserta_didik_id, 
            ks.tata_tertib_id, 
            ks.tanggal_kejadian, 
            ks.keterangan_petugas, 
            ks.status_sanksi, 
            ks.keterangan_tindakan, 
            ks.is_ulangan,
            ks.bukti_foto,
            s.nama_lengkap, 
            s.nisn,
            sr.nama_rombel,
            mtt.tingkat, 
            mtt.pasal, 
            mtt.deskripsi_pelanggaran
        ');
        
        $builder->join('siswa s', 's.peserta_didik_id = ks.peserta_didik_id', 'INNER');
        $builder->join('siswa_registrasi sr', 'sr.nisn = s.nisn', 'LEFT');
        $builder->join('master_tata_tertib mtt', 'mtt.id = ks.tata_tertib_id', 'INNER');
        
        $builder->orderBy('ks.tanggal_kejadian', 'DESC');
        $builder->orderBy('ks.id', 'DESC');

        $logKasus = $builder->get()->getResultArray();

        return $this->respond([
            'status'  => 200,
            'message' => 'Log catatan kasus siswa berhasil dimuat',
            'data'    => $logKasus
        ], 200);
    }

    /**
     * 3. Input Kasus Siswa Baru + Upload Foto Bukti
     * URL: POST /api/v1/siswa/kasus/store
     */
    public function storeKasus()
    {
        // Tambahkan aturan validasi untuk upload file
        $rules = [
            'peserta_didik_id' => 'required|max_length[36]',
            'tata_tertib_id'   => 'required|numeric',
            'tanggal_kejadian' => 'required|valid_date[Y-m-d]',
            'status_sanksi'    => 'required',
            'bukti_foto'       => 'max_size[bukti_foto,2048]|ext_in[bukti_foto,jpg,jpeg,png,pdf]',
        ];

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        $namaFileBaru = null;
        $fileBukti = $this->request->getFile('bukti_foto');

        // Cek jika ada file yang diunggah dari kamera/galeri frontend
        if ($fileBukti && $fileBukti->isValid() && !$fileBukti->hasMoved()) {
            // Berikan nama acak yang aman agar tidak bentrok di server lokal
            $namaFileBaru = $fileBukti->getRandomName();
            // Pindahkan ke folder public/uploads/bukti_kasus/
            $fileBukti->move(FCPATH . 'uploads/bukti_kasus', $namaFileBaru);
        }

        $data = [
            'peserta_didik_id'    => $this->request->getVar('peserta_didik_id'),
            'tata_tertib_id'      => $this->request->getVar('tata_tertib_id'),
            'tanggal_kejadian'    => $this->request->getVar('tanggal_kejadian'),
            'keterangan_petugas'  => $this->request->getVar('keterangan_petugas'),
            'status_sanksi'       => $this->request->getVar('status_sanksi'),
            'keterangan_tindakan' => $this->request->getVar('keterangan_tindakan'),
            'is_ulangan'          => $this->request->getVar('is_ulangan') ?? 0,
            'bukti_foto'          => $namaFileBaru, // Simpan nama filenya ke kolom DB
            'created_by'          => 1 
        ];

        $this->db->table('kasus_siswa')->insert($data);

        return $this->respondCreated([
            'status'  => 201,
            'message' => 'Catatan kasus siswa beserta bukti lampiran berhasil disimpan'
        ]);
    }

    /**
     * 4. Update Kasus Siswa Eksis
     * URL: PUT /api/v1/siswa/kasus/update/(:num)
     */
    public function updateKasus($id = null)
    {
        $builder = $this->db->table('kasus_siswa');
        
        $kasusEksis = $builder->getWhere(['id' => $id])->getRow();
        if (!$kasusEksis) {
            return $this->failNotFound('Catatan pelanggaran tidak ditemukan.');
        }

        $data = [
            'tata_tertib_id'      => $this->request->getVar('tata_tertib_id') ?? $kasusEksis->tata_tertib_id,
            'tanggal_kejadian'    => $this->request->getVar('tanggal_kejadian') ?? $kasusEksis->tanggal_kejadian,
            'keterangan_petugas'  => $this->request->getVar('keterangan_petugas') ?? $kasusEksis->keterangan_petugas,
            'status_sanksi'       => $this->request->getVar('status_sanksi') ?? $kasusEksis->status_sanksi,
            'keterangan_tindakan' => $this->request->getVar('keterangan_tindakan') ?? $kasusEksis->keterangan_tindakan,
            'is_ulangan'          => $this->request->getVar('is_ulangan') ?? $kasusEksis->is_ulangan,
        ];

        $builder->where('id', $id)->update($data);

        return $this->respond([
            'status'  => 200,
            'message' => 'Catatan pelanggaran siswa berhasil diperbarui'
        ], 200);
    }

    /**
     * 5. Hapus Catatan Kasus Siswa
     * URL: DELETE /api/v1/siswa/kasus/delete/(:num)
     */
    public function deleteKasus($id = null)
    {
        $builder = $this->db->table('kasus_siswa');
        
        // 1. Ambil data kasus berdasarkan ID untuk memeriksa keberadaannya
        $kasusEksis = $builder->getWhere(['id' => $id])->getRow();
        if (!$kasusEksis) {
            return $this->failNotFound('Data pelanggaran tidak ditemukan.');
        }

        // 2. Cek apakah ada lampiran bukti_foto dan hapus file fisiknya jika ada
        if (!empty($kasusEksis->bukti_foto)) {
            // Sesuaikan 'uploads/kasus/' dengan lokasi direktori tempat Anda menyimpan gambar tersebut
            $filePath = ROOTPATH . 'public/uploads/bukti_kasus/' . $kasusEksis->bukti_foto; 
            
            // Opsional: Jika Anda menggunakan FCPATH, ganti menjadi: $filePath = FCPATH . 'uploads/kasus/' . $kasusEksis->bukti_foto;

            if (is_file($filePath)) {
                unlink($filePath);
            }
        }

        // 3. Hapus baris data dari tabel basis data
        $builder->where('id', $id)->delete();

        return $this->respondDeleted([
            'status'  => 200,
            'message' => 'Catatan pelanggaran siswa beserta berkas bukti berhasil dihapus dari sistem'
        ]);
    }
}