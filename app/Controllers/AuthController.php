<?php

namespace App\Controllers;

class AuthController extends ApiController
{
    /**
     * Endpoint: POST /api/v1/auth/login
     */
    public function login()
{
    // 1. Tangkap input JSON dari Postman / Vue.js
    $json = $this->request->getJSON();
    
    // Jika input dikirim sebagai form-data standar, kita antisipasi di sini
    $username = $json->username ?? $this->request->getVar('username');
    $password = $json->password ?? $this->request->getVar('password');

    // 2. Validasi input kosong
    if (empty($username) || empty($password)) {
        return $this->respondError('Username dan password wajib diisi.', 400);
    }

    // 3. Ambil data user + nama rolenya dari Database
    $db   = \Config\Database::connect();
    $user = $db->table('users')
               ->select('users.*, roles.nama_role')
               ->join('roles', 'roles.id = users.role_id')
               ->where('username', $username)
               ->get()
               ->getRowArray();

    // 4. Verifikasi apakah user ada dan password-nya cocok
    if (!$user || !password_verify($password, $user['password'])) {
        return $this->respondError('Username atau password salah.', 401);
    }

    // 5. Cek status keaktifan akun
    if ($user['status_akun'] !== 'Aktif') {
        return $this->respondError('Akun Anda dinonaktifkan. Hubungi Admin.', 403);
    }

    // =========================================================================
    // FITUR TAMBAHAN: PENCATATAN RIWAYAT LOGIN (AUDIT TRAIL)
    // =========================================================================
    try {
        $db->table('audit_login')->insert([
            'user_id'      => $user['id'],
            'username'     => $user['username'],
            'nama_lengkap' => $user['nama_lengkap'],
            'ip_address'   => $this->request->getIPAddress(),
            'user_agent'   => $this->request->getUserAgent()->getAgentString(),
            'login_at'     => date('Y-m-d H:i:s')
        ]);
    } catch (\Exception $e) {
        // Dibungkus try-catch agar jika tabel audit bermasalah sejenak, 
        // proses login utama admin ke dashboard tidak ikut macet.
        log_message('error', 'Gagal menulis riwayat audit_login: ' . $e->getMessage());
    }
    // =========================================================================

    // 6. JIKA LOLOS: Set data ke dalam Session PHP Server
    $sessionData = [
        'user_id'      => $user['id'],
        'username'     => $user['username'],
        'nama_lengkap' => $user['nama_lengkap'],
        'role'         => $user['nama_role'],
        'is_logged_in' => true
    ];
    session()->set($sessionData);

    // 7. Ambil daftar menu dinamis berdasarkan role untuk dikirim ke Vue.js
    $menus = $db->table('role_menu')
                ->select('menus.id, menus.parent_id, menus.nama_menu, menus.url_route, menus.icon')
                ->join('menus', 'menus.id = role_menu.menu_id')
                ->where('role_menu.role_id', $user['role_id'])
                ->orderBy('menus.parent_id', 'ASC')
                ->orderBy('menus.urutan', 'ASC')
                ->get()
                ->getResultArray();

    // 8. Bungkus data akhir (Payload)
    $payload = [
        'user'  => [
            'username'     => $user['username'],
            'nama_lengkap' => $user['nama_lengkap'],
            'role'         => $user['nama_role'],
        ],
        'menus' => $menus
    ];

    return $this->respondSuccess($payload, 'Login berhasil! Selamat datang.');
}

    /**
     * Endpoint: POST /api/v1/auth/logout
     */
    public function logout()
    {
        // Hancurkan file session di server
        session()->destroy();
        return $this->respondSuccess(null, 'Berhasil logout, sesi telah dihapus.');
    }

    /**
     * Endpoint: GET /api/v1/auth/me
     */
    public function checkSession()
    {
        // Karena filter 'isAuth' sudah menghadang duluan jika tidak login, 
        // di sini kita sudah pasti aman dan tinggal mengembalikan data session yang aktif
        $payload = [
            'username'     => session()->get('username'),
            'nama_lengkap' => session()->get('nama_lengkap'),
            'role'         => session()->get('role'),
        ];

        return $this->respondSuccess($payload, 'Sesi aktif.');
    }
}