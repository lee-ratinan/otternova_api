<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Uploaded images, as saved by /v1/staff/image-upload and /v1/client/image-upload; see App\Controllers\Images
// The initials avatar shown when auth_master.avatar_path is empty; see App\Libraries\InitialsAvatar
$routes->get('image/initials-avatar/(:segment)', 'Images::initials/$1');
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
        $routes->post('image-delete', 'Images::delete');
    });

    // Admin app's internal ledger: staff of the Executive Office and Finance only; see App\Controllers\Ledger
    $routes->group('staff/ledger', ['filter' => ['jwt:S', 'department:EXE,FIN']], static function (RouteCollection $routes) {
        $routes->post('entries', 'Ledger::entries');
        $routes->post('entry', 'Ledger::entry');
        $routes->post('entry/update', 'Ledger::saveEntry');
        $routes->post('receipt-upload', 'Ledger::uploadReceipt');
        $routes->post('receipt-delete', 'Ledger::deleteReceipt');
        $routes->get('receipt/(:num)', 'Ledger::receipt/$1');
        $routes->post('accounts', 'Ledger::accounts');
        $routes->post('account/update', 'Ledger::saveAccount');
        $routes->post('categories', 'Ledger::categories');
        $routes->post('category/update', 'Ledger::saveCategory');
        $routes->post('counterparties', 'Ledger::counterparties');
        $routes->post('counterparty/update', 'Ledger::saveCounterparty');
    });

    // Client app: client accounts only (a valid token with typ C)
    $routes->group('client', ['filter' => 'jwt:C'], static function (RouteCollection $routes) {
        $routes->get('me', 'Auth::me');
        $routes->post('change-password', 'Auth::changePassword');
        $routes->post('account', 'Accounts::showOwn');
        $routes->post('account/update', 'Accounts::saveOwn');
        $routes->post('image-upload', 'Images::upload');
        $routes->post('image-delete', 'Images::delete');
    });
});
