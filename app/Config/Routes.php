<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');
$routes->group('api/v1', function($routes) {
    
    // Jalur Terbuka (Public - Bisa diakses tanpa login)
    $routes->post('auth/login', 'AuthController::login');

    // Jalur Terkunci (Protected - Wajib lolos filter 'isAuth')
    $routes->group('', ['filter' => 'isAuth'], function($routes) {
        
        // Autentikasi Internal
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('auth/me', 'AuthController::checkSession');

        // Modul Dapodik (Hanya bisa diakses jika sudah login)
        $routes->get('dapodik/check-status', 'DapodikController::checkStatus');
        $routes->post('dapodik/sync', 'DapodikController::syncBatch');
        
    });
});