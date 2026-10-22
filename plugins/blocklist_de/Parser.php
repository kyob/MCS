<?php

namespace McsPluginy\blocklist_de;

/**
 * Plik listy: jeden adres IP na linię. Pusty plik jest poprawny.
 * Treść bez żadnego adresu jest błędem parsowania.
 */
final class Parser
{
    /**
     * @return array{ok: bool, ips: list<string>, blad: string}
     */
    public function parsuj(string $tresc): array
    {
        $tresc = str_replace("\r\n", "\n", $tresc);
        $tresc = str_replace("\r", "\n", $tresc);
        if (trim($tresc) === '') {
            return ['ok' => true, 'ips' => [], 'blad' => ''];
        }

        $ips      = [];
        $niepusta = false;
        foreach (explode("\n", $tresc) as $linia) {
            $linia = trim($linia);
            if ($linia === '') {
                continue;
            }
            $niepusta = true;
            if (str_starts_with($linia, '#')) {
                continue;
            }
            if (filter_var($linia, FILTER_VALIDATE_IP) !== false) {
                $ips[$linia] = $linia;
            }
        }

        if ($niepusta && $ips === []) {
            return ['ok' => false, 'ips' => [], 'blad' => 'plik ma treść i zero adresów IP'];
        }

        return ['ok' => true, 'ips' => array_values($ips), 'blad' => ''];
    }
}
