<?php

use CodeIgniter\Router\RouteCollection;

/** @var RouteCollection $routes */

$routes->get('/', 'Dashboard::index');
$routes->get('dashboard', 'Dashboard::index');
$routes->get('dashboard/problematyczne-ip', 'Dashboard::problematyczneIp');
$routes->get('dashboard/kolejka', 'Dashboard::kolejka');
$routes->get('dashboard/incydenty', 'Dashboard::incydenty');
$routes->get('dashboard/speedtest', 'Dashboard::speedtest');
$routes->get('dashboard/dnsbl', 'Dashboard::dnsbl');
$routes->post('dashboard/dnsbl/skan', 'Dashboard::dnsblSkan');
$routes->get('dashboard/dnsbl/status/(:num)', 'Dashboard::dnsblStatus/$1');
$routes->get('dashboard/dnsbl/skan/(:num)/csv', 'Dashboard::dnsblSkanCsv/$1');
$routes->get('dashboard/abusix', 'Dashboard::abusix');
$routes->get('dashboard/spamhaus', 'Dashboard::spamhaus');
$routes->get('dashboard/eksport.csv', 'Dashboard::eksportCsv');
$routes->get('szukaj', 'Dashboard::szukaj');

$routes->get('login', 'Auth::login');
$routes->post('login', 'Auth::loginSubmit');
$routes->get('logout', 'Auth::logout');

// Chronione sesją (filtr mcsAuth w Config\Filters).
$routes->get('ip/(:segment)', 'Ip::show/$1');
$routes->get('ip/(:segment)/incydent/(:num)', 'Incydent::show/$1/$2');
$routes->post('ip/(:segment)/incydent/(:num)/ukryj', 'Incydent::ukryj/$1/$2');
$routes->get('ip/(:segment)/enrichment/abuseipdb', 'Ip::abuseipdb/$1');
$routes->get('ip/(:segment)/enrichment/ismalicious', 'Ip::ismalicious/$1');
$routes->get('ip/(:segment)/enrichment/spamrats', 'Ip::spamrats/$1');
$routes->get('ip/(:segment)/enrichment/dnsbl', 'Ip::dnsbl/$1');
$routes->get('ip/(:segment)/enrichment/lms', 'Ip::lms/$1');
$routes->get('watchlista', 'Watchlista::index');
$routes->post('watchlista/dodaj', 'Watchlista::dodaj');
$routes->post('watchlista/usun', 'Watchlista::usun');
$routes->group('admin', ['filter' => 'mcsRola:admin,superadmin'], static function ($routes) {
    $routes->get('/', 'Admin\Pluginy::index');
    $routes->get('pluginy', 'Admin\Pluginy::index');
    $routes->get('pluginy/historia-importow', 'Admin\Pluginy::historiaImportow');
    $routes->post('pluginy', 'Admin\Pluginy::zapisz');
    $routes->post('pluginy/pobierz', 'Admin\Pluginy::pobierz');
    $routes->post('pluginy/snds-klucz', 'Admin\Pluginy::zapiszKluczSnds');
    $routes->post('pluginy/abusix-klucz', 'Admin\Pluginy::zapiszKluczAbusix');
    $routes->post('pluginy/abusix-test', 'Admin\Pluginy::testujAbusix');
    $routes->get('ustawienia', 'Admin\Ustawienia::index');
    $routes->post('ustawienia', 'Admin\Ustawienia::zapisz');
    $routes->post('ustawienia/przywroc-domyslne', 'Admin\Ustawienia::przywrocDomyslne');
    $routes->get('ustawienia/opcje', 'Admin\Ustawienia::opcje');
    $routes->post('ustawienia/opcje', 'Admin\Ustawienia::zapiszOpcje');
});

$routes->group('admin/uzytkownicy', ['filter' => 'mcsRola:superadmin'], static function ($routes) {
    $routes->get('/', 'Admin\Uzytkownicy::index');
    $routes->post('/', 'Admin\Uzytkownicy::dodaj');
    $routes->post('rola', 'Admin\Uzytkownicy::zmienRole');
    $routes->post('haslo', 'Admin\Uzytkownicy::ustawHaslo');
    $routes->post('aktywny', 'Admin\Uzytkownicy::ustawAktywny');
    $routes->post('usun', 'Admin\Uzytkownicy::usun');
});

// API badge — token serwerowy (filtr mcsBadgeToken); CSRF wyłączone dla api/* .
// GET ?ip= (single) | GET ?ips=a,b | POST JSON {"ips":[…]}.
$routes->get('api/badge', 'Api\Badge::index');
$routes->post('api/badge', 'Api\Badge::index');

// Enrichment AbuseIPDB / LMS (ten sam token co badge; poza indeksem/badge streamowym).
$routes->get('api/enrichment/abuseipdb', 'Api\EnrichmentAbuseipdb::check');
$routes->get('api/enrichment/ismalicious', 'Api\EnrichmentIsmalicious::check');
$routes->get('api/enrichment/spamrats', 'Api\EnrichmentSpamrats::check');
$routes->get('api/enrichment/dnsbl', 'Api\EnrichmentDnsbl::check');
$routes->get('api/enrichment/lms', 'Api\EnrichmentLms::check');
