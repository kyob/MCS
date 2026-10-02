<?php

namespace McsPluginy\cloudflare_botnet;

/**
 * Klient REST Cloudflare DDoS Botnet Threat Feed (Bearer token).
 *
 * @see https://developers.cloudflare.com/ddos-protection/botnet-threat-feed/
 */
class ApiClient
{
    private string $baseUrl;
    private string $apiToken;
    private string $accountId;
    private bool $tlsVerifyPeer;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
     */
    public function __construct(
        string $apiToken,
        string $accountId,
        string $baseUrl = 'https://api.cloudflare.com/client/v4',
        bool $tlsVerifyPeer = true,
        ?callable $httpRequest = null,
    ) {
        $this->apiToken      = $apiToken;
        $this->accountId     = $accountId;
        $this->baseUrl       = rtrim($baseUrl, '/');
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->httpRequest   = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $base = trim((string) env('mcs.cloudflare_botnet.baseUrl', 'https://api.cloudflare.com/client/v4'));
        if ($base === '') {
            $base = 'https://api.cloudflare.com/client/v4';
        }

        return new self(
            trim((string) env('mcs.cloudflare_botnet.apiToken', '')),
            trim((string) env('mcs.cloudflare_botnet.accountId', '')),
            $base,
            filter_var(env('mcs.cloudflare_botnet.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
            $httpRequest,
        );
    }

    public function hasToken(): bool
    {
        return $this->apiToken !== '';
    }

    public function hasAuth(): bool
    {
        return $this->hasToken() && $this->accountId !== '';
    }

    public function getAccountId(): string
    {
        return $this->accountId;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * Uzupełnia accountId z GET /accounts, gdy brak w env (pierwsze konto z listy).
     *
     * @return array{ok:bool,account_id:string,error:string,http_code:int}
     */
    public function resolveAccountId(): array
    {
        if ($this->accountId !== '') {
            return [
                'ok'         => true,
                'account_id' => $this->accountId,
                'error'      => '',
                'http_code'  => 0,
            ];
        }
        if (! $this->hasToken()) {
            return [
                'ok'         => false,
                'account_id' => '',
                'error'      => 'mcs.cloudflare_botnet.apiToken is not set',
                'http_code'  => 0,
            ];
        }

        $res = $this->getJson('/accounts?per_page=50');
        if (! $res['ok']) {
            return [
                'ok'         => false,
                'account_id' => '',
                'error'      => $res['error'],
                'http_code'  => $res['http_code'],
            ];
        }

        $list = $res['result'];
        if (! is_array($list) || $list === []) {
            return [
                'ok'         => false,
                'account_id' => '',
                'error'      => 'Cloudflare: brak kont w GET /accounts',
                'http_code'  => $res['http_code'],
            ];
        }

        $first = $list[0] ?? null;
        $id    = is_array($first) ? trim((string) ($first['id'] ?? '')) : '';
        if ($id === '') {
            return [
                'ok'         => false,
                'account_id' => '',
                'error'      => 'Cloudflare: pierwsze konto bez id',
                'http_code'  => $res['http_code'],
            ];
        }

        $this->accountId = $id;

        return [
            'ok'         => true,
            'account_id' => $id,
            'error'      => '',
            'http_code'  => $res['http_code'],
        ];
    }

    /**
     * Lista ASN zarejestrowanych pod Botnet Feed (PeeringDB).
     *
     * @return array{ok:bool,http_code:int,asns:list<int>,error:string,raw_body:string}
     */
    public function listRegisteredAsns(): array
    {
        $acc = $this->resolveAccountId();
        if (! $acc['ok']) {
            return [
                'ok'        => false,
                'http_code' => $acc['http_code'],
                'asns'      => [],
                'error'     => $acc['error'],
                'raw_body'  => '',
            ];
        }

        $res = $this->getJson('/accounts/' . rawurlencode($this->accountId) . '/botnet_feed/configs/asn');
        if (! $res['ok']) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'asns'      => [],
                'error'     => $res['error'],
                'raw_body'  => $res['raw_body'],
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $res['http_code'],
            'asns'      => $this->normalizeAsnList($res['result']),
            'error'     => '',
            'raw_body'  => $res['raw_body'],
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,entries:list<array<string,mixed>>,error:string,raw_body:string}
     */
    public function fetchFullReport(int $asn): array
    {
        return $this->fetchReport($asn, 'full_report', null);
    }

    /**
     * @return array{ok:bool,http_code:int,entries:list<array<string,mixed>>,error:string,raw_body:string}
     */
    public function fetchDayReport(int $asn, ?string $dateYmd = null): array
    {
        return $this->fetchReport($asn, 'day_report', $dateYmd);
    }

    /**
     * @return array{ok:bool,http_code:int,entries:list<array<string,mixed>>,error:string,raw_body:string}
     */
    private function fetchReport(int $asn, string $kind, ?string $dateYmd): array
    {
        $acc = $this->resolveAccountId();
        if (! $acc['ok']) {
            return [
                'ok'        => false,
                'http_code' => $acc['http_code'],
                'entries'   => [],
                'error'     => $acc['error'],
                'raw_body'  => '',
            ];
        }
        if ($asn < 1) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'entries'   => [],
                'error'     => 'Cloudflare: ASN musi byc > 0',
                'raw_body'  => '',
            ];
        }

        $path = '/accounts/' . rawurlencode($this->accountId)
            . '/botnet_feed/asn/' . $asn . '/' . $kind;
        if ($kind === 'day_report' && $dateYmd !== null && $dateYmd !== '') {
            $path .= '?date=' . rawurlencode($dateYmd);
        }

        $res = $this->getJson($path);
        if (! $res['ok']) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'entries'   => [],
                'error'     => $res['error'],
                'raw_body'  => $res['raw_body'],
            ];
        }

