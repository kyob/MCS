<?php

namespace App\Filters;

use CodeIgniter\Cookie\Cookie;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Cookie as CookieConfig;

/**
 * Flaga Secure ciasteczek (sesja, CSRF) zależna od połączenia.
 *
 * Config\Cookie::$secure = true (domyślnie) działa jak „auto”:
 * - HTTPS (bezpośrednio albo przez zaufane proxy z app.proxyIPs + X-Forwarded-Proto: https)
 *   => ciasteczka z flagą Secure,
 * - czyste HTTP (np. wejście po adresie LAN, healthcheck) => bez flagi Secure.
 *   Bez tego CI4 rzuca SecurityException „secure cookie over a non-secure connection” (HTTP 500).
 *
 * cookie.secure = false w .env wyłącza flagę zawsze.
 * Filtr musi być w $globals['before'] przed 'csrf'.
 */
class McsCookieSecureFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $config = config(CookieConfig::class);
        if (! $config->secure) {
            return null;
        }
        if ($request instanceof IncomingRequest && ! $request->isSecure()) {
            $config->secure = false;
            Cookie::setDefaults($config);
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
