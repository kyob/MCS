<?php

namespace App\Libraries\Enrichment;

/**
 * Multi-zone DNSBL client (IPv4) — UCEPROTECT, SpamCop, DroneBL (darmowe strefy).
 * Spamhaus zen/DQS nie jest obsługiwany (płatny dla ISP, decyzja 2026-10-15).
 *
 * Zapytanie: {odwrócone-IPv4}.{strefa}
 * Listed: A w 127.0.0.0/8 poza kodami błędów 127.255.255.*.
 */
class DnsblClient
{
    public const LISTA_UCEPROTECT1 = 'uceprotect1';

    public const LISTA_SPAMCOP = 'spamcop';

    public const LISTA_DRONEBL = 'dronebl';

    /** Id strefy Spamhaus — tylko do odrzucania w mcs.dnsbl.zones (zen/DQS nieobsługiwane). */
    public const LISTA_SPAMHAUS = 'spamhaus';

    /** @var array<string, string> id => strefa DNS */
    private array $strefy;

    /** @var callable(string): list<string>|null */
    private $resolver;

    private int $rateLimitQps;

    private float $ostatnieZapytanieAt = 0.0;

    /**
     * @param array<string, string>                 $strefy   id => hostname strefy
     * @param callable(string): list<string>|null   $resolver
     */
    public function __construct(
        array $strefy = [],
        ?callable $resolver = null,
        int $rateLimitQps = 25,
        bool $uzyjDomyslnychGdyPuste = true,
    ) {
        $this->strefy          = ($strefy === [] && $uzyjDomyslnychGdyPuste) ? self::domyslneStrefy() : $strefy;
        $this->resolver        = $resolver;
        $this->rateLimitQps    = max(1, $rateLimitQps);
    }

    public static function fromEnv(?callable $resolver = null): self
    {
        $strefy = self::strefyZOpcji();
        $qps    = 25;
        try {
            $qps = \App\Libraries\OpcjeMcs::int('mcs.dnsbl.rateLimitQps');
        } catch (\Throwable) {
            $qps = (int) env('mcs.dnsbl.rateLimitQps', 25);
        }
        if ($qps < 1) {
            $qps = 25;
        }

        // Puste strefy z opcji = świadomie wyłączone list — nie przywracaj domyślnych.
        return new self($strefy, $resolver, $qps, false);
    }

