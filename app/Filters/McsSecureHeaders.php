<?php

namespace App\Filters;

use CodeIgniter\Filters\SecureHeaders;

/**
 * Nagłówki bezpieczeństwa dla każdej odpowiedzi (filtr globalny „after”).
 *
 * Pełne CSP nie jest włączone: widoki mają skrypty inline. Zostaje tylko
 * frame-ancestors 'none' (ochrona przed clickjackingiem formularzy admina).
 */
class McsSecureHeaders extends SecureHeaders
{
    /**
     * @var array<string, string>
     */
    protected $headers = [
        'X-Frame-Options'                   => 'DENY',
        'Content-Security-Policy'           => "frame-ancestors 'none'",
        'X-Content-Type-Options'            => 'nosniff',
        'X-Download-Options'                => 'noopen',
        'X-Permitted-Cross-Domain-Policies' => 'none',
        'Referrer-Policy'                   => 'same-origin',
    ];
}
