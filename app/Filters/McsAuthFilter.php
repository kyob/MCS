<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Wymaga sesji MCS na chronionych ścieżkach (/, /dashboard, /szukaj, /ip/*, /admin/*).
 * v1: obie role (admin, operator) mają ten sam dostęp.
 */
class McsAuthFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $sesja = session();

        if ($sesja->get('mcs_user_id') !== null) {
            return null;
        }

        if ($request instanceof IncomingRequest && $request->isAJAX()) {
            return service('response')
                ->setStatusCode(401)
                ->setJSON(['blad' => 'Wymagane logowanie.']);
        }

        $uri = '/';
        if ($request instanceof IncomingRequest) {
            $uri = $request->getUri()->getPath();
            // bez prefiksu front controllera (gdy indexPage niepusty)
            $uri = preg_replace('#^/index\.php#', '', $uri) ?: '/';
            if ($uri === '' || $uri[0] !== '/') {
                $uri = '/' . ltrim($uri, '/');
            }
        }

        $sesja->set('mcs_po_logowaniu', $uri);

        return redirect()->to('/login');
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
