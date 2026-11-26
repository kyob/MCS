<?php

namespace McsPluginy\spamhaus;

/**
 * Pobieranie plików Spamhaus DROP (HTTPS GET, bez klucza).
 *
 * Oficjalne adresy (zweryfikowane 2026-10-14):
 *   https://www.spamhaus.org/blocklists/do-not-route-or-peer/  — sekcja Download / FAQ
 *   https://www.spamhaus.org/blocklists/drop-fair-use-policy/   — warunki (Terms: https://www.spamhaus.org/drop/terms/)
 * FAQ: „Please DO NOT auto-fetch the DROP list more than once per hour!”,
 *      „once per day is more than enough in most cases”. MCS pobiera raz na dobę (Plugin::pobierz()).
 *
 * Bez ponowień w jednym przebiegu: błąd kończy przebieg, backoff liczy Harmonogram.
 */
class DropKlient
{
    public const URL_BAZA = 'https://www.spamhaus.org/drop/';

    /** @var array<string, string> lista → plik */
    public const PLIKI = [
        'drop_v4' => 'drop_v4.json',
        'drop_v6' => 'drop_v6.json',
        'asndrop' => 'asndrop.json',
    ];

    /** Domyślny User-Agent. Własny ustaw w .env: mcs.spamhaus.userAgent (np. z nazwą firmy i kontaktem). */
    public const USER_AGENT = 'MCS/1.0 (+https://github.com/kyob/MCS)';

    /** @var (callable(string, int): array{ok: bool, http_code: int, body: string, error: string, retry_after: int})|null */
    private $http;

    private int $timeout;

    /**
     * @param (callable(string, int): array{ok: bool, http_code: int, body: string, error: string, retry_after: int})|null $http
     */
    public function __construct(?callable $http = null, int $timeout = 30)
    {
        $this->http    = $http;
        $this->timeout = max(1, $timeout);
    }

    public static function fromOpcje(): self
    {
        return new self(null, \App\Libraries\OpcjeMcs::int('mcs.spamhaus.httpTimeout'));
    }

    public static function userAgent(): string
    {
        $ua = trim((string) env('mcs.spamhaus.userAgent', ''), " \t\n\r\0\x0B'\"");

        return $ua !== '' ? $ua : self::USER_AGENT;
    }

    public static function url(string $lista): string
    {
        return self::URL_BAZA . (self::PLIKI[$lista] ?? '');
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string, retry_after: int}
     */
    public function pobierz(string $lista): array
    {
        if (! isset(self::PLIKI[$lista])) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'nieznana lista ' . $lista, 'retry_after' => 0];
        }

        $res = $this->http !== null
            ? ($this->http)(self::url($lista), $this->timeout)
            : $this->curl(self::url($lista));

        if ($res['ok'] && trim($res['body']) === '') {
            return ['ok' => false, 'error' => 'HTTP ' . $res['http_code'] . ': pusta odpowiedź'] + $res;
        }

        return $res;
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string, retry_after: int}
     */
    private function curl(string $url): array
    {
        $res = \App\Libraries\Siec\KlientHttp::wykonaj($url, [
            'timeout'        => max(\App\Libraries\Siec\KlientHttp::MAKS_CZAS_POBRANIA, $this->timeout),
            'polaczenie'     => min(15, $this->timeout),
            'zastoj'         => $this->timeout,
            'przekierowania' => 3,
            'user_agent'     => self::userAgent(),
            'naglowki'       => ['Accept: application/json, text/plain'],
        ]);

        if (! $res['ok']) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'body' => '', 'error' => 'cURL: ' . $res['error'], 'retry_after' => 0];
        }
        if ($res['http_code'] !== 200) {
            return [
                'ok'          => false,
                'http_code'   => $res['http_code'],
                'body'        => '',
                'error'       => 'HTTP ' . $res['http_code'],
                'retry_after' => \App\Libraries\Siec\KlientHttp::retryAfter($res['naglowki']),
            ];
        }

        return ['ok' => true, 'http_code' => $res['http_code'], 'body' => $res['body'], 'error' => '', 'retry_after' => 0];
    }
}