        $entries = $this->normalizeEntries($res['result']);

        return [
            'ok'        => true,
            'http_code' => $res['http_code'],
            'entries'   => $entries,
            'error'     => '',
            'raw_body'  => $res['raw_body'],
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,result:mixed,error:string,raw_body:string}
     */
    private function getJson(string $path): array
    {
        if (! $this->hasToken()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'result'    => null,
                'error'     => 'mcs.cloudflare_botnet.apiToken is not set',
                'raw_body'  => '',
            ];
        }

        $url = $this->baseUrl . $path;
        $res = $this->get($url);
        if (! $res['ok']) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'result'    => null,
                'error'     => $res['error'],
                'raw_body'  => $res['body'],
            ];
        }

        $decoded = json_decode($res['body'], true);
        if (! is_array($decoded)) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'result'    => null,
                'error'     => 'Cloudflare: odpowiedz nie jest JSON',
                'raw_body'  => $res['body'],
            ];
        }

        if (array_key_exists('success', $decoded) && $decoded['success'] !== true) {
            $errMsg = $this->formatCfErrors($decoded['errors'] ?? null);

            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'result'    => $decoded['result'] ?? null,
                'error'     => $errMsg !== '' ? $errMsg : 'Cloudflare: success=false',
                'raw_body'  => $res['body'],
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $res['http_code'],
            'result'    => $decoded['result'] ?? null,
            'error'     => '',
            'raw_body'  => $res['body'],
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    public function get(string $url): array
    {
        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, [
                'token'       => $this->apiToken,
                'verify_peer' => $this->tlsVerifyPeer,
            ]);
        }

        return $this->curlGet($url);
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
            CURLOPT_USERAGENT      => 'MCS-CloudflareBotnet/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => max(1, \App\Libraries\OpcjeMcs::int('mcs.cloudflare_botnet.httpTimeout')),
            CURLOPT_CONNECTTIMEOUT => max(1, \App\Libraries\OpcjeMcs::int('mcs.cloudflare_botnet.httpConnectTimeout')),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiToken,
                'Accept: application/json',
            ],
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
     * @param mixed $result
     *
     * @return list<int>
     */
    public function normalizeAsnList(mixed $result): array
    {
        if ($result === null || $result === 0 || $result === '0') {
            return [];
        }
        if (is_int($result) || (is_string($result) && ctype_digit($result))) {
            $n = (int) $result;

            return $n > 0 ? [$n] : [];
        }
        if (! is_array($result)) {
            return [];
        }

        // Dokumentacja bywa { "asn": 13335 }; live API bywa [198050, ...]
        if (isset($result['asn']) && ! array_is_list($result)) {
            $n = (int) $result['asn'];

            return $n > 0 ? [$n] : [];
        }

        $out = [];
        foreach ($result as $item) {
            if (is_int($item) || (is_string($item) && ctype_digit($item))) {
                $n = (int) $item;
                if ($n > 0) {
                    $out[] = $n;
                }
                continue;
            }
            if (is_array($item) && isset($item['asn'])) {
                $n = (int) $item['asn'];
                if ($n > 0) {
                    $out[] = $n;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Puste / null / 0 = brak ofensyw (sukces wg docs CF).
     *
     * @param mixed $result
     *
     * @return list<array<string, mixed>>
     */
    public function normalizeEntries(mixed $result): array
    {
        if ($result === null || $result === 0 || $result === '0' || $result === []) {
            return [];
        }
        if (! is_array($result)) {
            return [];
        }

        $out = [];
        foreach ($result as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /**
     * @param mixed $errors
     */
    private function formatCfErrors(mixed $errors): string
    {
        if (! is_array($errors) || $errors === []) {
            return '';
        }
        $parts = [];
        foreach ($errors as $e) {
            if (! is_array($e)) {
                continue;
            }
            $code = $e['code'] ?? '';
            $msg  = isset($e['message']) ? (string) $e['message'] : '';
            $parts[] = trim($code . ($msg !== '' ? (': ' . $msg) : ''));
        }

        return implode('; ', array_filter($parts));
    }
}
