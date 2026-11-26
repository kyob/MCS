<?php

namespace McsPluginy\urlhaus;

/**
 * Pobranie zrzutu recent. Klucz wchodzi do URL tylko w pamięci i nie wraca w komunikacie błędu.
 */
final class Klient
{
    public const DOMYSLNY_SZABLON = 'https://urlhaus-api.abuse.ch/v2/files/exports/{authKey}/recent.csv';

    public const USER_AGENT = 'MCS/1.0 (+https://github.com/kyob/MCS)';

    /** @var (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null */
    private $http;

    private int $timeout;

    /**
     * @param (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null $http
     */
    public function __construct(?callable $http = null, int $timeout = 60)
    {
        $this->http    = $http;
        $this->timeout = max(1, $timeout);
    }

    public static function fromOpcje(?callable $http = null): self
    {
        $timeout = (int) env('mcs.urlhaus.httpTimeout', 60);

        return new self($http, $timeout > 0 ? $timeout : 60);
    }

    public static function szablonZEnv(): string
    {
        $szablon = trim((string) env('mcs.urlhaus.exportUrl', self::DOMYSLNY_SZABLON));

        return $szablon !== '' ? $szablon : self::DOMYSLNY_SZABLON;
    }

    /**
     * @return array{ok: bool, body: string, blad: string}
     */
    public function pobierz(string $szablon, string $klucz): array
    {
        if (! str_contains($szablon, '{authKey}')) {
            return ['ok' => false, 'body' => '', 'blad' => 'mcs.urlhaus.exportUrl nie zawiera {authKey}'];
        }

        $url  = str_replace('{authKey}', rawurlencode($klucz), $szablon);
        $czesci = parse_url($url);
        $schemat = is_array($czesci) ? (string) ($czesci['scheme'] ?? '') : '';
        $host    = is_array($czesci) ? (string) ($czesci['host'] ?? '') : '';
        if ($schemat !== 'https' || $host === '') {
            return ['ok' => false, 'body' => '', 'blad' => 'URL zrzutu recent.csv musi być https'];
        }

        $res = $this->http !== null
            ? ($this->http)($url, $this->timeout)
            : $this->curl($url);
        $blad = $this->bezKlucza((string) ($res['error'] ?? ''), $klucz);
        if (! ($res['ok'] ?? false)) {
            $opis = $blad !== '' ? $blad : 'HTTP ' . (int) ($res['http_code'] ?? 0);

            return ['ok' => false, 'body' => '', 'blad' => 'recent.csv: ' . $this->bezKlucza($opis, $klucz)];
        }

        return ['ok' => true, 'body' => (string) ($res['body'] ?? ''), 'blad' => ''];
    }

    private function bezKlucza(string $msg, string $klucz): string
    {
        if ($klucz === '') {
            return $msg;
        }

        return str_replace([$klucz, rawurlencode($klucz)], '[klucz]', $msg);
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    private function curl(string $url): array
    {
        $res = \App\Libraries\Siec\KlientHttp::wykonaj($url, [
            'timeout'    => max(\App\Libraries\Siec\KlientHttp::MAKS_CZAS_POBRANIA, $this->timeout),
            'polaczenie' => min(15, $this->timeout),
            'zastoj'     => $this->timeout,
            'kompresja'  => true,
            'user_agent' => self::USER_AGENT,
            'naglowki'   => ['Accept: text/csv, text/plain'],
        ]);

        if (! $res['ok']) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'body' => '', 'error' => 'cURL: ' . $res['error']];
        }
        if ($res['http_code'] !== 200) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'body' => '', 'error' => 'HTTP ' . $res['http_code']];
        }

        return ['ok' => true, 'http_code' => 200, 'body' => $res['body'], 'error' => ''];
    }
}
