<?php

namespace App\Controllers;

use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

class ApiController extends Controller
{
    protected $request;
    
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        parent::initController($request, $response, $logger);
    }

    /**
     * Helper untuk Response Sukses (200 OK)
     */
    protected function respondSuccess($data = null, string $message = 'Success')
    {
        return $this->response->setJSON([
            'status'  => 'success',
            'message' => $message,
            'data'    => $data
        ])->setStatusCode(200);
    }

    /**
     * Helper untuk Response Gagal/Error
     */
    protected function respondError(string $message = 'Error occurred', int $statusCode = 400)
    {
        return $this->response->setJSON([
            'status'  => 'error',
            'message' => $message,
            'data'    => null
        ])->setStatusCode($statusCode);
    }
}