<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Services;

class IsGranted implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $session = Services::session();
        $roleId  = $session->get('role_id');

        // 1. Bypass Mutlak untuk Superadmin (Role ID: 1)
        if ($roleId !== null && (int)$roleId === 1) {
            return;
        }

        // 2. Ambil path URL yang sedang diakses, pecah berdasarkan karakter "/"
        $currentPath = $request->getUri()->getPath(); 
        $segments = explode('/', trim($currentPath, '/'));

        // Ambil segmen kata terakhir dari URL API (misal dari 'api/v1/kasus' diambil kata 'kasus')
        $targetKeyword = end($segments); 

        if (empty($targetKeyword)) {
            return;
        }

        // 3. Hubungkan ke Database untuk memeriksa relasi menu berdasarkan role_id
        $db = \Config\Database::connect();
        
        // Ambil semua daftar url_route menu yang diizinkan untuk role ini
        $allowedMenus = $db->table('role_menu rm')
            ->select('m.url_route')
            ->join('menus m', 'rm.menu_id = m.id')
            ->where('rm.role_id', $roleId)
            ->get()
            ->getResultArray();

        $hasAccess = false;

        // 4. Lakukan pencocokan kata kunci URL ke dalam daftar menu database
        foreach ($allowedMenus as $menu) {
            if (empty($menu['url_route'])) continue;

            // Jika kata 'kasus' ditemukan di dalam string '/kesiswaan/kasus', berikan akses
            if (stripos($menu['url_route'], $targetKeyword) !== false) {
                $hasAccess = true;
                break;
            }
        }

        // 5. Jika setelah diperiksa tidak ada satupun menu yang cocok, BLOKIR!
        if (!$hasAccess) {
            return Services::response()->setJSON([
                'status'  => 'error',
                'message' => 'Akses API ditolak. Peran Anda tidak memiliki izin untuk memuat data dari fitur ini.',
                'debug'   => [
                    'role_id' => $roleId,
                    'target_keyword' => $targetKeyword
                ]
            ])->setStatusCode(403);
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null) {}
}