<?php

namespace McsPluginy\abusix;

/**
 * Klient Abusix API (Guardian Ops) — Bearer token.
 * Base URL: https://api.abusix.com, endpointy pod /beta/guardian-ops/ (docs.abusix.com).
 * Bez ponowień: błąd (w tym 429) kończy wywołanie; Retry-After zwracane wywołującemu.
 */
class ApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string,retry_after?:int}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string,retry_after?:int}|null $httpRequest
     */
    public function __construct(string $baseUrl, string $token, int $timeout = 30, ?callable $httpRequest = null)
    {
        $this->baseUrl     = rtrim(trim($baseUrl), '/');
        $this->token       = trim($token);
        $this->timeout     = max(1, $timeout);
        $this->httpRequest = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $timeout = (int) env('mcs.abusix.httpTimeout', 30);

        return new self(
            (string) env('mcs.abusix.baseUrl', 'https://api.abusix.com'),
            (string) env('mcs.abusix.apiKey', ''),
            $timeout > 0 ? $timeout : 30,
            $httpRequest,
        );
    }

    public function hasAuth(): bool
    {
        return $this->baseUrl !== '' && $this->token !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /** GET /beta/guardian-ops/cases */
    public function listCases(int $limit = 100, ?string $cursor = null): array
    {
        return $this->getJson('/beta/guardian-ops/cases', $this->strona($limit, $cursor));
    }

    /** GET /beta/guardian-ops/customers */
    public function listCustomers(int $limit = 100, ?string $cursor = null): array
    {
        return $this->getJson('/beta/guardian-ops/customers', $this->strona($limit, $cursor));
    }

    /** GET /beta/guardian-ops/events */
    public function listEvents(int $limit = 100, ?string $cursor = null): array
    {
        return $this->getJson('/beta/guardian-ops/events', $this->strona($limit, $cursor));
    }

    /**
     * @return array<string,string>
     */
    private function strona(int $limit, ?string $cursor): array
    {
        $q = ['limit' => (string) max(1, min(500, $limit))];
        if ($cursor !== null && $cursor !== '') {
            $q['cursor'] = $cursor;
        }

        return $q;
    }

    /**
     * @param array<string,string> $query
     *
     * @return array{ok:bool,http_code:int,data:array<string,mixed>,error:string,rate_limited:bool,retry_after:int}
     */
    public function getJson(string $path, array $query = []): array
    {
        $baza = ['ok' => false, 'http_code' => 0, 'data' => [], 'error' => '', 'rate_limited' => false, 'retry_after' => 0];
        if (! $this->hasAuth()) {
            return ['error' => 'Abusix: ustaw mcs.abusix.apiKey w .env'] + $baza;
        }

        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $res = $this->httpRequest !== null
            ? ($this->httpRequest)($url, ['token' => $this->token])
            : $this->curlGet($url);

        $baza['http_code'] = $res['http_code'];
        if ($res['http_code'] === 429) {
            $ra = (int) ($res['retry_after'] ?? 0);

            return ['rate_limited' => true, 'retry_after' => $ra > 0 ? $ra : 3600, 'error' => 'HTTP 429 (limit zapytań)'] + $baza;
        }
        if (! $res['ok']) {
            return ['error' => $res['error']] + $baza;
        }
        $data = json_decode($res['body'], true);
        if (! is_array($data)) {
            return ['error' => 'Abusix: odpowiedź nie jest JSON'] + $baza;
        }

        return ['ok' => true, 'data' => $data] + $baza;
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string,retry_after:int}
     */
    private function curlGet(string $url): array
    {
        $retryAfter = 0;
        $ch         = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'MCS-Abusix/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $this->token],
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
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'cURL error: ' . $err, 'retry_after' => 0];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'http_code' => $code, 'body' => (string) $out, 'error' => 'HTTP ' . $code . ': ' . substr((string) $out, 0, 200), 'retry_after' => $retryAfter];
        }

        return ['ok' => true, 'http_code' => $code, 'body' => (string) $out, 'error' => '', 'retry_after' => 0];
    }
}
