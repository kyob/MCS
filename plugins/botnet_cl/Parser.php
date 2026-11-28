<?php

namespace McsPluginy\botnet_cl;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Lista „Recent observations” ze strony https://botnet.cl/asn/{asn}.
 * botnet.cl nie wystawia JSON — obserwacje są w HTML.
 */
final class Parser
{
    /**
     * @return array{ok: bool, blad: string, obserwacje: list<array{
     *   id: string,
     *   zaobserwowano_at: string,
     *   metoda: string,
     *   cidr: string,
     *   protokol: string,
     *   port: int,
     *   kraj: string,
     *   czas_trwania: string
     * }>}
     */
    public function parsuj(string $html): array
    {
        $html = trim($html);
        if ($html === '' || ! str_contains($html, 'Recent observations')) {
            return ['ok' => false, 'blad' => 'brak sekcji Recent observations', 'obserwacje' => []];
        }

        $doc = new DOMDocument();
        $poprzedni = libxml_use_internal_errors(true);
        $wczytano = $doc->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();
        libxml_use_internal_errors($poprzedni);
        if ($wczytano === false) {
            return ['ok' => false, 'blad' => 'nie udało się odczytać HTML', 'obserwacje' => []];
        }

        $xpath = new DOMXPath($doc);
        $listy = $xpath->query('//h2[normalize-space()="Recent observations"]/following::ul[1]');
        $ul    = $listy !== false ? $listy->item(0) : null;
        if (! $ul instanceof DOMElement) {
            return ['ok' => false, 'blad' => 'brak listy obserwacji', 'obserwacje' => []];
        }

        $obserwacje = [];
        $linki      = $xpath->query('.//a[starts-with(@href, "/attack/")]', $ul);
        if ($linki === false) {
            return ['ok' => false, 'blad' => 'brak listy obserwacji', 'obserwacje' => []];
        }

        foreach ($linki as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $wiersz = $this->wiersz($a);
            if ($wiersz !== null) {
                $obserwacje[] = $wiersz;
            }
        }

        return ['ok' => true, 'blad' => '', 'obserwacje' => $obserwacje];
    }

    /**
     * @return array{
     *   id: string,
     *   zaobserwowano_at: string,
     *   metoda: string,
     *   cidr: string,
     *   protokol: string,
     *   port: int,
     *   kraj: string,
     *   czas_trwania: string
     * }|null
     */
    private function wiersz(DOMElement $a): ?array
    {
        $href = $a->getAttribute('href');
        if (! preg_match('#^/attack/([0-9a-f-]{36})$#i', $href, $id)) {
            return null;
        }

        $czas = '';
        $czasy = $a->getElementsByTagName('time');
        if ($czasy->length > 0 && $czasy->item(0) instanceof DOMElement) {
            $czas = $this->czas($czasy->item(0)->getAttribute('datetime'));
        }
        if ($czas === '') {
            return null;
        }

        $metoda = '';
        $cidr   = '';
        $proto  = '';
        $port   = 0;
        $kraj   = '';
        $trwa   = '';

        foreach ($a->getElementsByTagName('span') as $span) {
            if (! $span instanceof DOMElement) {
                continue;
            }
            if ($span->getElementsByTagName('time')->length > 0) {
                continue;
            }
            $tekst = trim(preg_replace('/\s+/u', ' ', $span->textContent) ?? '');
            if ($tekst === '' || preg_match('/\d{4}-\d{2}-\d{2}/', $tekst) === 1) {
                continue;
            }
            $klasa = $span->getAttribute('class');
            if (str_contains($klasa, 'sr-only')) {
                $kraj = $tekst;

                continue;
            }
            if (preg_match('#^(?:\d{1,3}\.){3}\d{1,3}/\d{1,2}$#', $tekst) === 1) {
                $cidr = $tekst;

                continue;
            }
            if (preg_match('#^(TCP|UDP|ICMP)/(\d{1,5})$#i', $tekst, $pp) === 1) {
                $proto = strtolower($pp[1]);
                $port  = (int) $pp[2];

                continue;
            }
            if (preg_match('#^\d+[smh]$#', $tekst) === 1) {
                $trwa = $tekst;

                continue;
            }
            if ($metoda === '' && preg_match('/\p{L}/u', $tekst) === 1) {
                $metoda = $tekst;
            }
        }

        if ($cidr === '' || $metoda === '' || $port < 1 || $port > 65535 || $proto === '') {
            return null;
        }

        return [
            'id'                => strtolower($id[1]),
            'zaobserwowano_at'  => $czas,
            'metoda'            => $metoda,
            'cidr'              => $cidr,
            'protokol'          => $proto,
            'port'              => $port,
            'kraj'              => $kraj,
            'czas_trwania'      => $trwa,
        ];
    }

    private function czas(string $iso): string
    {
        $iso = trim($iso);
        if ($iso === '') {
            return '';
        }
        try {
            return (new \DateTimeImmutable($iso))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:sP');
        } catch (\Exception) {
            return '';
        }
    }
}
