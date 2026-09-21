<?php

namespace App\Filters;

use App\Models\UserModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\IncomingRequest;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Wymaga sesji MCS na chronionych ścieżkach (/, /dashboard, /szukaj, /ip/*, /admin/*).
 * Odświeża rolę z bazy; wyłączone konto = wylogowanie.
 */
class McsAuthFilter implements FilterInterface
{
    /**
     * @param list<string>|null $arguments
     */
    public function before(RequestInterface $request, $arguments = null)
    {
        $sesja = session();
        $id    = $sesja->get('mcs_user_id');

        if ($id !== null) {
            $user = model(UserModel::class)->find($id);
            if (is_array($user) && $this->jestAktywny($user)) {
                $sesja->set([
                    'mcs_user_id'    => $user['id'],
                    'mcs_user_email' => $user['email'],
                    'mcs_user_rola'  => $user['rola'],
                ]);

                return null;
            }

            $sesja->remove(['mcs_user_id', 'mcs_user_email', 'mcs_user_rola']);
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

    /**
     * @param array<string, mixed> $user
     */
    private function jestAktywny(array $user): bool
    {
        $v = $user['aktywny'] ?? false;

        return $v === true || $v === 1 || $v === '1' || $v === 't' || $v === 'true';
    }
}
