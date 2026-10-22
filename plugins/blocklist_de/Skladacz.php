<?php

namespace McsPluginy\blocklist_de;

use App\Libraries\WagaV1;

/**
 * Składa jeden incydent na IP z wielu list. Adresy spoza sieci odrzuca callback.
 */
final class Skladacz
{
    private WagaV1 $waga;

    public function __construct(?WagaV1 $waga = null)
    {
        $this->waga = $waga ?? new WagaV1();
    }

    /**
     * @param array<string, list<string>> $listyIp nazwa listy → adresy
     * @param callable(string): bool      $czyNasze
     *
     * @return list<array{
     *   ip: string,
     *   listy: list<string>,
     *   waga: string,
     *   wersja: string,
     *   kategoria: string,
     *   tytul: string,
     *   payload: array{listy: list<string>, liczba_list: int, atrybucja: string}
     * }>
     */
    public function incydenty(array $listyIp, callable $czyNasze): array
    {
        $perIp = [];
        foreach ($listyIp as $nazwa => $ips) {
            foreach ($ips as $ip) {
                $perIp[$ip][] = (string) $nazwa;
            }
        }

        $out = [];
        foreach ($perIp as $ip => $nazwy) {
            if (! $czyNasze((string) $ip)) {
                continue;
            }
            $nazwy    = Listy::posortuj($nazwy);
            $sygnaly  = in_array('strongips', $nazwy, true) ? ['severity' => 'critical'] : [];
            $wyliczona = $this->waga->wylicz('abuse', $sygnaly);
            $out[]    = [
                'ip'        => (string) $ip,
                'listy'     => $nazwy,
                'waga'      => $wyliczona['waga'],
                'wersja'    => $wyliczona['wersja'],
                'kategoria' => 'abuse',
                'tytul'     => 'blocklist.de: ' . implode(', ', $nazwy),
                'payload'   => [
                    'listy'       => $nazwy,
                    'liczba_list' => count($nazwy),
                    'atrybucja'   => 'blocklist.de, ostatnie 48 h',
                ],
            ];
        }

        return $out;
    }
}