    /**
     * Strefy wg przełączników OpcjeMcs (+ opcjonalny override mcs.dnsbl.zones w .env).
     *
     * @return array<string, string>
     */
    public static function strefyZOpcji(): array
    {
        $zonesRaw = trim((string) env('mcs.dnsbl.zones', ''));
        $baza     = $zonesRaw !== '' ? self::parsujStrefy($zonesRaw) : self::domyslneStrefy();

        $wlacz = static function (string $klucz, bool $domyslnie): bool {
            try {
                return \App\Libraries\OpcjeMcs::jestTak($klucz);
            } catch (\Throwable) {
                return $domyslnie;
            }
        };

        $out = [];
        if ($wlacz('mcs.dnsbl.listaUceprotect', true) && isset($baza[self::LISTA_UCEPROTECT1])) {
            $out[self::LISTA_UCEPROTECT1] = $baza[self::LISTA_UCEPROTECT1];
        } elseif ($wlacz('mcs.dnsbl.listaUceprotect', true)) {
            $out[self::LISTA_UCEPROTECT1] = self::domyslneStrefy()[self::LISTA_UCEPROTECT1];
        }

        if ($wlacz('mcs.dnsbl.listaSpamcop', true) && isset($baza[self::LISTA_SPAMCOP])) {
            $out[self::LISTA_SPAMCOP] = $baza[self::LISTA_SPAMCOP];
        } elseif ($wlacz('mcs.dnsbl.listaSpamcop', true)) {
            $out[self::LISTA_SPAMCOP] = self::domyslneStrefy()[self::LISTA_SPAMCOP];
        }

        if ($wlacz('mcs.dnsbl.listaDronebl', true) && isset($baza[self::LISTA_DRONEBL])) {
            $out[self::LISTA_DRONEBL] = $baza[self::LISTA_DRONEBL];
        } elseif ($wlacz('mcs.dnsbl.listaDronebl', true)) {
            $out[self::LISTA_DRONEBL] = self::domyslneStrefy()[self::LISTA_DRONEBL];
        }

        // Pozostałe strefy z .env zones (niestandardowe), poza znanymi id. Spamhaus pomijany zawsze.
        foreach ($baza as $id => $host) {
            if (isset($out[$id])) {
                continue;
            }
            if (in_array($id, [self::LISTA_UCEPROTECT1, self::LISTA_SPAMCOP, self::LISTA_DRONEBL, self::LISTA_SPAMHAUS], true)) {
                continue;
            }
            $out[$id] = $host;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    public static function domyslneStrefy(): array
    {
        return [
            self::LISTA_UCEPROTECT1 => 'dnsbl-1.uceprotect.net',
            self::LISTA_SPAMCOP     => 'bl.spamcop.net',
            self::LISTA_DRONEBL     => 'dnsbl.dronebl.org',
        ];
    }

    /**
     * Format: id=host,id=host
     *
     * @return array<string, string>
     */
    public static function parsujStrefy(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s*,\s*/', trim($raw)) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (str_contains($part, '=')) {
                [$id, $host] = explode('=', $part, 2);
                $id          = trim($id);
                $host        = trim($host);
            } else {
                $host = $part;
                $id   = self::idZeStrefy($host);
            }
            if ($id === '' || $host === '') {
                continue;
            }
            $out[$id] = $host;
        }

        return $out !== [] ? $out : self::domyslneStrefy();
    }

    public static function idZeStrefy(string $host): string
    {
        $host = strtolower(trim($host));
        if (str_contains($host, 'uceprotect')) {
            return self::LISTA_UCEPROTECT1;
        }
        if (str_contains($host, 'spamcop')) {
            return self::LISTA_SPAMCOP;
        }
        if (str_contains($host, 'dronebl')) {
            return self::LISTA_DRONEBL;
        }
        if (str_contains($host, 'spamhaus')) {
            return self::LISTA_SPAMHAUS;
        }

        return preg_replace('/[^a-z0-9]+/', '_', $host) ?: 'dnsbl';
    }

    /**
     * Aktywne strefy.
     *
     * @return array<string, string> id => strefa (suffix po odwróconym IP)
     */
    public function aktywneStrefy(): array
    {
        return $this->strefy;
    }

