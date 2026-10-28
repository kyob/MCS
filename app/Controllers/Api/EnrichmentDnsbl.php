<?php

namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\Enrichment\DnsblSerwis;

/**
 * Enrichment DNSBL multi-list (token serwerowy — filtr mcsBadgeToken).
 */
class EnrichmentDnsbl extends BaseController
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

        $wynik = (new DnsblSerwis())->sprawdz($ip, $force);

        if (! $wynik['ok']) {
            return $this->response->setStatusCode(400)->setJSON([
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
