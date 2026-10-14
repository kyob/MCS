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
        $retryAfter = 0;
        $ch         = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => self::userAgent(),
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/plain'],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linia) use (&$retryAfter): int {
                if (stripos($linia, 'retry-after:') === 0) {
                    $retryAfter = (int) trim(substr($linia, 12));
                }

                return strlen($linia);
            },
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'cURL: ' . $err, 'retry_after' => 0];
        }
        if ($code !== 200) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'HTTP ' . $code, 'retry_after' => $retryAfter];
        }

        return ['ok' => true, 'http_code' => $code, 'body' => (string) $out, 'error' => '', 'retry_after' => 0];
    }
}
