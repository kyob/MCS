<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */
$routes->get('/', 'Home::index');

$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::loginSubmit');
$routes->get('logout', 'Auth::logout');

// Chronione sesją (filtr mcsAuth w Config\Filters). Treść IP/admin: Task 6 i 8.
$routes->get('ip/(:segment)', 'Ip::show/$1');
$routes->get('admin/pluginy', 'Admin\Pluginy::index');
$routes->get('admin', 'Admin\Pluginy::index');

// API badge — token serwerowy (filtr mcsBadgeToken); CSRF wyłączone dla api/* .
$routes->get('api/badge', 'Api\Badge::index');

