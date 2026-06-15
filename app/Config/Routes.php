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
        
        $routes->get('setting/dapodik', 'SettingController::getSetting');
        $routes->get('dapodik/sync-history', 'SettingController::getSyncHistory');
        $routes->post('setting/dapodik/save', 'SettingController::saveDapodikConfig');

        // Rute Dashboard Utama
        $routes->get('dashboard/summary', 'DashboardController::getSummary');

        // Semua Fitur Kesiswaan (Siswa & Kasus Pelanggaran) berada di SiswaController
        $routes->get('siswa/search', 'SiswaController::search');
        $routes->get('kasus', 'SiswaController::getKasus');
        $routes->post('kasus/store', 'SiswaController::storeKasus');
        $routes->put('siswa/kasus/update/(:num)', 'SiswaController::updateKasus/$1');
        $routes->delete('kasus/delete/(:num)', 'SiswaController::deleteKasus/$1');

        // Master Aturan Tata Tertib (Tetap di controller terpisah agar rapi)
        $routes->get('tatatertib', 'TataTertibController::index');

        // Kelompok Rute Sinkronisasi Dapodik (Sekarang aman di dalam dekapan isAuth)
        $routes->post('dapodik/sync-sekolah', 'DapodikController::syncSekolah');
        $routes->post('dapodik/sync-gtk', 'DapodikController::syncGtk');
        $routes->post('dapodik/sync-rombel', 'DapodikController::syncRombonganBelajar');
        $routes->post('dapodik/sync-siswa', 'DapodikController::syncSiswa');
        
    });

});