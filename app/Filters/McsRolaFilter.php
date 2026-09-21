<?php

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Wymaga jednej z ról podanych jako argumenty filtra (np. mcsRola:superadmin).
 */
class McsRolaFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $dozwolone = is_array($arguments) ? $arguments : [];
        $rola      = (string) session()->get('mcs_user_rola');

        if ($rola !== '' && in_array($rola, $dozwolone, true)) {
            return null;
        }

        if ($request instanceof IncomingRequest && $request->isAJAX()) {
            return service('response')
                ->setStatusCode(403)
                ->setJSON(['blad' => 'Brak uprawnień.']);
        }

        return service('response')
            ->setStatusCode(403)
            ->setBody(view('errors/brak_uprawnien', [
                'title'      => 'Brak uprawnień — MCS',
                'navAktywny' => '',
                'email'      => (string) session()->get('mcs_user_email'),
                'rola'       => $rola,
            ]));
    }

    /**
     * @param list<string>|null $arguments
     */
    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        return null;
    }
}
