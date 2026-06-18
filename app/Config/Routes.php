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
        
        // URL Umum / Public untuk semua yang sudah login
        $routes->post('auth/logout', 'AuthController::logout');
        $routes->get('auth/me', 'AuthController::checkSession');
        $routes->get('dashboard/summary', 'DashboardController::getSummary');

        // =========================================================================
        // C. JALUR OTORISASI DATABASE (Filter 'isGranted' Polosan)
        //    *Struktur grup di bawah ini disamakan persis dengan router.js*
        // =========================================================================
        $routes->group('', ['filter' => 'isGranted'], function($routes) {
            
            // 1. Jalur Match dengan Frontend Path: /master/dapodik
            $routes->group('master/dapodik', function($routes) {
                $routes->get('', 'SettingController::getSetting');
                $routes->post('save', 'SettingController::saveDapodikConfig');
                $routes->get('sync-history', 'SettingController::getSyncHistory');
                $routes->post('sync-sekolah', 'DapodikController::syncSekolah');
                $routes->post('sync-gtk', 'DapodikController::syncGtk');
                $routes->post('sync-rombel', 'DapodikController::syncRombonganBelajar');
                $routes->post('sync-siswa', 'DapodikController::syncSiswa');
            });

            // 2. Jalur Match dengan Frontend Path: /kesiswaan/tatatertib
            $routes->group('kesiswaan/tatatertib', function($routes) {
                $routes->get('', 'TataTertibController::index');
            });

            // 3. Jalur Match dengan Frontend Path: /kesiswaan/kasus
            $routes->group('kesiswaan/kasus', function($routes) {
                $routes->get('', 'SiswaController::getKasus');
                $routes->get('search-siswa', 'SiswaController::search'); // jika pencarian siswa ada di dalam form kasus
                $routes->post('store', 'SiswaController::storeKasus');
                $routes->put('update/(:num)', 'SiswaController::updateKasus/$1');
                $routes->delete('delete/(:num)', 'SiswaController::deleteKasus/$1');
            });

            // 4. Jalur Match dengan Frontend Path: /usersetting
            $routes->group('usersetting', function($routes) {
                $routes->get('users', 'UserSettingController::getUsers');
                $routes->post('users/save', 'UserSettingController::saveUser');
                $routes->delete('users/delete/(:num)', 'UserSettingController::deleteUser/$1');
                $routes->post('users/sync-walikelas', 'UserSettingController::syncWaliKelas');
                
                $routes->get('roles', 'UserSettingController::getRoles');
                $routes->get('permissions/(:num)', 'UserSettingController::getRolePermissions/$1');
                $routes->post('permissions/save', 'UserSettingController::saveRolePermissions');
            });

        });
    });

});