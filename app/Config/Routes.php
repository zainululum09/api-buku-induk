<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// 1. GERBANG UTAMA API (v1)
$routes->group('api/v1', function($routes) {
    
    // =========================================================================
    // A. JALUR TERBUKA (Public - Bisa diakses siapa saja / sebelum login)
    // =========================================================================
    $routes->post('auth/login', 'AuthController::login');
    $routes->get('setting/sekolah', 'SettingController::getNamaSekolah');

    // =========================================================================
    // B. JALUR TERKUNCI (Protected - Wajib lolos filter 'isAuth')
    // =========================================================================
    $routes->group('', ['filter' => 'isAuth'], function($routes) {
        
        // Autentikasi Internal
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('auth/me', 'AuthController::checkSession');
        
        // Kelompok Rute Sinkronisasi Dapodik (Sekarang aman di dalam dekapan isAuth)
        $routes->post('dapodik/sync-sekolah', 'DapodikController::syncSekolah');
        $routes->post('dapodik/sync-gtk', 'DapodikController::syncGtk');
        $routes->post('dapodik/sync-rombel', 'DapodikController::syncRombonganBelajar');
        $routes->post('dapodik/sync-siswa', 'DapodikController::syncSiswa');
        
    });

});