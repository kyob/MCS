<?php

namespace McsPluginy\urlhaus;

use App\Libraries\WagaV1;

/**
 * Jeden incydent na IP. Payload trzyma co najwyżej 20 URL-i i pełną liczbę.
 */
final class Skladacz
{
    public const LIMIT_URL = 20;

    private WagaV1 $waga;

    public function __construct(?WagaV1 $waga = null)
    {
        $this->waga = $waga ?? new WagaV1();
    }

    /**
     * @param list<array{id: string, dateadded: string, url: string, threat: string, tags: string, urlhaus_link: string, ip: string}> $wiersze
     * @param callable(string): bool $czyNasze
     *
     * @return list<array{ip: string, waga: string, wersja: string, kategoria: string, tytul: string, payload: array{liczba: int, url: list<array<string, string>>}}>
     */
    public function incydenty(array $wiersze, callable $czyNasze): array
    {
        $perIp = [];
        foreach ($wiersze as $wiersz) {
            $ip = $wiersz['ip'];
            if (! $czyNasze($ip)) {
                continue;
            }
            $perIp[$ip][] = [
                'id'           => $wiersz['id'],
                'url'          => $wiersz['url'],
                'threat'       => $wiersz['threat'],
                'tags'         => $wiersz['tags'],
                'urlhaus_link' => $wiersz['urlhaus_link'],
                'dateadded'    => $wiersz['dateadded'],
            ];
        }

        $wyliczona = $this->waga->wylicz('malware');
        $out       = [];
        foreach ($perIp as $ip => $url) {
            $liczba = count($url);
            $out[]  = [
                'ip'        => $ip,
                'waga'      => $wyliczona['waga'],
                'wersja'    => $wyliczona['wersja'],
                'kategoria' => 'malware',
                'tytul'     => 'URLhaus: ' . $liczba . ' aktywnych URL',
                'payload'   => [
                    'liczba' => $liczba,
                    'url'    => array_slice($url, 0, self::LIMIT_URL),
                ],
            ];
        }

        return $out;
    }
}
