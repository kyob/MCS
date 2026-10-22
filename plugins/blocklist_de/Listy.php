<?php

namespace McsPluginy\blocklist_de;

/**
 * Allowlista nazw list blocklist.de. all.txt jest poza zestawem.
 */
final class Listy
{
    /** @var list<string> */
    public const DOZWOLONE = ['ssh', 'mail', 'bruteforcelogin', 'bots', 'strongips'];

    /**
     * Puste wejście = domyślna piątka. Nieznana nazwa albo same przecinki = błąd.
     *
     * @return array{ok: bool, listy: list<string>, blad: string}
     */
    public static function parsuj(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return ['ok' => true, 'listy' => self::DOZWOLONE, 'blad' => ''];
        }

        $nazwy = [];
        foreach (explode(',', $raw) as $czesc) {
            $nazwa = strtolower(trim($czesc));
            if ($nazwa === '') {
                continue;
            }
            if (! in_array($nazwa, self::DOZWOLONE, true)) {
                return ['ok' => false, 'listy' => [], 'blad' => 'nieznana lista ' . $nazwa];
            }
            if (! in_array($nazwa, $nazwy, true)) {
                $nazwy[] = $nazwa;
            }
        }

        if ($nazwy === []) {
            return ['ok' => false, 'listy' => [], 'blad' => 'pusta lista po odrzuceniu'];
        }

        return ['ok' => true, 'listy' => $nazwy, 'blad' => ''];
    }

    /**
     * @param list<string> $nazwy
     *
     * @return list<string>
     */
    public static function posortuj(array $nazwy): array
    {
        $kolejnosc = array_flip(self::DOZWOLONE);
        usort($nazwy, static function (string $a, string $b) use ($kolejnosc): int {
            return ($kolejnosc[$a] ?? 99) <=> ($kolejnosc[$b] ?? 99);
        });

        return array_values(array_unique($nazwy));
    }
}
