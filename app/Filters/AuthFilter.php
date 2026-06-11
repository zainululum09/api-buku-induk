<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class AuthFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Cek apakah di session server, status login bernilai true
        if (!session()->get('is_logged_in')) {
            // Jika belum login, langsung hadang dan kembalikan JSON error 401
            $response = service('response');
            $response->setStatusCode(401);
            $response->setContentType('application/json');
            $response->setBody(json_encode([
                'status'  => 'error',
                'message' => 'Akses ditolak. Silakan login terlebih dahulu.'
            ]));
            
            // Kirim response CORS agar browser frontend tidak error cors saat ditolak
            $origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:5173';
            header("Access-Control-Allow-Origin: " . $origin);
            header("Access-Control-Allow-Credentials: true");
            
            return $response;
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak ada aksi setelah request
    }
}