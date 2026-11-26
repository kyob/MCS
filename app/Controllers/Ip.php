<?php

namespace App\Controllers;

use App\Libraries\Enrichment\AbuseipdbSerwis;
use App\Libraries\Enrichment\DnsblSerwis;
use App\Libraries\Enrichment\GreynoiseSerwis;
use App\Libraries\Enrichment\IsmaliciousClient;
use App\Libraries\Enrichment\IsmaliciousSerwis;
use App\Libraries\Enrichment\LmsMapowanieSerwis;
use App\Libraries\Enrichment\SpamratsClient;
use App\Libraries\Enrichment\SpamratsSerwis;
use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\CzasUi;
use App\Libraries\LimitForce;
use App\Libraries\OpcjeMcs;
use App\Libraries\ProblemyIp;
use App\Libraries\Siec\AsMatcher;
use App\Libraries\Siec\RevDns;
use App\Models\IncydentModel;

/**
 * Strona podmiotu IP — status + zagregowane problemy, panele LMS / AbuseIPDB.
 * Speedtest: tylko krótka notka /24 (Ookla anonimizuje do *.0) → dashboard agregatów.
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

        $ism = new IsmaliciousSerwis();
        $ismTryb = $ism->tryb();
        $ismKomunikat = null;
        if ($ismTryb === 'brak_klucza') {
            $ismKomunikat = (new IsmaliciousClient())->brakCredentialsKomunikat();
            if ($ismKomunikat === '') {
                $ismKomunikat = 'Brak credentials isMalicious.';
            }
        }

        $spamrats = new SpamratsSerwis();
        $spamratsTryb = $spamrats->tryb();
        $spamratsKomunikat = null;
        if ($spamratsTryb === 'brak_klucza') {
            $spamratsKomunikat = (new SpamratsClient())->brakCredentialsKomunikat();
            if ($spamratsKomunikat === '') {
                $spamratsKomunikat = 'Brak credentials SpamRats.';
            }
        }

        $dnsbl         = new DnsblSerwis();
        $dnsblTryb     = $dnsbl->tryb();
        $dnsblKomunikat = null;

        $greynoise = new GreynoiseSerwis();
        $greynoiseTryb = $greynoise->tryb();
        $greynoiseKomunikat = null;
        if ($greynoiseTryb === 'brak_klucza') {
            $greynoiseKomunikat = 'Brak klucza GreyNoise. Wejdź w Wtyczki → GreyNoise → Klucz.';
        }

        $lms = new LmsMapowanieSerwis();
        $lmsTryb = $lms->tryb();
        $lmsKomunikat = null;
        if ($lmsTryb === 'brak_konfiguracji') {
            $lmsKomunikat = 'Brak konfiguracji LMS — ustaw mcs.lms.baseUrl+mapToken, albo db*, albo fixturePath.';
        }

        $speedtest = new SpeedtestSerwis();

        $wtyczkiIp = [
            'lms'         => $lmsTryb !== 'brak_konfiguracji',
            'abuseipdb'   => $abuseTryb !== 'brak_klucza',
            'ismalicious' => $ismTryb !== 'brak_klucza',
            'greynoise'   => $greynoiseTryb !== 'brak_klucza',
            'spamrats'    => $spamratsTryb !== 'brak_klucza',
            'speedtest'   => $speedtest->tryb() !== 'brak_konfiguracji',
        ];

        $pustyStatus = [
            'max_waga'         => '',
            'liczba_problemow' => 0,
            'top_tytuly'       => [],
        ];

        $revDnsLib       = new RevDns();
        $revDnsWlaczone  = $revDnsLib->jestWlaczone();

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
                    'lmsTryb'            => $lmsTryb,
                    'lmsCache'           => null,
                    'lmsKomunikat'       => $lmsKomunikat,
                    'abuseipdbTryb'      => $abuseTryb,
                    'abuseipdbCache'     => null,
                    'abuseipdbKomunikat' => $abuseKomunikat,
                    'ismaliciousTryb'      => $ismTryb,
                    'ismaliciousCache'     => null,
                    'ismaliciousKomunikat' => $ismKomunikat,
                    'spamratsTryb'         => $spamratsTryb,
                    'spamratsCache'        => null,
                    'spamratsKomunikat'    => $spamratsKomunikat,
                    'dnsblTryb'            => $dnsblTryb,
                    'dnsblCache'           => null,
                    'dnsblKomunikat'       => $dnsblKomunikat,
                    'greynoiseTryb'        => $greynoiseTryb,
                    'greynoiseCache'       => null,
                    'greynoiseKomunikat'   => $greynoiseKomunikat,
                    'speedtestCidr'        => null,
                    'speedtestLiczba24'    => 0,
                    'wtyczkiIp'            => $wtyczkiIp,
                    'flashOk'            => null,
                    'flashBlad'          => null,
                    'revDnsWlaczone'     => $revDnsWlaczone,
                    'revDns'             => null,
                ]),
            );
        }

        $filtrZrodlo = $this->request->getGet('zrodlo');
        $filtrZrodlo = is_string($filtrZrodlo) && $filtrZrodlo !== '' ? $filtrZrodlo : null;

        $model     = model(IncydentModel::class);
        $incydenty = $model->dlaPodmiotuIp($ip, $filtrZrodlo);
        $zrodla    = $model->zrodlaDlaPodmiotuIp($ip);
        $agregat   = (new ProblemyIp())->zbuduj($ip, $incydenty);
        $ipNowe    = isset($model->ipNowe([$ip], OpcjeMcs::int('mcs.nowe.dni'))[$ip]);

        $matcher         = new AsMatcher();
        $asMatchWlaczone = $matcher->jestWlaczone();
        $naszeIp         = $asMatchWlaczone && $matcher->czyNaszeIp($ip);

        $abuseCache    = $abuse->podgladCache($ip);
        $ismCache      = $ism->podgladCache($ip);
        $spamratsCache = $spamrats->podgladCache($ip);
        $dnsblCache    = $dnsbl->podgladCache($ip);
        $greynoiseCache = null;
        try {
            $greynoiseCache = $greynoise->podgladCache($ip);
        } catch (\Throwable) {
            if ($greynoiseKomunikat === null) {
                $greynoiseKomunikat = 'Brak tabeli cache GreyNoise — uruchom php spark migrate.';
            }
        }
        // Skaner DNSBL (E2): tylko odczyt mcs_dnsbl_listingi, bez DNS przy otwarciu strony.
        $dnsblListingi   = [];
        $dnsblOstatniSkan = null;
        try {
            $skWidok          = new \App\Libraries\Dnsbl\SkanyWidok();
            $dnsblListingi    = $skWidok->listingiIp($ip);
            $dnsblOstatniSkan = $skWidok->ostatniSkanIp($ip);
        } catch (\Throwable) {
            $dnsblListingi = [];
        }
        $lmsCache      = $lms->podgladCache($ip);
        if ($lmsCache === null && $lmsTryb !== 'brak_konfiguracji') {
            $lmsWynik = $lms->sprawdz($ip, false);
            if ($lmsWynik['ok'] && is_array($lmsWynik['wynik'] ?? null)) {
                $lmsCache = $lmsWynik['wynik'];
                $lmsTryb  = (string) ($lmsWynik['tryb'] ?? $lmsTryb);
            } elseif (! $lmsWynik['ok'] && $lmsKomunikat === null) {
                $lmsKomunikat = (string) ($lmsWynik['blad'] ?? 'Błąd mapowania LMS.');
            }
        }
        // Issue #447: przy zimnym cache lookup PTR idzie przez PtrResolver z twardym
        // timeoutem (mcs.revDns.timeoutMs, domyślnie 300 ms) — request nie wisi na DNS.
        $revDns        = $revDnsLib->dlaIp($ip);

        $stCidr   = SpeedtestSerwis::cidrZIp($ip);
        $stLiczba = 0;
        if ($wtyczkiIp['speedtest']) {
            try {
                $stLiczba = $speedtest->liczbaTestowDlaCidrZIp($ip, 0);
            } catch (\Throwable) {
                $stLiczba = 0;
            }
        }

        return view('ip/show', [
            'title'              => 'IP ' . $ip . ' — MCS',
            'navAktywny'         => 'ip',
            'ipNav'              => $ip,
            'szukajIp'           => $ip,
            'ip'                 => $ip,
            'blad'               => null,
            'problemy'           => $agregat['problemy'],
            'ipNowe'             => $ipNowe,
            'statusIp'           => $agregat['status'],
            'zrodla'             => $zrodla,
            'filtrZrodlo'        => $filtrZrodlo,
            'asMatchWlaczone'    => $asMatchWlaczone,
            'naszeIp'            => $naszeIp,
            'email'              => $email,
            'rola'               => $rola,
            'lmsTryb'            => $lmsCache !== null ? 'cache' : $lmsTryb,
            'lmsCache'           => $lmsCache,
            'lmsKomunikat'       => $lmsKomunikat,
            'abuseipdbTryb'      => $abuseCache !== null ? 'cache' : $abuseTryb,
            'abuseipdbCache'     => $abuseCache,
            'abuseipdbKomunikat' => $abuseKomunikat,
            'ismaliciousTryb'      => $ismCache !== null ? 'cache' : $ismTryb,
            'ismaliciousCache'     => $ismCache,
            'ismaliciousKomunikat' => $ismKomunikat,
            'spamratsTryb'         => $spamratsCache !== null ? 'cache' : $spamratsTryb,
            'spamratsCache'        => $spamratsCache,
            'spamratsKomunikat'    => $spamratsKomunikat,
            'dnsblTryb'            => $dnsblCache !== null ? 'cache' : $dnsblTryb,
            'dnsblCache'           => $dnsblCache,
            'dnsblKomunikat'       => $dnsblKomunikat,
            'dnsblListingi'        => $dnsblListingi,
            'dnsblOstatniSkan'     => $dnsblOstatniSkan,
            'greynoiseTryb'        => $greynoiseCache !== null ? 'cache' : $greynoiseTryb,
            'greynoiseCache'       => $greynoiseCache,
            'greynoiseKomunikat'   => $greynoiseKomunikat,
            'speedtestCidr'        => $stCidr,
            'speedtestLiczba24'    => $stLiczba,
            'wtyczkiIp'            => $wtyczkiIp,
            'flashOk'            => session()->getFlashdata('komunikat'),
            'flashBlad'          => session()->getFlashdata('blad'),
            'revDnsWlaczone'     => $revDnsWlaczone,
            'revDns'             => $revDns,
        ]);
    }

    /**
     * Enrichment AbuseIPDB pod sesją (nie Bearer). Nie pisze do indeksu/badge.
     * GET = tylko cache; zapytanie do API tylko POST z CSRF.
     */
    public function abuseipdb(string $ip)
    {
        return $this->enrichment(AbuseipdbSerwis::class, $ip, 'brak_klucza');
    }

    /**
     * Enrichment isMalicious pod sesją. Nie pisze do indeksu/badge.
     */
    public function ismalicious(string $ip)
    {
        return $this->enrichment(IsmaliciousSerwis::class, $ip, 'brak_klucza');
    }

    /**
     * Enrichment SpamRats DNSBL pod sesją. Nie pisze do indeksu/badge.
     */
    public function spamrats(string $ip)
    {
        return $this->enrichment(SpamratsSerwis::class, $ip, 'brak_klucza');
    }

    /**
     * Enrichment multi-DNSBL pod sesją (może też zaktualizować incydenty przy force).
     */
    public function dnsbl(string $ip)
    {
        return $this->enrichment(DnsblSerwis::class, $ip, '');
    }

    /**
     * Enrichment LMS mapowanie pod sesją. Nie pisze do indeksu/badge.
     */
    public function lms(string $ip)
    {
        return $this->enrichment(LmsMapowanieSerwis::class, $ip, 'brak_konfiguracji');
    }

    /**
     * Enrichment GreyNoise pod sesją. Nie pisze do indeksu/badge.
     */
    public function greynoise(string $ip)
    {
        return $this->enrichment(GreynoiseSerwis::class, $ip, 'brak_klucza');
    }

    /**
     * Fabryka serwisu enrichmentu (nadpisywana w testach atrapą).
     */
    protected function nowySerwis(string $klasa): object
    {
        return new $klasa();
    }

    /**
     * GET: wyłącznie podgląd cache (zero zapytań sieciowych do API).
     * POST (CSRF wymusza filtr globalny): sprawdz() — cache albo API; force=1 pomija cache.
     *
     * @param string $trybBrakuKonfiguracji tryb zwracany przez serwis przy braku klucza/konfiguracji (HTTP 503)
     */
    private function enrichment(string $klasa, string $ip, string $trybBrakuKonfiguracji)
    {
        $ip     = trim($ip);
        $serwis = $this->nowySerwis($klasa);

        if (! $this->request->is('post')) {
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return $this->odpowiedzZCsrf()->setStatusCode(400)->setJSON([
                    'ok'   => false,
                    'blad' => 'Wymagany prawidłowy adres IP.',
                    'ip'   => $ip,
                    'tryb' => 'cache',
                ]);
            }
            $cache = $serwis->podgladCache($ip);
            if ($cache === null) {
                return $this->odpowiedzZCsrf()->setStatusCode(404)->setJSON([
                    'ok'   => false,
                    'blad' => 'Brak zapisanego wyniku. Kliknij „Sprawdź”.',
                    'ip'   => $ip,
                    'tryb' => 'brak_cache',
                ]);
            }

            return $this->odpowiedzZCsrf()->setJSON([
                'ok'      => true,
                'ip'      => $ip,
                'z_cache' => true,
                'tryb'    => 'cache',
                'wynik'   => $this->czasyUi(is_array($cache) ? $cache : []),
            ]);
        }

        $force = $this->czyForce();
        if ($force && ! (new LimitForce())->pozwolUzytkownik((string) session()->get('mcs_user_email'))) {
            return $this->odpowiedzZCsrf()
                ->setStatusCode(429)
                ->setHeader('Retry-After', (string) LimitForce::retryAfter(OpcjeMcs::int('mcs.force.limitUzytkownik')))
                ->setJSON([
                    'ok'   => false,
                    'blad' => 'Za dużo odświeżeń. Spróbuj później.',
                    'ip'   => $ip,
                    'tryb' => 'limit_force',
                ]);
        }

        $wynik = $serwis->sprawdz($ip, $force);

        if (! $wynik['ok']) {
            $kod = ($trybBrakuKonfiguracji !== '' && $wynik['tryb'] === $trybBrakuKonfiguracji) ? 503 : 400;

            return $this->odpowiedzZCsrf()->setStatusCode($kod)->setJSON([
                'ok'   => false,
                'blad' => $wynik['blad'],
                'ip'   => $wynik['ip'],
                'tryb' => $wynik['tryb'],
            ]);
        }

        return $this->odpowiedzZCsrf()->setJSON([
            'ok'      => true,
            'ip'      => $wynik['ip'],
            'z_cache' => $wynik['z_cache'],
            'tryb'    => $wynik['tryb'],
            'wynik'   => $this->czasyUi(is_array($wynik['wynik'] ?? null) ? $wynik['wynik'] : []),
        ]);
    }

    /**
     * force=1 tylko przez POST (z CSRF). GET (także z ?force=1) czyta wyłącznie cache.
     * Chroni limity płatnych API przed linkiem z obcej strony (SameSite=Lax wpuszcza GET).
     */
    private function czyForce(): bool
    {
        return $this->request->is('post')
            && filter_var($this->request->getPost('force'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Odpowiedź JSON z aktualnym tokenem CSRF w nagłówku (po POST token jest regenerowany).
     */
    /**
     * Znaczniki z bazy są w UTC. Odpowiedź panelu (nie API) pokazuje je w strefie aplikacji.
     *
     * @param array<string, mixed> $wynik
     *
     * @return array<string, mixed>
     */
    private function czasyUi(array $wynik): array
    {
        foreach ($wynik as $k => $v) {
            if (! is_string($k) || ! is_string($v)) {
                continue;
            }
            if (! str_ends_with($k, '_at') && $k !== 'last_reported_at') {
                continue;
            }
            $fmt = CzasUi::pokaz($v);
            if ($fmt !== '') {
                $wynik[$k] = $fmt;
            }
        }

        return $wynik;
    }

    private function odpowiedzZCsrf(): \CodeIgniter\HTTP\ResponseInterface
    {
        return $this->response->setHeader('X-Mcs-Csrf', csrf_hash());
    }
}
