<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Config\Mcs;

/**
 * Token serwerowy dla GET|POST /api/badge (single + batch).
 * Akceptuje Authorization: Bearer … lub X-Mcs-Badge-Token.
 */
class McsBadgeTokenFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $oczekiwany = (string) config(Mcs::class)->badgeToken;
        if ($oczekiwany === '') {
            return $this->odrzuc();
        }

        $podany = $this->wyciagnijToken($request);
        if ($podany === null || ! hash_equals($oczekiwany, $podany)) {
            return $this->odrzuc();
        }

        return null;
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }

    private function wyciagnijToken(RequestInterface $request): ?string
    {
        if (! $request instanceof IncomingRequest) {
            return null;
        }

        $naglowek = $request->getHeaderLine('X-Mcs-Badge-Token');
        if ($naglowek !== '') {
            return $naglowek;
        }

        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(\S+)/i', $auth, $m) === 1) {
            return $m[1];
        }

        return null;
    }

    private function odrzuc(): ResponseInterface
    {
        return service('response')
            ->setStatusCode(401)
            ->setJSON(['blad' => 'Wymagany prawidłowy token API badge.']);
    }
}
