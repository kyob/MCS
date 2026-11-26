<?php

namespace App\Libraries\Enrichment;

use App\Libraries\Siec\BezpiecznyUrl;

/**
 * Klient HTTP do API mapowania LMS (MCS→LMS).
 * Auth: Bearer mapToken (ten sam w [mcs] map_token w lms.ini).
 *
 * SSRF: baseUrl/apiPath walidowane przy starcie (fromEnv, tylko https + publiczny host),
 * a przed każdym realnym żądaniem curl — pełna walidacja z resolve DNS (BezpiecznyUrl::waliduj).
 * Redirecty nie są śledzone (CURLOPT_FOLLOWLOCATION=false), więc cel egressu jest zawsze walidowany.
 */
class LmsMapowanieClient
{
    /** @var callable|null fn(string $method, string $url, array $headers): array{ok:bool,http_code:int,data:?array,error:string,raw_body:string} */
    private $httpFn;

    public function __construct(
        private readonly string $baseUrl = '',
        private readonly string $mapToken = '',
        private readonly string $apiPath = 'plugins/LMSMcsMapowaniePlugin/api.php',
        private readonly int $timeout = 15,
        private readonly bool $tlsVerifyPeer = true,
        ?callable $httpFn = null,
    ) {
        $this->httpFn = $httpFn;
    }

    public static function fromEnv(): self
    {
        $base = rtrim(trim((string) env('mcs.lms.baseUrl', '')), '/');
        $tok  = trim((string) env('mcs.lms.mapToken', ''));
        $path = trim((string) env('mcs.lms.apiPath', 'plugins/LMSMcsMapowaniePlugin/api.php'));
        $ttl  = \App\Libraries\OpcjeMcs::int('mcs.lms.httpTimeout');
        $tls  = filter_var(env('mcs.lms.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN);

        // SSRF: pełny URL z apiPath też musi być https na publicznym hoście.
        if (preg_match('#^https?://#i', $path) === 1) {
            $blad = BezpiecznyUrl::walidujSchemat($path);
            if ($blad !== null) {
                log_message('warning', 'MCS egress odrzucony dla mcs.lms.apiPath (' . $blad . ') host=' . BezpiecznyUrl::host($path));
                $base = '';
            }
        }
        $base = BezpiecznyUrl::baseUrlZEnv($base, 'mcs.lms.baseUrl');

        return new self(
            $base,
            $tok,
            $path !== '' ? ltrim($path, '/') : 'plugins/LMSMcsMapowaniePlugin/api.php',
            max(3, $ttl),
            $tls,
        );
    }

    public function hasConfig(): bool
    {
        return $this->baseUrl !== '' && $this->mapToken !== '';
    }

    public function hasMapToken(): bool
    {
        return $this->mapToken !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getApiPath(): string
    {
        return $this->apiPath;
    }

    /**
     * URL endpointu bez query (host instalacji LMS).
     */
    public function opisUrl(): string
    {
        if ($this->baseUrl === '') {
            return '';
        }

        $path = $this->apiPath;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }

        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * @return array{ok:bool,http_code:int,data:?array,error:string,raw_body:string}
     */
    public function lookup(string $ip): array
    {
        if (! $this->hasConfig()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.lms.baseUrl / mcs.lms.mapToken nie ustawione',
                'raw_body'  => '',
            ];
        }

        $url = $this->endpointUrl(['action' => 'lookup', 'ip' => $ip]);

        return $this->request('GET', $url);
    }

    /**
     * Pełna synchronizacja mapowań (lista items[]).
     * API LMS paginuje (limit/offset); klient zbiera wszystkie strony.
     *
     * @return array{ok:bool,http_code:int,data:?array,error:string,raw_body:string}
     */
    public function sync(): array
    {
        if (! $this->hasConfig()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.lms.baseUrl / mcs.lms.mapToken nie ustawione',
                'raw_body'  => '',
            ];
        }

        $limit   = max(1, \App\Libraries\OpcjeMcs::int('mcs.lms.syncPageSize'));
        $offset  = 0;
        $all     = [];
        $last    = null;
        $maxPage = max(1, \App\Libraries\OpcjeMcs::int('mcs.lms.syncMaxPages'));
        $obciety = false;

        for ($page = 0; $page < $maxPage; $page++) {
            $url  = $this->endpointUrl([
                'action' => 'sync',
                'limit'  => $limit,
                'offset' => $offset,
            ]);
            $resp = $this->request('GET', $url);
            if (! $resp['ok'] || ! is_array($resp['data'])) {
                return $resp;
            }

            $last  = $resp;
            $items = $resp['data']['items'] ?? null;
            if (! is_array($items)) {
                return [
                    'ok'        => false,
                    'http_code' => $resp['http_code'],
                    'data'      => $resp['data'],
                    'error'     => 'Odpowiedź sync bez tablicy items[].',
                    'raw_body'  => $resp['raw_body'],
                ];
            }

            foreach ($items as $row) {
                if (is_array($row)) {
                    $all[] = $row;
                }
            }

            $hasMore = ! empty($resp['data']['has_more']);
            if (! $hasMore || $items === []) {
                break;
            }
            $offset += $limit;
            $obciety = ($page === $maxPage - 1);
        }

        // Obcięty sync nie może wyglądać na pełny: zapis niepełnej listy zostawiłby stare mapowania.
        if ($obciety) {
            return [
                'ok'        => false,
                'http_code' => (int) ($last['http_code'] ?? 200),
                'data'      => null,
                'error'     => 'Sync LMS obcięty: osiągnięto limit ' . $maxPage . ' stron (mcs.lms.syncMaxPages), dane niepełne.',
                'raw_body'  => '',
            ];
        }

        $data = is_array($last['data'] ?? null) ? $last['data'] : [];
        $data['items']    = $all;
        $data['count']    = count($all);
        $data['has_more'] = false;

        return [
            'ok'        => true,
            'http_code' => (int) ($last['http_code'] ?? 200),
            'data'      => $data,
            'error'     => '',
            'raw_body'  => (string) ($last['raw_body'] ?? ''),
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,data:?array,error:string,raw_body:string}
     */
    public function health(): array
    {
        if (! $this->hasConfig()) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'mcs.lms.baseUrl / mcs.lms.mapToken nie ustawione',
                'raw_body'  => '',
            ];
        }

        $url = $this->endpointUrl(['action' => 'health']);

        return $this->request('GET', $url);
    }

