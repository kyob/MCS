<?php

namespace App\Libraries\Enrichment;

/**
 * Klient AbuseIPDB API v2 — tylko GET /check (enrichment, nie stream).
 *
 * Auth: nagłówek `Key` (nigdy query string w logach produkcyjnych).
 */
class AbuseipdbClient
{
    private string $baseUrl;
    private string $apiKey;
    private int $maxAgeInDays;
    private bool $verbose;

    /** @var callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null $httpRequest
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.abuseipdb.com/api/v2',
        int $maxAgeInDays = 90,
        bool $verbose = true,
        ?callable $httpRequest = null,
    ) {
        $this->apiKey       = trim($apiKey);
        $this->baseUrl      = rtrim($baseUrl, '/');
        $this->maxAgeInDays = max(1, min(365, $maxAgeInDays));
        $this->verbose      = $verbose;
        $this->httpRequest  = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $key     = trim((string) env('mcs.abuseipdb.apiKey', ''));
        $base    = trim((string) env('mcs.abuseipdb.baseUrl', 'https://api.abuseipdb.com/api/v2'));
        $maxAge  = \App\Libraries\OpcjeMcs::int('mcs.abuseipdb.maxAgeInDays');
        $verbose = filter_var(env('mcs.abuseipdb.verbose', true), FILTER_VALIDATE_BOOLEAN);

        return new self(
            $key,
            $base !== '' ? $base : 'https://api.abuseipdb.com/api/v2',
            $maxAge > 0 ? $maxAge : 90,
            $verbose,
            $httpRequest,
        );
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function maxAgeInDays(): int
    {
        return $this->maxAgeInDays;
    }

    /**
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function checkIp(string $ip): array
    {
        if (! $this->hasApiKey()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.abuseipdb.apiKey is not set',
                'raw_body'  => '',
            ];
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'invalid ip',
                'raw_body'  => '',
            ];
        }

        $query = [
            'ipAddress'    => $ip,
            'maxAgeInDays' => (string) $this->maxAgeInDays,
        ];
        if ($this->verbose) {
            $query['verbose'] = '';
        }

        $url     = $this->baseUrl . '/check?' . http_build_query($query);
        $headers = [
            'Key'        => $this->apiKey,
            'Accept'     => 'application/json',
            'User-Agent' => 'MCS-AbuseIPDB/1.0',
        ];

        return $this->executeGet($url, $headers);
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
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => max(1, \App\Libraries\OpcjeMcs::int('mcs.abuseipdb.httpTimeout')),
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
            if (is_array($json) && isset($json['errors'][0]['detail'])) {
                $error = (string) $json['errors'][0]['detail'];
            } elseif ($err !== '') {
                $error = $err;
            } else {
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
