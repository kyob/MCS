<?php

namespace App\Controllers;

use App\Libraries\Enrichment\AbuseipdbSerwis;
use App\Libraries\ProblemyIp;
use App\Libraries\Siec\As198050Matcher;
use App\Models\IncydentModel;

/**
 * Strona podmiotu IP — status + zagregowane problemy, panel enrichment AbuseIPDB.
 */
class Ip extends BaseController
{
    public function show(string $ip)
    {
        $ip = trim($ip);
        $email = (string) session()->get('mcs_user_email');
        $rola  = (string) session()->get('mcs_user_rola');

        $abuse = new AbuseipdbSerwis();
        $abuseTryb = $abuse->tryb();
        $abuseKomunikat = null;
        if ($abuseTryb === 'brak_klucza') {
            $abuseKomunikat = 'Brak mcs.abuseipdb.apiKey — ustaw w .env albo mcs.abuseipdb.fixturePath do dry-run.';
        }

        $pustyStatus = [
            'max_waga'         => '',
            'liczba_problemow' => 0,
            'top_tytuly'       => [],
        ];

        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return $this->response->setStatusCode(400)->setBody(
                view('ip/show', [
                    'title'              => 'IP — MCS',
                    'navAktywny'         => 'ip',
                    'ipNav'              => $ip,
                    'szukajIp'           => $ip,
                    'ip'                 => $ip,
                    'blad'               => 'Nieprawidłowy adres IP.',
                    'problemy'           => [],
                    'statusIp'           => $pustyStatus,
                    'zrodla'             => [],
                    'filtrZrodlo'        => null,
                    'asMatchWlaczone'    => false,
                    'naszeIp'            => false,
                    'email'              => $email,
                    'rola'               => $rola,
                    'abuseipdbTryb'      => $abuseTryb,
                    'abuseipdbCache'     => null,
                    'abuseipdbKomunikat' => $abuseKomunikat,
                ]),
            );
        }

        $filtrZrodlo = $this->request->getGet('zrodlo');
        $filtrZrodlo = is_string($filtrZrodlo) && $filtrZrodlo !== '' ? $filtrZrodlo : null;

        $model     = model(IncydentModel::class);
        $incydenty = $model->dlaPodmiotuIp($ip, $filtrZrodlo);
        $zrodla    = $model->zrodlaDlaPodmiotuIp($ip);
        $agregat   = (new ProblemyIp())->zbuduj($ip, $incydenty);

        $matcher         = new As198050Matcher();
        $asMatchWlaczone = $matcher->jestWlaczone();
        $naszeIp         = $asMatchWlaczone && $matcher->czyNaszeIp($ip);

        $abuseCache = $abuse->podgladCache($ip);

        return view('ip/show', [
            'title'              => 'IP ' . $ip . ' — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => $ip,
            'szukajIp'           => $ip,
            'ip'                 => $ip,
            'blad'               => null,
            'problemy'           => $agregat['problemy'],
            'statusIp'           => $agregat['status'],
            'zrodla'             => $zrodla,
            'filtrZrodlo'        => $filtrZrodlo,
            'asMatchWlaczone'    => $asMatchWlaczone,
            'naszeIp'            => $naszeIp,
            'email'              => $email,
            'rola'               => $rola,
            'abuseipdbTryb'      => $abuseCache !== null ? 'cache' : $abuseTryb,
            'abuseipdbCache'     => $abuseCache,
            'abuseipdbKomunikat' => $abuseKomunikat,
        ]);
    }

    /**
     * Enrichment AbuseIPDB pod sesją (nie Bearer). Nie pisze do indeksu/badge.
     */
    public function abuseipdb(string $ip)
    {
        $ip = trim($ip);
        $forceRaw = $this->request->getGet('force');
        $force    = filter_var($forceRaw, FILTER_VALIDATE_BOOLEAN);

        $wynik = (new AbuseipdbSerwis())->sprawdz($ip, $force);

        if (! $wynik['ok']) {
            $kod = ($wynik['tryb'] === 'brak_klucza') ? 503 : 400;

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
