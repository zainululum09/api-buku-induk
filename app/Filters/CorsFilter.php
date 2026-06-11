<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

class CorsFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? 'http://localhost:5173';

        // Set Header CORS secara agresif langsung ke native PHP
        header("Access-Control-Allow-Origin: " . $origin);
        header("Access-Control-Allow-Headers: X-API-KEY, Origin, X-Requested-With, Content-Type, Accept, Authorization, Access-Control-Allow-Headers");
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Credentials: true");

        // Jika request adalah preflight (OPTIONS), langsung potong kompas di sini!
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            header("HTTP/1.1 200 OK");
            exit(); // Hentikan script detik ini juga khusus untuk request OPTIONS
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Tidak perlu aksi lanjutan
    }
}