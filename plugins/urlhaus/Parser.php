<?php

namespace McsPluginy\urlhaus;

/**
 * CSV recent URLhaus. Do incydentu kwalifikuje się wyłącznie host będący adresem IP
 * i url_status=online. Domeny są liczone i pomijane (bez DNS).
 */
final class Parser
{
    /** @var list<string> */
    private const WYMAGANE = ['id', 'dateadded', 'url', 'url_status', 'threat', 'tags', 'urlhaus_link'];

    /**
     * @return array{
     *   ok: bool,
     *   blad: string,
     *   pominiete_domeny: int,
     *   wiersze: list<array{id: string, dateadded: string, url: string, threat: string, tags: string, urlhaus_link: string, ip: string}>
     * }
     */
    public function parsuj(string $tresc): array
    {
        if (str_starts_with($tresc, "PK")) {
            return $this->blad('odpowiedź ZIP, oczekiwany CSV');
        }

        $linie = preg_split("/\r\n|\n|\r/", $tresc) ?: [];
        $czyste = [];
        foreach ($linie as $linia) {
            $trim = ltrim($linia);
            if (str_starts_with($trim, '#')) {
                // Zrzut abuse.ch ma nagłówek jako komentarz: "# id,dateadded,..."
                $poKrzyzyku = ltrim(substr($trim, 1));
                if ($this->pierwszePole($poKrzyzyku) === 'id') {
                    $czyste[] = $poKrzyzyku;
                }

                continue;
            }
            if (trim($linia) === '') {
                continue;
            }
            $czyste[] = $linia;
        }
        if ($czyste === []) {
            return $this->blad('pusty CSV');
        }

        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            return $this->blad('nie udało się czytać CSV');
        }
        fwrite($fp, implode("\n", $czyste));
        rewind($fp);
        $naglowek = fgetcsv($fp, null, ',', '"', '');
        if (! is_array($naglowek)) {
            fclose($fp);

            return $this->blad('CSV bez nagłówka');
        }
        $indeks = [];
        foreach ($naglowek as $i => $nazwa) {
            $indeks[strtolower(trim((string) $nazwa))] = $i;
        }
        foreach (self::WYMAGANE as $kolumna) {
            if (! array_key_exists($kolumna, $indeks)) {
                fclose($fp);

                return $this->blad('CSV bez kolumny ' . $kolumna);
            }
        }

        $wiersze = [];
        $domeny  = 0;
        while (($wiersz = fgetcsv($fp, null, ',', '"', '')) !== false) {
            if ($wiersz === [null] || $wiersz === false) {
                continue;
            }
            $url    = $this->pole($wiersz, $indeks, 'url');
            $status = strtolower($this->pole($wiersz, $indeks, 'url_status'));
            $host   = $this->host($url);
            if ($host === null) {
                continue;
            }
            if (filter_var($host, FILTER_VALIDATE_IP) === false) {
                $domeny++;

                continue;
            }
            if ($status !== 'online') {
                continue;
            }
            $wiersze[] = [
                'id'           => $this->pole($wiersz, $indeks, 'id'),
                'dateadded'    => $this->pole($wiersz, $indeks, 'dateadded'),
                'url'          => $url,
                'threat'       => $this->pole($wiersz, $indeks, 'threat'),
                'tags'         => $this->pole($wiersz, $indeks, 'tags'),
                'urlhaus_link' => $this->pole($wiersz, $indeks, 'urlhaus_link'),
                'ip'           => $host,
            ];
        }
        fclose($fp);

        return ['ok' => true, 'blad' => '', 'pominiete_domeny' => $domeny, 'wiersze' => $wiersze];
    }

    /**
     * @param list<string|null> $wiersz
     * @param array<string, int> $indeks
     */
    private function pole(array $wiersz, array $indeks, string $nazwa): string
    {
        $i = $indeks[$nazwa];

        return trim((string) ($wiersz[$i] ?? ''));
    }

    private function pierwszePole(string $linia): string
    {
        if ($linia === '') {
            return '';
        }
        $pola = str_getcsv($linia, ',', '"', '');

        return strtolower(trim((string) ($pola[0] ?? '')));
    }

    private function host(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        return $host;
    }

    /**
     * @return array{ok: bool, blad: string, pominiete_domeny: int, wiersze: list<array{id: string, dateadded: string, url: string, threat: string, tags: string, urlhaus_link: string, ip: string}>}
     */
    private function blad(string $msg): array
    {
        return ['ok' => false, 'blad' => $msg, 'pominiete_domeny' => 0, 'wiersze' => []];
    }
}
