<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Enrichment\LmsMapowanieSerwis;

/**
 * Enrichment LMS mapowanie dla LMS/UI (token serwerowy — filtr mcsBadgeToken).
 * Nie wpływa na badge streamowy / mcs_incydenty.
 */
class EnrichmentLms extends BaseController
{
    public function check()
    {
        $ip = $this->request->getGet('ip');
        $ip = is_string($ip) ? trim($ip) : '';

        $forceRaw = $this->request->getGet('force');
        $force    = filter_var($forceRaw, FILTER_VALIDATE_BOOLEAN);

        if ($force && ($odmowa = $this->odmowaLimituForceApi()) !== null) {
            return $odmowa;
        }

        $wynik = (new LmsMapowanieSerwis())->sprawdz($ip, $force);

        if (! $wynik['ok']) {
            $kod = ($wynik['tryb'] === 'brak_konfiguracji') ? 503 : 400;

            return $this->response->setStatusCode($kod)->setJSON([
                'ok'   => false,
                'blad' => $wynik['blad'],
                'ip'   => $wynik['ip'],
                'tryb' => $wynik['tryb'],
            ]);
        }

        return $this->response->setJSON([
            'ok'      => true,
            'ip'      => $wynik['ip'],
            'z_cache' => $wynik['z_cache'],
            'tryb'    => $wynik['tryb'],
            'wynik'   => $wynik['wynik'],
        ]);
    }
}
