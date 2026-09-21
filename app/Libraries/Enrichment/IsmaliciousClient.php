<?php

namespace App\Libraries\Enrichment;

/**
 * Klient isMalicious API — GET /check (enrichment, nie stream).
 *
 * Auth: nagłówek `X-API-KEY: base64(apiKey:apiSecret)` albo gotowy credential.
 *
 * @see https://ismalicious.com/api-docs/authentication
 */
class IsmaliciousClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiSecret;
    private string $credentialB64;
    private string $enrichment;

    /** @var callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null $httpRequest
     */
    public function __construct(
        string $apiKey = '',
        string $apiSecret = '',
        string $credentialB64 = '',
        string $baseUrl = 'https://api.ismalicious.com',
        string $enrichment = 'standard',
        ?callable $httpRequest = null,
    ) {
        $this->apiKey         = trim($apiKey);
        $this->apiSecret      = trim($apiSecret);
        $this->credentialB64  = trim($credentialB64);
        $this->baseUrl        = rtrim($baseUrl !== '' ? $baseUrl : 'https://api.ismalicious.com', '/');
        $this->enrichment     = $this->normalizujEnrichment($enrichment);
        $this->httpRequest    = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $key        = trim((string) env('mcs.ismalicious.apiKey', ''));
        $secret     = trim((string) env('mcs.ismalicious.apiSecret', ''));
        $credential = trim((string) env('mcs.ismalicious.apiCredential', ''));
        $base       = trim((string) env('mcs.ismalicious.baseUrl', 'https://api.ismalicious.com'));
        $enrichment = trim((string) env('mcs.ismalicious.enrichment', 'standard'));

        return new self(
            $key,
            $secret,
            $credential,
            $base !== '' ? $base : 'https://api.ismalicious.com',
            $enrichment,
            $httpRequest,
        );
    }

    public function hasCredentials(): bool
    {
        if ($this->credentialB64 !== '') {
            return true;
        }

        return $this->apiKey !== '' && $this->apiSecret !== '';
    }

    /**
     * Komunikat gdy brak pełnych credentials (dla UI / CLI).
     */
    public function brakCredentialsKomunikat(): string
    {
        if ($this->hasCredentials()) {
            return '';
        }
        if ($this->apiKey !== '' && $this->apiSecret === '') {
            return 'Brak mcs.ismalicious.apiSecret — isMalicious wymaga pary apiKey:apiSecret (Account → API keys), albo mcs.ismalicious.apiCredential = base64(key:secret).';
        }
        if ($this->apiKey === '' && $this->apiSecret !== '') {
            return 'Brak mcs.ismalicious.apiKey — uzupełnij klucz z dashboardu isMalicious.';
        }

        return 'Brak mcs.ismalicious.apiKey+apiSecret (lub apiCredential) — ustaw w .env albo fixturePath.';
    }

    public function enrichment(): string
    {
        return $this->enrichment;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Lookup IP / domeny / URL / hash przez `query`.
     *
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function check(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'empty query',
                'raw_body'  => '',
            ];
        }

        if (! $this->hasCredentials()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.ismalicious.apiKey+apiSecret (lub apiCredential) is not set',
                'raw_body'  => '',
            ];
        }

        $params = [
            'query'      => $query,
            'enrichment' => $this->enrichment,
        ];
        $url = $this->baseUrl . '/check?' . http_build_query($params);

        $headers = [
            'X-API-KEY'  => $this->naglowekApiKey(),
            'Accept'     => 'application/json',
            'User-Agent' => 'MCS-isMalicious/1.0',
        ];

        return $this->executeGet($url, $headers);
    }

    public function checkIp(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'invalid ip',
                'raw_body'  => '',
            ];
        }

        return $this->check($ip);
    }

    private function naglowekApiKey(): string
    {
        if ($this->credentialB64 !== '') {
            return $this->credentialB64;
        }

        return base64_encode($this->apiKey . ':' . $this->apiSecret);
    }

    private function normalizujEnrichment(string $poziom): string
    {
        $poziom = strtolower(trim($poziom));
        if (! in_array($poziom, ['basic', 'standard', 'full'], true)) {
            return 'standard';
        }

        return $poziom;
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    private function executeGet(string $url, array $headers): array
    {
        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, $headers);
        }

        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'curl_init failed',
                'raw_body'  => '',
            ];
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headerLines,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return [
                'ok'        => false,
                'http_code' => $code,
                'data'      => null,
                'error'     => $err !== '' ? $err : 'curl_exec failed',
                'raw_body'  => '',
            ];
        }

        $raw  = (string) $body;
        $json = json_decode($raw, true);
        $ok   = $code >= 200 && $code < 300 && is_array($json);

        $error = '';
        if (! $ok) {
            if (is_array($json)) {
                $error = (string) ($json['message'] ?? $json['error'] ?? '');
            }
            if ($error === '' && $err !== '') {
                $error = $err;
            }
            if ($error === '') {
                $error = 'HTTP ' . $code;
            }
        }

        return [
            'ok'        => $ok,
            'http_code' => $code,
            'data'      => $ok ? $json : (is_array($json) ? $json : null),
            'error'     => $error,
            'raw_body'  => $raw,
        ];
    }
}
