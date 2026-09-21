<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Dashboard::index');
$routes->get('dashboard', 'Dashboard::index');
$routes->get('dashboard/problematyczne-ip', 'Dashboard::problematyczneIp');
$routes->get('dashboard/kolejka', 'Dashboard::kolejka');
$routes->get('dashboard/incydenty', 'Dashboard::incydenty');
$routes->get('dashboard/speedtest', 'Dashboard::speedtest');
$routes->get('dashboard/eksport.csv', 'Dashboard::eksportCsv');
$routes->get('szukaj', 'Dashboard::szukaj');

$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::loginSubmit');
$routes->get('logout', 'Auth::logout');

// Chronione sesją (filtr mcsAuth w Config\Filters).
$routes->get('ip/(:segment)', 'Ip::show/$1');
$routes->get('ip/(:segment)/incydent/(:num)', 'Incydent::show/$1/$2');
$routes->get('ip/(:segment)/enrichment/abuseipdb', 'Ip::abuseipdb/$1');
$routes->get('watchlista', 'Watchlista::index');
$routes->post('watchlista/dodaj', 'Watchlista::dodaj');
$routes->post('watchlista/usun', 'Watchlista::usun');
$routes->get('admin/pluginy', 'Admin\Pluginy::index');
$routes->post('admin/pluginy', 'Admin\Pluginy::zapisz');
$routes->post('admin/pluginy/pobierz', 'Admin\Pluginy::pobierz');
$routes->get('admin/ustawienia', 'Admin\Ustawienia::index');
$routes->post('admin/ustawienia', 'Admin\Ustawienia::zapisz');
$routes->post('admin/ustawienia/przywroc-domyslne', 'Admin\Ustawienia::przywrocDomyslne');
$routes->get('admin', 'Admin\Pluginy::index');

// API badge — token serwerowy (filtr mcsBadgeToken); CSRF wyłączone dla api/* .
// GET ?ip= (single) | GET ?ips=a,b | POST JSON {"ips":[…]}.
$routes->get('api/badge', 'Api\Badge::index');
$routes->post('api/badge', 'Api\Badge::index');

// Enrichment AbuseIPDB (ten sam token co badge; poza indeksem/badge streamowym).
$routes->get('api/enrichment/abuseipdb', 'Api\EnrichmentAbuseipdb::check');
