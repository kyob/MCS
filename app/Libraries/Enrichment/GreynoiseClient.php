<?php

namespace App\Libraries\Enrichment;

/**
 * GET https://api.greynoise.io/v3/community/{ip}
 * Nagłówek key. 200 i 404 są wynikiem. 429 i 5xx są błędem.
 */
class GreynoiseClient
{
    private string $apiKey;

    private string $baseUrl;

    private int $timeout;

    /** @var (callable(string, array<string, string>): array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string})|null */
    private $httpRequest;

    /**
     * @param (callable(string, array<string, string>): array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string})|null $httpRequest
     */
    public function __construct(
        string $apiKey = '',
        string $baseUrl = 'https://api.greynoise.io',
        int $timeout = 15,
        ?callable $httpRequest = null,
    ) {
        $this->apiKey      = trim($apiKey);
        $this->baseUrl     = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.greynoise.io', '/');
        $this->timeout     = max(1, $timeout);
        $this->httpRequest = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $key  = trim(\App\Libraries\OpcjeMcs::tekst('mcs.greynoise.apiKey'));
        $base = trim((string) env('mcs.greynoise.baseUrl', 'https://api.greynoise.io'));

        return new self(
            $key,
            $base !== '' ? $base : 'https://api.greynoise.io',
            \App\Libraries\OpcjeMcs::int('mcs.greynoise.httpTimeout'),
            $httpRequest,
        );
    }

    public function hasCredentials(): bool
    {
        return $this->apiKey !== '';
    }

    public function brakCredentialsKomunikat(): string
    {
        return $this->hasCredentials() ? '' : 'Brak klucza GreyNoise. Wtyczki → GreyNoise → Klucz.';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string}
     */
    public function lookup(string $ip): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return $this->blad(0, 'Wymagany prawidłowy adres IP.');
        }
        if (! $this->hasCredentials()) {
            return $this->blad(0, $this->brakCredentialsKomunikat());
        }

        $url = $this->baseUrl . '/v3/community/' . $ip;
        if ($this->httpRequest !== null) {
            $res = ($this->httpRequest)($url, ['key' => $this->apiKey, 'Accept' => 'application/json']);

            return $this->znormalizuj($res);
        }

        return $this->znormalizuj($this->curl($url));
    }

    /**
     * @param array{ok?: bool, http_code?: int, data?: mixed, error?: string, raw_body?: string} $res
     *
     * @return array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string}
     */
    private function znormalizuj(array $res): array
    {
        $code = (int) ($res['http_code'] ?? 0);
        $body = (string) ($res['raw_body'] ?? '');
        $data = $res['data'] ?? null;
        if (! is_array($data) && $body !== '') {
            $zdekodowane = json_decode($body, true);
            $data        = is_array($zdekodowane) ? $zdekodowane : null;
        }

        if ($code === 200 || $code === 404) {
            if (! is_array($data)) {
                $data = [];
            }
            if ($code === 404 && ! isset($data['message'])) {
                $data['message'] = 'IP nie występuje w danych Community';
            }

            return [
                'ok'        => true,
                'http_code' => $code,
                'data'      => $data,
                'error'     => '',
                'raw_body'  => $body,
            ];
        }

        $msg = '';
        if (is_array($data) && isset($data['message']) && is_string($data['message'])) {
            $msg = $data['message'];
        }
        if ($msg === '') {
            $msg = (string) ($res['error'] ?? '');
        }
        if ($msg === '') {
            $msg = $code === 429 ? 'limit GreyNoise' : 'HTTP ' . $code;
        }

        return $this->blad($code, $msg, $body);
    }

    /**
     * @return array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string}
     */
    private function blad(int $code, string $msg, string $body = ''): array
    {
        return [
            'ok'        => false,
            'http_code' => $code,
            'data'      => null,
            'error'     => $msg,
            'raw_body'  => $body,
        ];
    }

    /**
     * @return array{ok: bool, http_code: int, data: mixed, error: string, raw_body: string}
     */
    private function curl(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'key: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'data' => null, 'error' => 'cURL: ' . $err, 'raw_body' => ''];
        }

        return [
            'ok'        => $code === 200 || $code === 404,
            'http_code' => $code,
            'data'      => null,
            'error'     => ($code === 200 || $code === 404) ? '' : 'HTTP ' . $code,
            'raw_body'  => (string) $out,
        ];
    }
}
