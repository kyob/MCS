<?php

namespace McsPluginy\botnet_cl;

use App\Libraries\Siec\CidrMatcher;
use App\Libraries\WagaV1;

/**
 * Obserwacja botnet.cl dotyczy nas, gdy jej prefiks nachodzi na prefiks z ustawień AS.
 * Zwracany adres to podmiot incydentu (host /32 albo adres sieci prefiksu).
 */
final class Dopasowanie
{
    private CidrMatcher $cidr;

    private WagaV1 $waga;

    public function __construct(?CidrMatcher $cidr = null, ?WagaV1 $waga = null)
    {
        $this->cidr = $cidr ?? new CidrMatcher();
        $this->waga = $waga ?? new WagaV1();
    }

    /**
     * @param list<array<string, mixed>> $obserwacje
     * @param list<string>               $nasze
     *
     * @return list<array<string, mixed>>
     */
    public function incydenty(array $obserwacje, array $nasze): array
    {
        $out = [];
        foreach ($obserwacje as $obs) {
            if (! is_array($obs)) {
                continue;
            }
            $ip = $this->adresNas(($obs['cidr'] ?? ''), $nasze);
            if ($ip === null) {
                continue;
            }
            $metoda    = (string) ($obs['metoda'] ?? '');
            $kategoria = $this->kategoria($metoda);
            $wyliczona = $this->waga->wylicz($kategoria);
            $cidr      = (string) $obs['cidr'];
            $out[]     = [
                'id'               => (string) $obs['id'],
                'ip'               => $ip,
                'waga'             => $wyliczona['waga'],
                'wersja'           => $wyliczona['wersja'],
                'kategoria'        => $wyliczona['kategoria'],
                'tytul'            => mb_substr('botnet.cl: ' . $metoda . ' (' . $cidr . ')', 0, 255),
                'port'             => (int) $obs['port'],
                'protokol'         => (string) $obs['protokol'],
                'zaobserwowano_at' => (string) $obs['zaobserwowano_at'],
                'payload'          => [
                    'zrodlo_url'   => 'https://botnet.cl/attack/' . $obs['id'],
                    'cidr'         => $cidr,
                    'metoda'       => $metoda,
                    'kraj'         => (string) ($obs['kraj'] ?? ''),
                    'czas_trwania' => (string) ($obs['czas_trwania'] ?? ''),
                ],
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $nasze
     */
    public function adresNas(string $cidr, array $nasze): ?string
    {
        $cidr = trim($cidr);
        if (! preg_match('#^((?:\d{1,3}\.){3}\d{1,3})/(\d{1,2})$#', $cidr, $m)) {
            return null;
        }
        $siec = $m[1];
        $maska = (int) $m[2];
        if ($maska < 16 || $maska > 32 || filter_var($siec, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        if ($this->cidr->ipWLiscie($siec, $nasze)) {
            return $siec;
        }
        foreach ($nasze as $nasz) {
            $adres = str_contains($nasz, '/') ? explode('/', $nasz, 2)[0] : $nasz;
            if ($this->cidr->ipWCidr($adres, $cidr)) {
                return $adres;
            }
        }

        return null;
    }

    private function kategoria(string $metoda): string
    {
        $n = strtolower($metoda);
        if (str_contains($n, 'flood') || str_contains($n, 'amplification') || str_contains($n, 'udp')) {
            return 'amplification';
        }

        return 'abuse';
    }
}
