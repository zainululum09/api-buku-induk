<?php

namespace App\Controllers;

use App\Controllers\BaseController;
use CodeIgniter\API\ResponseTrait;

class UserSettingController extends BaseController
{
    use ResponseTrait;
    protected $db;

    public function __construct()
    {
        $this->db = \Config\Database::connect();
    }

    public function index()
    {
        $data = $this->db->table('users u')
            ->select('u.id, u.username, u.nama_lengkap, u.role_id, u.ptk_id, u.status_akun, r.nama_role, rb.nama as nama_kelas')
            ->join('roles r', 'u.role_id = r.id', 'inner')
            ->join('rombongan_belajar rb', 'u.rombongan_belajar_id = rb.rombongan_belajar_id', 'left')
            ->get()
            ->getResultArray();

        return $this->response->setJSON([
            'status' => 'success',
            'data'   => $data
        ]);
    }

    // =========================================================================
    // 1. MANAJEMEN USERS (Pengguna)
    // =========================================================================
    
    public function getUsers()
    {
        $users = $this->db->table('users')
                ->select('
                    users.id, 
                    users.username, 
                    users.nama_lengkap, 
                    users.status_akun, 
                    users.role_id, 
                    roles.nama_role, 
                    users.created_at,
                    rombongan_belajar.nama as nama_rombel
                ')
                ->join('roles', 'roles.id = users.role_id')
                // Gunakan 'left' join agar akun yang tidak punya rombel (Admin/TU) tidak hilang dari daftar
                // Sesuaikan 'rombongan_belajar.id_gtk = users.id' dengan nama kolom foreign key di database Anda
                ->join('rombongan_belajar', 'rombongan_belajar.ptk_id = users.ptk_id', 'left')
                
                // Urutkan berdasarkan nama rombel terlebih dahulu
                ->orderBy('rombongan_belajar.nama', 'ASC')
                ->orderBy('users.role_id', 'ASC')
                ->orderBy('users.nama_lengkap', 'ASC')
                ->get()
                ->getResultArray();

        return $this->respond([
            'status'  => 'success',
            'data'    => $users
        ], 200);
    }

    public function saveUser()
    {
        $id = $this->request->getVar('id');
        
        // 1. ATURAN VALIDASI DINAMIS
        // Jika ada ID (Update), is_unique akan mengecualikan ID tersebut agar tidak bentrok dengan dirinya sendiri
        $rules = [
            'username'     => "required|regex_match[/^[a-zA-Z0-9_ -]+$/]|min_length[3]|is_unique[users.username,id,{$id}]",
            'nama_lengkap' => 'required|min_length[3]',
            'role_id'      => 'required|is_not_unique[roles.id]',
            'status_akun'  => 'required|in_list[Aktif,Nonaktif]'
        ];

        // Password hanya wajib jika membuat User Baru ATAU ketika Update mendeteksi input password diisi (Ganti Password)
        if (empty($id) || $this->request->getVar('password')) {
            $rules['password'] = 'required|min_length[6]';
        }

        if (!$this->validate($rules)) {
            return $this->failValidationErrors($this->validator->getErrors());
        }

        // 2. PREPARASI DATA PAYLOAD
        $data = [
            'username'     => $this->request->getVar('username'),
            'nama_lengkap' => $this->request->getVar('nama_lengkap'),
            'role_id'      => $this->request->getVar('role_id'),
            'status_akun'  => $this->request->getVar('status_akun'),
        ];

        // Enkripsi password jika dilempar dari frontend
        if ($this->request->getVar('password')) {
            $data['password'] = password_hash($this->request->getVar('password'), PASSWORD_BCRYPT);
        }

        // 3. LOGIKA UPSERT (Update or Insert)
        $builder = $this->db->table('users');

        if (!empty($id) && is_numeric($id)) {
            // Aksi UPDATE jika ID ditemukan
            $builder->where('id', $id)->update($data);
            $message = "Data user '{$data['username']}' berhasil diperbarui.";
        } else {
            // Aksi INSERT jika ID kosong/baru
            $builder->insert($data);
            $message = "User baru '{$data['username']}' berhasil ditambahkan ke sistem.";
        }

        return $this->respond([
            'status'  => 'success',
            'message' => $message
        ], 200);
    }

    public function deleteUser($id)
    {
        // Mencegah superadmin menghapus dirinya sendiri
        if ($id == 1) {
            return $this->fail('User master superadmin tidak boleh dihapus.', 400);
        }

        $this->db->table('users')->where('id', $id)->delete();
        return $this->respond(['status' => 'success', 'message' => 'User berhasil dihapus.'], 200);
    }

    // =========================================================================
    // 2. MANAJEMEN ROLES & HAK AKSES MENU (`role_menu`)
    // =========================================================================

    public function getRoles()
    {
        $roles = $this->db->table('roles')->orderBy('id', 'ASC')->get()->getResultArray();
        return $this->respond(['status' => 'success', 'data' => $roles], 200);
    }

    /**
     * Mengambil seluruh daftar menu beserta status checklist akses untuk Role tertentu
     */
    public function getRolePermissions($roleId)
    {
        // 1. Ambil semua menu terdaftar di sistem
        $allMenus = $this->db->table('menus')->get()->getResultArray();
        
        // 2. Ambil id menu yang diizinkan untuk role ini dari tabel pivot role_menu
        $allowedMenusRaw = $this->db->table('role_menu')
            ->where('role_id', $roleId)
            ->get()
            ->getResultArray();
            
        $allowedMenuIds = array_column($allowedMenusRaw, 'menu_id');

        // 3. Gabungkan datanya agar Vue tinggal render checklist true/false
        $matrix = [];
        foreach ($allMenus as $menu) {
            $matrix[] = [
                'menu_id'   => (int)$menu['id'],
                'parent_id' => $menu['parent_id'] ? (int)$menu['parent_id'] : null,
                'nama_menu' => $menu['nama_menu'],
                'url_route' => $menu['url_route'],
                'icon'      => $menu['icon'],
                'is_granted'=> in_array($menu['id'], $allowedMenuIds) // Boolean true/false untuk checkbox v-model Vue
            ];
        }

        return $this->respond([
            'status' => 'success',
            'data'   => $matrix
        ], 200);
    }

    /**
     * Menyimpan perubahan hak akses (Sync tabel pivot role_menu)
     */
    public function saveRolePermissions()
    {
        $roleId = $this->request->getVar('role_id');
        $menuIds = $this->request->getVar('menu_ids'); // Ekspektasi: Array ID Menu yang di-check [1, 2, 3, 5]

        if (empty($roleId) || !is_array($menuIds)) {
            return $this->fail('Payload data tidak valid atau menu belum dipilih.', 400);
        }

        $this->db->transStart();

        // 1. Hapus semua akses menu lama untuk role ini
        $this->db->table('role_menu')->where('role_id', $roleId)->delete();

        // 2. Re-insert menu baru yang diberikan izin akses
        $insertData = [];
        foreach ($menuIds as $menuId) {
            $insertData[] = [
                'role_id' => $roleId,
                'menu_id' => $menuId
            ];
        }

        if (!empty($insertData)) {
            $this->db->table('role_menu')->insertBatch($insertData);
        }

        $this->db->transComplete();

        if ($this->db->transStatus() === FALSE) {
            return $this->fail('Gagal memperbarui hak akses menu pada database.', 500);
        }

        return $this->respond([
            'status'  => 'success',
            'message' => 'Hak akses menu berhasil dikonfigurasi.'
        ], 200);
    }

    /**
     * POST /api/v1/setting/users/sync-walikelas
     * Generate otomatis akun user untuk Wali Kelas dari data Rombel & GTK
     */
    public function syncWaliKelas()
    {
        // 1. Ambil ID Role untuk Wali Kelas (Sesuaikan dengan table roles Anda)
        $roleWaliKelasId = 3; 

        // 2. Ambil data guru yang menjadi Wali Kelas aktif langsung via Query Builder
        $builderRombel = $this->db->table('rombongan_belajar rb');
        $builderRombel->select('rb.rombongan_belajar_id, rb.nama as nama_kelas, rb.ptk_id, g.nama as nama_guru, g.nik, g.tanggal_lahir');
        $builderRombel->join('gtk g', 'rb.ptk_id = g.ptk_id', 'inner');
        $walikelasDapodik = $builderRombel->get()->getResultArray();

        if (empty($walikelasDapodik)) {
            // Jika pakai BaseController biasa, gunakan setJSON dengan status code 404
            return $this->response->setJSON([
                'status'  => 'error',
                'message' => "Tidak ada data wali kelas yang ditemukan di tabel Rombongan Belajar."
            ])->setStatusCode(404);
        }

        $insertedCount = 0;
        $updatedCount = 0;

        foreach ($walikelasDapodik as $row) {
            // Bersihkan nama untuk dijadikan username default (huruf kecil, tanpa spasi/gelar)
            $cleanUsername = strtolower(str_replace(' ', '_', preg_replace('/[^A-Za-z0-9 ]/', '', $row['nama_guru'])));
            
            // Password default menggunakan tanggal lahir tanpa strip (Contoh: 1979-11-14 -> 19791114)
            $defaultPassword = !empty($row['tanggal_lahir']) ? str_replace('-', '', $row['tanggal_lahir']) : '123456';
            $hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

            // Cek apakah user dengan ptk_id ini sudah punya akun sebelumnya
            $userCheck = $this->db->table('users')->where('ptk_id', $row['ptk_id'])->get()->getRowArray();

            // Siapkan data payload (Gunakan format date Y-m-d H:i:s yang benar)
            $dataPayload = [
                'nama_lengkap'         => $row['nama_guru'] . " (Walas " . $row['nama_kelas'] . ")",
                'role_id'              => $roleWaliKelasId,
                'status_akun'          => 'Aktif',
                'rombongan_belajar_id' => $row['rombongan_belajar_id'], 
                'updated_at'           => date('Y-m-d H:i:s') // <-- Perbaikan format di sini
            ];

            if ($userCheck) {
                // UPDATE data langsung ke tabel users tanpa Model
                $this->db->table('users')->where('id', $userCheck['id'])->update($dataPayload);
                $updatedCount++;
            } else {
                // INSERT data baru jika belum ada akun
                $dataPayload['ptk_id']   = $row['ptk_id'];
                $dataPayload['username'] = $cleanUsername;
                $dataPayload['password'] = $hashedPassword; 
                $dataPayload['created_at'] = date('Y-m-d H:i:s');
                
                // Cek duplikasi username di tabel users
                $usernameExist = $this->db->table('users')->where('username', $cleanUsername)->countAllResults();
                if ($usernameExist > 0) {
                    // Jika bentrok, tambahkan nama kelas dibelakangnya (misal: siti_jubaidah_7f)
                    $dataPayload['username'] = $cleanUsername . '_' . strtolower(str_replace(' ', '', $row['nama_kelas']));
                }

                $this->db->table('users')->insert($dataPayload);
                $insertedCount++;
            }
        }

        // Mengembalikan response sukses tanpa dependensi ResourceController trait
        return $this->response->setJSON([
            'status'  => 'success',
            'message' => "Sinkronisasi Akun Wali Kelas berhasil. Berhasil membuat {$insertedCount} akun baru dan memperbarui {$updatedCount} akun wali kelas."
        ])->setStatusCode(200);
    }
}