    /**
     * @param array<string, scalar> $query
     */
    private function endpointUrl(array $query): string
    {
        $path = $this->apiPath;
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            $base = $path;
        } else {
            $base = $this->baseUrl . '/' . ltrim($path, '/');
        }

        return $base . (str_contains($base, '?') ? '&' : '?') . http_build_query($query);
    }

    /**
     * @return array{ok:bool,http_code:int,data:?array,error:string,raw_body:string}
     */
    private function request(string $method, string $url): array
    {
        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->mapToken,
        ];

        if ($this->httpFn !== null) {
            return ($this->httpFn)($method, $url, $headers);
        }

        // SSRF: przed realnym wyjściem curl — https + publiczne IP po resolpcie DNS.
        // (z wstrzykniętym $httpFn, np. w testach, walidacja leży po stronie testu)
        $blad = BezpiecznyUrl::waliduj($url);
        if ($blad !== null) {
            log_message('warning', 'MCS LMS egress odrzucony (' . $blad . ') host=' . BezpiecznyUrl::host($url));

            return [
                'ok'        => false,
                'http_code' => 0,
                'data'      => null,
                'error'     => 'Odrzucono adres egress (https + publiczny IP): ' . $blad,
                'raw_body'  => '',
            ];
        }

        // Bez śledzenia redirectów: każdy kolejny cel musiałby przejść walidację egress.
        $res  = \App\Libraries\Siec\KlientHttp::wykonaj($url, [
            'metoda'     => $method,
            'timeout'    => $this->timeout,
            'polaczenie' => 10,
            'naglowki'   => $headers,
            'tls'        => $this->tlsVerifyPeer,
        ]);
        $body = $res['ok'] ? $res['body'] : false;
        $code = $res['http_code'];
        $err  = $res['ok'] ? '' : ($res['error'] === 'curl_exec failed' ? '' : $res['error']);

        if ($body === false) {
            return [
                'ok'        => false,
                'http_code' => $code,
                'data'      => null,
                'error'     => $err !== '' ? $err : 'HTTP request failed',
                'raw_body'  => '',
            ];
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return [
                'ok'        => false,
                'http_code' => $code,
                'data'      => null,
                'error'     => 'Nieprawidłowa odpowiedź JSON z LMS (HTTP ' . $code . ')',
                'raw_body'  => (string) $body,
            ];
        }

        if ($code < 200 || $code >= 300) {
            $msg = (string) ($decoded['blad'] ?? $decoded['error'] ?? ('HTTP ' . $code));

            return [
                'ok'        => false,
                'http_code' => $code,
                'data'      => $decoded,
                'error'     => $msg,
                'raw_body'  => (string) $body,
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $code,
            'data'      => $decoded,
            'error'     => '',
            'raw_body'  => (string) $body,
        ];
    }
}
