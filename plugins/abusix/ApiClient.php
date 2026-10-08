<?php

namespace McsPluginy\abusix;

/**
 * Klient Abusix API (Guardian Ops) — Bearer token.
 * Base URL: https://api.abusix.com, endpointy pod /beta/guardian-ops/ (docs.abusix.com).
 */
class ApiClient
{
    private string $baseUrl;
    private string $token;
    private int $timeout;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
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
    public function listCases(?string $state = null, int $limit = 100): array
    {
        $q = ['limit' => (string) $limit];
        if ($state !== null && $state !== '') {
            $q['state'] = $state;
        }

        return $this->getJson('/beta/guardian-ops/cases', $q);
    }

    /** GET /beta/guardian-ops/customers */
    public function listCustomers(int $limit = 100): array
    {
        return $this->getJson('/beta/guardian-ops/customers', ['limit' => (string) $limit]);
    }

    /** GET /beta/guardian-ops/events */
    public function listEvents(int $limit = 100): array
    {
        return $this->getJson('/beta/guardian-ops/events', ['limit' => (string) $limit]);
    }

    /**
     * @param array<string,string> $query
     *
     * @return array{ok:bool,http_code:int,data:array<string,mixed>,error:string}
     */
    public function getJson(string $path, array $query = []): array
    {
        if (! $this->hasAuth()) {
            return ['ok' => false, 'http_code' => 0, 'data' => [], 'error' => 'Abusix: ustaw mcs.abusix.apiKey w .env'];
        }

        $url = $this->baseUrl . $path . ($query !== [] ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');
        $res = $this->httpRequest !== null
            ? ($this->httpRequest)($url, ['token' => $this->token])
            : $this->curlGet($url);

        if (! $res['ok']) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'data' => [], 'error' => $res['error']];
        }
        $data = json_decode($res['body'], true);
        if (! is_array($data)) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'data' => [], 'error' => 'Abusix: odpowiedź nie jest JSON'];
        }

        return ['ok' => true, 'http_code' => $res['http_code'], 'data' => $data, 'error' => ''];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    private function curlGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERAGENT      => 'MCS-Abusix/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => ['Accept: application/json', 'Authorization: Bearer ' . $this->token],
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'cURL error: ' . $err];
        }
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'http_code' => $code, 'body' => (string) $out, 'error' => 'HTTP ' . $code . ': ' . substr((string) $out, 0, 200)];
        }

        return ['ok' => true, 'http_code' => $code, 'body' => (string) $out, 'error' => ''];
    }
}
