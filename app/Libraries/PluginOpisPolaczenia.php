<?php

namespace App\Libraries;

/**
 * Bezpieczny (bez haseł i tokenów) opis skąd i jak wtyczka bierze dane.
 */
final class PluginOpisPolaczenia
{
    public const PUSTE = 'nie ustawione';

    /**
     * @param list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}> $pozycje
     *
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public static function zloz(
        string $trybAktywny,
        string $trybEtykieta,
        string $skrot,
        array $pozycje,
    ): array {
        return [
            'skrot'         => $skrot,
            'tryb_aktywny'  => $trybAktywny,
            'tryb_etykieta' => $trybEtykieta,
            'pozycje'       => $pozycje,
        ];
    }

    /**
     * @return array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}
     */
    public static function pozycja(
        string $nazwa,
        string $wartosc,
        bool $aktywna = false,
        ?bool $ustawiona = null,
    ): array {
        $wartosc = trim($wartosc);
        $jest    = $wartosc !== '' && $wartosc !== self::PUSTE;

        return [
            'nazwa'     => $nazwa,
            'wartosc'   => $jest ? $wartosc : self::PUSTE,
            'aktywna'   => $aktywna,
            'ustawiona' => $ustawiona ?? $jest,
        ];
    }

    public static function hostZUrl(string $url): string
    {
        $host = parse_url(trim($url), PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : '';
    }

    public static function sekret(bool $ustawiony): string
    {
        return $ustawiony ? 'ustawiony' : 'brak';
    }

    /**
     * Komunikaty health / logi — bez angielskiego „fixture” w GUI.
     */
    public static function bezZargonu(string $tekst): string
    {
        return str_replace(
            [
                'tryb=fixture',
                'import fixture',
                'gotowy fixture',
                'tryb fixturePath',
                'konfiguracja: tryb fixturePath',
            ],
            [
                'tryb=plik testowy',
                'import pliku testowego',
                'gotowy plik testowy',
                'tryb pliku testowego',
                'konfiguracja: tryb pliku testowego',
            ],
            $tekst,
        );
    }

    /**
     * IMAP (Play, Shadowserver): plik testowy ma pierwszeństwo przed skrzynką.
     *
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public static function imap(string $envPrefix): array
    {
        $plik  = trim((string) env($envPrefix . '.fixturePath', ''));
        $host  = trim((string) env($envPrefix . '.imapHost', ''));
        $port  = trim((string) env($envPrefix . '.imapPort', '993'));
        $user  = trim((string) env($envPrefix . '.imapUser', ''));
        $mbox  = trim((string) env($envPrefix . '.imapMailbox', 'INBOX'));
        $haslo = trim((string) env($envPrefix . '.imapPass', '')) !== '';
        $szyfr = trim((string) env($envPrefix . '.imapEncryption', 'ssl'));

        $imapOpis = $host === '' ? '' : $host . ':' . ($port !== '' ? $port : '993');
        if ($imapOpis !== '' && $szyfr !== '') {
            $imapOpis .= ' (' . $szyfr . ')';
        }

        $aktywnyPlik = $plik !== '';
        $aktywnyImap = ! $aktywnyPlik && $host !== '' && $user !== '';
        $tryb        = $aktywnyPlik ? 'plik_testowy' : ($aktywnyImap ? 'imap' : 'brak');
        $etykieta    = match ($tryb) {
            'plik_testowy' => 'plik testowy (lokalny)',
            'imap'         => 'IMAP',
            default        => 'brak konfiguracji',
        };
        $skrot = match ($tryb) {
            'plik_testowy' => 'plik testowy · ' . basename($plik),
            'imap'         => 'IMAP · ' . $host,
            default        => 'brak konfiguracji',
        };

        return self::zloz($tryb, $etykieta, $skrot, [
            self::pozycja('Plik testowy (lokalny)', $plik, $aktywnyPlik),
            self::pozycja('Serwer IMAP', $imapOpis, $aktywnyImap),
            self::pozycja('Skrzynka', $mbox, false, $mbox !== ''),
            self::pozycja('Użytkownik', $user, false),
            self::pozycja('Hasło', self::sekret($haslo), false, $haslo),
        ]);
    }

    /**
     * Typowy importer: lokalny plik testowy albo jeden kanał (HTTP/API).
     *
     * @param list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}> $extra
     *
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public static function plikIKanal(
        string $plik,
        string $kanalNazwa,
        string $kanalWartosc,
        bool $kanalGotowy,
        string $kanalKod,
        string $kanalEtykieta,
        array $extra = [],
    ): array {
        $aktywnyPlik  = trim($plik) !== '';
        $aktywnyKanal = ! $aktywnyPlik && $kanalGotowy;
        $tryb         = $aktywnyPlik ? 'plik_testowy' : ($aktywnyKanal ? $kanalKod : 'brak');
        $etykieta     = $tryb === 'plik_testowy'
            ? 'plik testowy (lokalny)'
            : ($tryb === $kanalKod ? $kanalEtykieta : 'brak konfiguracji');
        $host         = self::hostZUrl($kanalWartosc);
        $skrot        = $aktywnyPlik
            ? 'plik testowy · ' . basename($plik)
            : ($aktywnyKanal
                ? $kanalEtykieta . ' · ' . ($host !== '' ? $host : $kanalWartosc)
                : 'brak konfiguracji');

        $pozycje = [
            self::pozycja('Plik testowy (lokalny)', $plik, $aktywnyPlik),
            self::pozycja($kanalNazwa, $kanalWartosc, $aktywnyKanal),
            ...$extra,
        ];

        return self::zloz($tryb, $etykieta, $skrot, $pozycje);
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public static function brak(): array
    {
        return self::zloz('brak', 'brak konfiguracji', 'brak konfiguracji', []);
    }

    /**
     * @param list<array<string, mixed>> $wiersze
     * @param list<SourcePluginInterface> $pluginy
     *
     * @return list<array<string, mixed>>
     */
    public static function dolaczDoWierszy(array $wiersze, array $pluginy): array
    {
        $mapa = [];
        foreach ($pluginy as $plugin) {
            $mapa[$plugin->zrodlo()] = $plugin->opisPolaczenia();
        }

        foreach ($wiersze as &$wiersz) {
            $zrodlo = (string) ($wiersz['zrodlo'] ?? '');
            $wiersz['opis_polaczenia'] = $mapa[$zrodlo] ?? self::brak();
        }
        unset($wiersz);

        return $wiersze;
    }
}
