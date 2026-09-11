<?php

namespace McsPluginy\wanguard;

/**
 * Klient REST Wanguard API v1 — Basic Auth.
 * Lista Finished (paginacja) + concurrent details (jak LMS bin).
 */
class ApiClient
{
    private string $apiUrl;
    private string $apiUser;
    private string $apiPassword;
    private int $listLimit;
    private int $concurrency;
    private bool $tlsVerifyPeer;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
     */
    public function __construct(
        string $apiUrl,
        string $apiUser,
        string $apiPassword,
        int $listLimit = 500,
        int $concurrency = 15,
        bool $tlsVerifyPeer = true,
        ?callable $httpRequest = null,
    ) {
        $this->apiUrl        = self::normalizeApiBaseUrl($apiUrl);
        $this->apiUser       = $apiUser;
        $this->apiPassword   = $apiPassword;
        $this->listLimit     = max(1, min(5000, $listLimit));
        $this->concurrency   = max(1, min(50, $concurrency));
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->httpRequest   = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $limit = (int) env('mcs.wanguard.apiListLimit', 500);
        $conc  = (int) env('mcs.wanguard.concurrency', 15);

        return new self(
            trim((string) env('mcs.wanguard.apiUrl', '')),
            trim((string) env('mcs.wanguard.apiUser', '')),
            (string) env('mcs.wanguard.apiPassword', ''),
            $limit > 0 ? $limit : 500,
            $conc > 0 ? $conc : 15,
            filter_var(env('mcs.wanguard.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
            $httpRequest,
        );
    }

    public function hasAuth(): bool
    {
        return $this->apiUrl !== '' && $this->apiUser !== '' && $this->apiPassword !== '';
    }

    public function getApiUrl(): string
    {
        return $this->apiUrl;
    }

    public function getApiUser(): string
    {
        return $this->apiUser;
    }

    /**
     * Normalize to base ending with / (strip accidental /anomalies?...).
     */
    public static function normalizeApiBaseUrl(string $apiUrl): string
    {
        $apiUrl = rtrim(trim($apiUrl), '/');
        if ($apiUrl === '') {
            return '';
        }
        $pos = stripos($apiUrl, '/anomalies');
        if ($pos !== false) {
            $apiUrl = substr($apiUrl, 0, $pos);
        }

        return rtrim($apiUrl, '/') . '/';
    }

    /**
     * @return array{ok:bool,http_code:int,items:list<array<string,mixed>>,error:string,raw_bodies:list<string>}
     */
    public function listFinishedAnomalies(): array
    {
        if (! $this->hasAuth()) {
            return [
                'ok'         => false,
                'http_code'  => 0,
                'items'      => [],
                'error'      => 'Wanguard: ustaw mcs.wanguard.apiUrl / apiUser / apiPassword',
                'raw_bodies' => [],
            ];
        }

        $items     = [];
        $rawBodies = [];
        $offset    = 0;
        $maxPages  = 10000;
        $lastCode  = 0;

        for ($page = 0; $page < $maxPages; $page++) {
            $query = http_build_query(
                [
                    'status' => 'Finished',
                    'sort'   => 'anomaly_id ASC',
                    'limit'  => (string) $this->listLimit,
                    'offset' => (string) $offset,
                ],
                '',
                '&',
                PHP_QUERY_RFC3986,
            );
            $url = $this->apiUrl . 'anomalies?' . $query;
            $res = $this->get($url);
            $lastCode = $res['http_code'];
            if (! $res['ok']) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'items'      => $items,
                    'error'      => $res['error'],
                    'raw_bodies' => $rawBodies,
                ];
            }
            $rawBodies[] = $res['body'];
            $decoded     = json_decode($res['body'], true);
            if (! is_array($decoded)) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'items'      => $items,
                    'error'      => 'Wanguard: lista anomalii nie jest JSON',
                    'raw_bodies' => $rawBodies,
                ];
            }
            $chunkLen = count($decoded);
            if ($chunkLen === 0) {
                break;
            }
            foreach ($decoded as $row) {
                if (is_array($row)) {
                    $items[] = $row;
                }
            }
            $offset += $chunkLen;
            if ($chunkLen < $this->listLimit) {
                break;
            }
        }

        return [
            'ok'         => true,
            'http_code'  => $lastCode,
            'items'      => $items,
            'error'      => '',
            'raw_bodies' => $rawBodies,
        ];
    }

    /**
     * @param list<int> $anomalyIds
     *
     * @return array{ok:bool,details:array<int,array<string,mixed>|null>,error:string}
     */
    public function fetchDetailsConcurrent(array $anomalyIds): array
    {
        if (! $this->hasAuth()) {
            return [
                'ok'      => false,
                'details' => [],
                'error'   => 'Wanguard: brak auth',
            ];
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $anomalyIds), static fn ($id) => $id > 0)));
        if ($ids === []) {
            return ['ok' => true, 'details' => [], 'error' => ''];
        }

        // Test / injection hook — sequential GET via httpRequest
        if ($this->httpRequest !== null) {
            $details = [];
            foreach ($ids as $id) {
                $url = $this->apiUrl . 'anomalies/' . $id;
                $res = ($this->httpRequest)($url, $this->authMeta());
                if (! $res['ok']) {
                    $details[$id] = null;
                    continue;
                }
                $decoded = json_decode($res['body'], true);
                $details[$id] = is_array($decoded) ? $decoded : null;
            }

            return ['ok' => true, 'details' => $details, 'error' => ''];
        }

        return [
            'ok'      => true,
            'details' => $this->curlMultiDetails($ids),
            'error'   => '',
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    public function get(string $url): array
    {
        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, $this->authMeta());
        }

        return $this->curlGet($url);
    }

    /**
     * @return array<string, mixed>
     */
    private function authMeta(): array
    {
        return [
            'user'        => $this->apiUser,
            'password'    => $this->apiPassword,
            'verify_peer' => $this->tlsVerifyPeer,
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    private function curlGet(string $url): array
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerifyPeer ? 2 : 0,
            CURLOPT_USERAGENT      => 'MCS-Wanguard/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERPWD        => $this->apiUser . ':' . $this->apiPassword,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $output   = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errno    = curl_errno($ch);
        $errstr   = curl_error($ch);
        curl_close($ch);

        if ($output === false) {
            return [
                'ok'        => false,
                'http_code' => $httpCode,
                'body'      => '',
                'error'     => 'cURL error: ' . $errstr . " (errno {$errno})",
            ];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = substr((string) $output, 0, 400);

            return [
                'ok'        => false,
                'http_code' => $httpCode,
                'body'      => (string) $output,
                'error'     => 'HTTP ' . $httpCode . ($snippet !== '' ? (': ' . $snippet) : ''),
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $httpCode,
            'body'      => (string) $output,
            'error'     => '',
        ];
    }

    /**
     * @param list<int> $anomalyIds
     *
     * @return array<int, array<string,mixed>|null>
     */
    private function curlMultiDetails(array $anomalyIds): array
    {
        $results = [];
        $chunks  = array_chunk($anomalyIds, $this->concurrency);

        foreach ($chunks as $chunk) {
            $mh      = curl_multi_init();
            $handles = [];

            foreach ($chunk as $anomalyId) {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => $this->tlsVerifyPeer,
                    CURLOPT_SSL_VERIFYHOST => $this->tlsVerifyPeer ? 2 : 0,
                    CURLOPT_USERAGENT      => 'MCS-Wanguard/1.0',
                    CURLOPT_URL            => $this->apiUrl . 'anomalies/' . $anomalyId,
                    CURLOPT_TIMEOUT        => 60,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_USERPWD        => $this->apiUser . ':' . $this->apiPassword,
                    CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
                    CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                ]);
                curl_multi_add_handle($mh, $ch);
                $handles[$anomalyId] = $ch;
            }

            $running = null;
            do {
                $status = curl_multi_exec($mh, $running);
            } while ($status === CURLM_CALL_MULTI_PERFORM);

            while ($running && $status === CURLM_OK) {
                if (curl_multi_select($mh) === -1) {
                    usleep(1000);
                }
                do {
                    $status = curl_multi_exec($mh, $running);
                } while ($status === CURLM_CALL_MULTI_PERFORM);
            }

            foreach ($handles as $anomalyId => $ch) {
                $body    = curl_multi_getcontent($ch);
                $decoded = json_decode((string) $body, true);
                $results[$anomalyId] = is_array($decoded) ? $decoded : null;
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
            }
            curl_multi_close($mh);
        }

        return $results;
    }
}
