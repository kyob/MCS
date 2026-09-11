<?php

namespace McsPluginy\mojecert;

use Config\Services;
use RuntimeException;

/**
 * Klient REST moje.cert.pl (Authorization: Token …).
 * Port uproszczony z LMSMojeCertPlugin — GET + throttle 429.
 */
class ApiClient
{
    private string $baseUrl;
    private string $token;

    /** @var callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null */
    private $httpRequest;

    private bool $tlsSkipVerify;
    private int $maxThrottleAttempts;

    /**
     * @param callable(string, array<string,string>): array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}|null $httpRequest
     */
    public function __construct(
        string $baseUrl,
        string $token,
        ?callable $httpRequest = null,
        bool $tlsSkipVerify = false,
        int $maxThrottleAttempts = 12,
    ) {
        $this->baseUrl             = rtrim($baseUrl, '/');
        $this->token               = $token;
        $this->httpRequest         = $httpRequest;
        $this->tlsSkipVerify       = $tlsSkipVerify;
        $this->maxThrottleAttempts = max(1, $maxThrottleAttempts);
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $base  = trim((string) env('mcs.mojecert.baseUrl', 'https://moje.cert.pl'));
        $token = trim((string) env('mcs.mojecert.apiToken', ''));
        $skip  = filter_var(env('mcs.mojecert.tlsSkipVerify', false), FILTER_VALIDATE_BOOLEAN);

        return new self($base !== '' ? $base : 'https://moje.cert.pl', $token, $httpRequest, $skip);
    }

    public function hasToken(): bool
    {
        return $this->token !== '';
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function request(string $method, string $path, array $query = []): array
    {
        if (! $this->hasToken()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.mojecert.apiToken is not set',
                'raw_body'  => '',
            ];
        }

        if ($path === '' || $path[0] !== '/') {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'Invalid API path',
                'raw_body'  => '',
            ];
        }

        $url     = $this->baseUrl . $path . $this->buildQuery($query);
        $headers = [
            'Authorization' => 'Token ' . $this->token,
            'Accept'        => 'application/json',
            'User-Agent'    => 'MCS-MojeCert/1.0',
        ];

        $attempt    = 0;
        $lastResult = null;

        while ($attempt < $this->maxThrottleAttempts) {
            ++$attempt;
            $lastResult = $this->executeGet($url, $headers);
            if ($lastResult['ok']) {
                return $lastResult;
            }
            if (! $this->isThrottle($lastResult)) {
                return $lastResult;
            }
            $wait = max(1, min($this->throttleSeconds($lastResult), 120));
            sleep($wait);
        }

        return $lastResult ?? [
            'ok'        => false,
            'http_code' => 0,
            'data'      => null,
            'error'     => 'throttle retries exhausted',
            'raw_body'  => '',
        ];
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function listOrganizations(array $query = []): array
    {
        return $this->request('GET', '/api/v1/organizations/', $query);
    }

    /**
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function getOrganization(string $slug): array
    {
        $slug = rawurlencode($slug);

        return $this->request('GET', '/api/v1/organizations/' . $slug . '/');
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string}
     */
    public function listOrganizationSubnetScanResults(string $orgSlug, string $subnetPath, array $query = []): array
    {
        $org = rawurlencode($orgSlug);
        // subnetPath może zawierać CIDR ze slash — encode segment
        $sn  = rawurlencode($subnetPath);

        return $this->request(
            'GET',
            '/api/v1/organizations/' . $org . '/subnets/' . $sn . '/results/',
            $query,
        );
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

        try {
            $client   = Services::curlrequest(['http_errors' => false]);
            $options  = [
                'timeout'         => 60,
                'connect_timeout' => 15,
                'headers'         => $headers,
                'verify'          => ! $this->tlsSkipVerify,
            ];
            $response = $client->get($url, $options);
            $kod      = $response->getStatusCode();
            $raw      = (string) $response->getBody();
        } catch (\Throwable $e) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'Network error: ' . $e->getMessage(),
                'raw_body'  => '',
            ];
        }

        $data = null;
        if ($raw !== '') {
            $trim = ltrim($raw);
            if ($trim !== '' && ($trim[0] === '{' || $trim[0] === '[')) {
                $decoded = json_decode($raw, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    return [
                        'ok'        => false,
                        'http_code' => $kod,
                        'data'      => null,
                        'error'     => 'Invalid JSON in response',
                        'raw_body'  => $raw,
                    ];
                }
                $data = $decoded;
            }
        }

        $ok     = $kod >= 200 && $kod < 300;
        $errMsg = '';
        if (! $ok) {
            if (is_array($data) && isset($data['detail'])) {
                if (is_string($data['detail'])) {
                    $errMsg = $data['detail'];
                } else {
                    $errMsg = (string) json_encode($data['detail']);
                }
            } else {
                $errMsg = 'HTTP ' . $kod;
            }
        }

        return [
            'ok'        => $ok,
            'http_code' => $kod,
            'data'      => $data,
            'error'     => $errMsg,
            'raw_body'  => $raw,
        ];
    }

    /**
     * @param array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string} $result
     */
    private function isThrottle(array $result): bool
    {
        if ((int) ($result['http_code'] ?? 0) === 429) {
            return true;
        }
        $err = (string) ($result['error'] ?? '');

        return $err !== '' && stripos($err, 'throttled') !== false;
    }

    /**
     * @param array{ok:bool,http_code:int,data:mixed,error:string,raw_body:string} $result
     */
    private function throttleSeconds(array $result): int
    {
        $err = (string) ($result['error'] ?? '');
        if ($err !== '' && preg_match('/Expected available in (\d+)\s*seconds?/i', $err, $m) === 1) {
            return (int) $m[1];
        }

        return 5;
    }

    /**
     * @param array<string, mixed> $query
     */
    private function buildQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        $parts = [];
        foreach ($query as $k => $v) {
            if (is_array($v)) {
                foreach ($v as $item) {
                    $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $item);
                }
            } elseif ($v !== null) {
                $parts[] = rawurlencode((string) $k) . '=' . rawurlencode((string) $v);
            }
        }

        return $parts === [] ? '' : ('?' . implode('&', $parts));
    }
}
