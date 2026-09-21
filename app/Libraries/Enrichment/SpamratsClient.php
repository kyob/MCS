<?php

namespace App\Libraries\Enrichment;

/**
 * Klient SpamRats DNSBL RATS-All — enrichment, nie stream.
 *
 * Zapytanie: {odwrócone-IPv4}.{apiKey}.all.spamrats.com
 * Kody A: 127.0.0.36 Dyna, .37 NoPtr, .38 Spam, .43 Auth; .2 też akceptowany.
 *
 * @see https://www.spamrats.com/lists.php
 */
class SpamratsClient
{
    private const KODY_ZNANE = [
        '127.0.0.2'  => null,
        '127.0.0.36' => 'dyna',
        '127.0.0.37' => 'noptr',
        '127.0.0.38' => 'spam',
        '127.0.0.43' => 'auth',
    ];

    private string $apiKey;
    private string $zone;

    /** @var callable(string): list<string>|null */
    private $resolver;

    /**
     * @param callable(string): list<string>|null $resolver  Zwraca listę A; [] = NXDOMAIN/brak
     */
    public function __construct(
        string $apiKey = '',
        string $zone = 'all.spamrats.com',
        ?callable $resolver = null,
    ) {
        $this->apiKey   = trim($apiKey);
        $zone           = trim($zone);
        $this->zone     = $zone !== '' ? $zone : 'all.spamrats.com';
        $this->resolver = $resolver;
    }

    public static function fromEnv(?callable $resolver = null): self
    {
        $key  = trim((string) env('mcs.spamrats.apiKey', ''));
        $zone = trim((string) env('mcs.spamrats.zone', 'all.spamrats.com'));

        return new self($key, $zone !== '' ? $zone : 'all.spamrats.com', $resolver);
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '' && $this->kluczJestPrawidlowy($this->apiKey);
    }

    public function getZone(): string
    {
        return $this->zone;
    }

    /**
     * Komunikat gdy brak / zły klucz (dla UI / CLI). Bez wartości klucza.
     */
    public function brakCredentialsKomunikat(): string
    {
        if ($this->apiKey === '') {
            return 'Brak mcs.spamrats.apiKey — ustaw klucz DNSBL SpamRats w .env albo fixturePath.';
        }
        if (! $this->kluczJestPrawidlowy($this->apiKey)) {
            return 'Nieprawidłowy mcs.spamrats.apiKey — dozwolone tylko litery i cyfry [A-Za-z0-9].';
        }

        return '';
    }

    /**
     * @return array{
     *   ok: bool,
     *   data: ?array{
     *     listed: bool,
     *     listed_dyna: bool,
     *     listed_noptr: bool,
     *     listed_spam: bool,
     *     listed_auth: bool,
     *     kody: list<string>
     *   },
     *   error: string
     * }
     */
    public function checkIp(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'data' => null, 'error' => 'invalid ip'];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'SpamRats DNSBL obsługuje tylko IPv4.',
            ];
        }

        if ($this->apiKey === '') {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'mcs.spamrats.apiKey is not set',
            ];
        }

        if (! $this->kluczJestPrawidlowy($this->apiKey)) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'mcs.spamrats.apiKey contains invalid characters',
            ];
        }

        $fqdn = $this->budujFqdn($ip);

        try {
            $kody = $this->rozwiążA($fqdn);
        } catch (\Throwable $e) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'Błąd DNS SpamRats dla IP ' . $ip . ' (strefa ' . $this->zone . ').',
            ];
        }

        $listed      = false;
        $listedDyna  = false;
        $listedNoptr = false;
        $listedSpam  = false;
        $listedAuth  = false;
        $znormalizowane = [];

        foreach ($kody as $kod) {
            $kod = trim((string) $kod);
            if ($kod === '') {
                continue;
            }
            if (! array_key_exists($kod, self::KODY_ZNANE)) {
                return [
                    'ok'    => false,
                    'data'  => null,
                    'error' => 'nieoczekiwany kod A SpamRats: ' . $kod,
                ];
            }

            $listed            = true;
            $znormalizowane[]  = $kod;
            $flaga             = self::KODY_ZNANE[$kod];
            if ($flaga === 'dyna') {
                $listedDyna = true;
            } elseif ($flaga === 'noptr') {
                $listedNoptr = true;
            } elseif ($flaga === 'spam') {
                $listedSpam = true;
            } elseif ($flaga === 'auth') {
                $listedAuth = true;
            }
        }

        return [
            'ok'   => true,
            'data' => [
                'listed'       => $listed,
                'listed_dyna'  => $listedDyna,
                'listed_noptr' => $listedNoptr,
                'listed_spam'  => $listedSpam,
                'listed_auth'  => $listedAuth,
                'kody'         => $znormalizowane,
            ],
            'error' => '',
        ];
    }

    public function budujFqdn(string $ip): string
    {
        $czesci = explode('.', $ip);
        if (count($czesci) !== 4) {
            throw new \InvalidArgumentException('invalid ipv4');
        }

        return implode('.', array_reverse($czesci)) . '.' . $this->apiKey . '.' . $this->zone;
    }

    private function kluczJestPrawidlowy(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z0-9]+$/', $key);
    }

    /**
     * @return list<string>
     */
    private function rozwiążA(string $fqdn): array
    {
        if ($this->resolver !== null) {
            $wynik = ($this->resolver)($fqdn);
            if (! is_array($wynik)) {
                return [];
            }
            $out = [];
            foreach ($wynik as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }

            return $out;
        }

        $rekordy = @dns_get_record($fqdn, DNS_A);
        if ($rekordy === false || $rekordy === []) {
            return [];
        }

        $out = [];
        foreach ($rekordy as $r) {
            if (is_array($r) && isset($r['ip']) && is_string($r['ip']) && $r['ip'] !== '') {
                $out[] = $r['ip'];
            }
        }

        return $out;
    }
}