    /**
     * @return array{
     *   ok: bool,
     *   data: ?array{
     *     listed_any: bool,
     *     listy: array<string, array{listed: bool, kody: list<string>, error: string}>
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
            return ['ok' => false, 'data' => null, 'error' => 'DNSBL obsługuje tylko IPv4.'];
        }

        $listy     = [];
        $listedAny = false;
        $bledy     = [];

        $strefy = $this->aktywneStrefy();
        if ($strefy === []) {
            return [
                'ok'   => true,
                'data' => [
                    'listed_any' => false,
                    'listy'      => [],
                ],
                'error' => '',
            ];
        }

        foreach ($strefy as $id => $strefa) {
            $wynik = $this->sprawdzStrefę($ip, $id, $strefa);
            $listy[$id] = [
                'listed' => $wynik['listed'],
                'kody'   => $wynik['kody'],
                'error'  => $wynik['error'],
            ];
            if ($wynik['error'] !== '') {
                $bledy[] = $id . ': ' . $wynik['error'];
            }
            if ($wynik['listed']) {
                $listedAny = true;
            }
        }

        // Częściowy sukces OK — pełna awaria tylko gdy żadna strefa nie odpowiedziała.
        $udane = 0;
        foreach ($listy as $w) {
            if ($w['error'] === '') {
                $udane++;
            }
        }
        if ($udane === 0 && $bledy !== []) {
            return [
                'ok'    => false,
                'data'  => null,
                'error' => 'Błąd DNS DNSBL dla IP ' . $ip . '.',
            ];
        }

        return [
            'ok'   => true,
            'data' => [
                'listed_any' => $listedAny,
                'listy'      => $listy,
            ],
            'error' => '',
        ];
    }

    public function budujFqdn(string $ip, string $strefa): string
    {
        $czesci = explode('.', $ip);
        if (count($czesci) !== 4) {
            throw new \InvalidArgumentException('invalid ipv4');
        }

        return implode('.', array_reverse($czesci)) . '.' . $strefa;
    }

    /**
     * @return array{listed: bool, kody: list<string>, error: string}
     */
    private function sprawdzStrefę(string $ip, string $id, string $strefa): array
    {
        $this->czekajRateLimit();

        try {
            $fqdn = $this->budujFqdn($ip, $strefa);
            $kody = $this->rozwiążA($fqdn);
        } catch (\Throwable) {
            return [
                'listed' => false,
                'kody'   => [],
                'error'  => 'błąd DNS',
            ];
        }

        $listed = false;
        $out    = [];
        foreach ($kody as $kod) {
            $kod = trim($kod);
            if ($kod === '') {
                continue;
            }
            if ($this->czyKodBleduSpamhaus($kod)) {
                return [
                    'listed' => false,
                    'kody'   => [$kod],
                    'error'  => 'kod błędu DNSBL ' . $kod,
                ];
            }
            if ($this->czyKodListed($kod)) {
                $listed = true;
                $out[]  = $kod;
            } else {
                return [
                    'listed' => false,
                    'kody'   => [$kod],
                    'error'  => 'nieoczekiwany kod A: ' . $kod,
                ];
            }
        }

        return ['listed' => $listed, 'kody' => $out, 'error' => ''];
    }

    private function czyKodBleduSpamhaus(string $kod): bool
    {
        return str_starts_with($kod, '127.255.255.');
    }

    private function czyKodListed(string $kod): bool
    {
        if (! filter_var($kod, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }
        $long = ip2long($kod);
        if ($long === false) {
            return false;
        }
        // 127.0.0.0/8 poza 127.255.255.*
        return ($long & 0xFF000000) === 0x7F000000 && ! $this->czyKodBleduSpamhaus($kod);
    }

    private function czekajRateLimit(): void
    {
        if ($this->resolver !== null) {
            // testy / mock — bez sleep
            return;
        }
        $minOdstep = 1.0 / $this->rateLimitQps;
        $teraz     = microtime(true);
        $delta     = $teraz - $this->ostatnieZapytanieAt;
        if ($this->ostatnieZapytanieAt > 0 && $delta < $minOdstep) {
            usleep((int) (($minOdstep - $delta) * 1_000_000));
        }
        $this->ostatnieZapytanieAt = microtime(true);
    }

    /**
     * @return list<string>
     */
    private function rozwiążA(string $fqdn): array
    {
        if ($this->resolver !== null) {
            $wynik = ($this->resolver)($fqdn);
            if (! is_array($wynik)) {
                // Resolver testowy/zewnętrzny zwrócił false/null = błąd, nie „czysto”.
                throw new \RuntimeException('resolver error');
            }
            $out = [];
            foreach ($wynik as $v) {
                if (is_string($v) && $v !== '') {
                    $out[] = $v;
                }
            }

            return $out;
        }

        // NXDOMAIN / NODATA => [] (czysto). SERVFAIL / timeout / REFUSED => false (błąd).
        // Błąd nigdy nie oznacza „czysto”: rzucamy wyjątek, sprawdzStrefę() ustawia error.
        $rekordy = @dns_get_record($fqdn, DNS_A);
        if ($rekordy === false) {
            throw new \RuntimeException('DNS lookup failed (SERVFAIL/timeout)');
        }
        if ($rekordy === []) {
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
