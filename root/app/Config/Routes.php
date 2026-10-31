<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

// Sign-in for the Admin (staff) and Client apps; see App\Controllers\Auth
$routes->post('login', 'Auth::login');
$routes->post('verify-otp', 'Auth::verifyOtp');
$routes->post('logout', 'Auth::logout', ['filter' => 'jwt']);
