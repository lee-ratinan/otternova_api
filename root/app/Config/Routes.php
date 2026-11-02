<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Uploaded images, as saved by /v1/staff/image-upload and /v1/client/image-upload; see App\Controllers\Images
$routes->get('image/(:segment)/(:segment)', 'Images::show/$1/$2');

// Version 1 of the API: every endpoint the apps call lives under /v1
$routes->group('v1', static function (RouteCollection $routes) {
    // Sign-in for the Admin (staff) and Client apps; see App\Controllers\Auth
    $routes->post('login', 'Auth::login');
    $routes->post('verify-otp', 'Auth::verifyOtp');
    $routes->post('change-expired-password', 'Auth::changeExpiredPassword');
    $routes->post('logout', 'Auth::logout', ['filter' => 'jwt']);

    // Client app sign-up: no sign-in needed; see App\Controllers\Auth
    $routes->post('create-client-account', 'Auth::createClientAccount');
    $routes->post('activate-client-account', 'Auth::activateClientAccount');

    // Forgotten password, for either app: no sign-in needed; see App\Controllers\Auth
    $routes->post('request-password-reset', 'Auth::requestPasswordReset');
    $routes->post('check-password-reset', 'Auth::checkPasswordReset');
    $routes->post('reset-password', 'Auth::resetPassword');

    // Admin app: staff accounts only (a valid token with typ S)
    $routes->group('staff', ['filter' => 'jwt:S'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        $routes->post('accounts', 'Accounts::list');
        $routes->post('account', 'Accounts::show');
        $routes->post('account/update', 'Accounts::save');
        $routes->post('account/delete', 'Accounts::delete');
        $routes->post('departments', 'Accounts::departments');
        $routes->post('staff-members', 'Accounts::staffMembers');
        $routes->post('image-upload', 'Images::upload');
    });

    // Client app: client accounts only (a valid token with typ C)
    $routes->group('client', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        $routes->post('image-upload', 'Images::upload');
    });
});
