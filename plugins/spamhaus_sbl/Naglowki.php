<?php

namespace McsPluginy\spamhaus_sbl;

/**
 * Minimalny parser surowego bloku nagłówków RFC 5322 (unfolding, bez dekodowania MIME).
 * Treść jest niezaufana: limit rozmiaru i liczby nagłówków.
 */
final class Naglowki
{
    private const MAX_BAJTOW = 262144;

    private const MAX_NAGLOWKOW = 500;

    /** @var list<array{nazwa: string, wartosc: string}> */
    private array $lista = [];

    public function __construct(string $surowe)
    {
        $surowe = substr($surowe, 0, self::MAX_BAJTOW);
        // Tylko blok nagłówków (do pierwszej pustej linii).
        $koniec = preg_split('/\r?\n\r?\n/', $surowe, 2);
        $blok   = is_array($koniec) ? (string) $koniec[0] : $surowe;
        $blok   = str_replace("\r\n", "\n", $blok);
        // Unfolding: CRLF + WSP = kontynuacja.
        $blok = (string) preg_replace("/\n[ \t]+/", ' ', $blok);

        foreach (explode("\n", $blok) as $linia) {
            if (count($this->lista) >= self::MAX_NAGLOWKOW) {
                break;
            }
            $poz = strpos($linia, ':');
            if ($poz === false || $poz === 0) {
                continue;
            }
            $nazwa = substr($linia, 0, $poz);
            if (preg_match('/^[!-9;-~]+$/', $nazwa) !== 1) {
                continue;
            }
            $this->lista[] = [
                'nazwa'   => strtolower($nazwa),
                'wartosc' => trim(substr($linia, $poz + 1)),
            ];
        }
    }

    /**
     * Wartości nagłówka w kolejności z wiadomości (pierwszy = najwyżej = dodany ostatni).
     *
     * @return list<string>
     */
    public function wszystkie(string $nazwa): array
    {
        $nazwa = strtolower($nazwa);
        $out   = [];
        foreach ($this->lista as $n) {
            if ($n['nazwa'] === $nazwa) {
                $out[] = $n['wartosc'];
            }
        }

        return $out;
    }

    public function pierwszy(string $nazwa): ?string
    {
        return $this->wszystkie($nazwa)[0] ?? null;
    }

    public function pusty(): bool
    {
        return $this->lista === [];
    }
}
