<?php

/**
 * Klient agregatu badge MCS — bez sekretów w kodzie (token z configu LMS).
 *
 * Single: GET /api/badge?ip=
 * Batch:  GET /api/badge?ips=a,b  (albo POST JSON — badgeDlaIpLista).
 */
class McsBadgeClient
{
    private string $baseUrl;
    private string $token;
    private int $timeoutSec;

    public function __construct(string $baseUrl, string $token, int $timeoutSec = 5)
    {
        $this->baseUrl     = rtrim($baseUrl, '/') . '/';
        $this->token       = trim($token);
        $this->timeoutSec  = max(1, $timeoutSec);
    }

    /**
     * @return array{ok:bool,http_code:int,data:?array,error:string}
     */
    public function badgeDlaIp(string $ip): array
    {
        if ($this->token === '' || $this->baseUrl === '/') {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'mcs config incomplete'];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'invalid ip'];
        }

        return $this->request('GET', 'api/badge?' . http_build_query(['ip' => $ip]));
    }

    /**
     * Batch: GET ?ips=… (do 100 IP). Zwraca data.wyniki[] jak single-IP wiersze.
     *
     * @param list<string> $ips
     * @return array{ok:bool,http_code:int,data:?array,error:string}
     */
    public function badgeDlaIpLista(array $ips): array
    {
        if ($this->token === '' || $this->baseUrl === '/') {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'mcs config incomplete'];
        }

        $czyste = [];
        foreach ($ips as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'invalid ip'];
            }
            if (! in_array($ip, $czyste, true)) {
                $czyste[] = $ip;
            }
        }
        if ($czyste === []) {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'empty ips'];
        }
        if (count($czyste) > 100) {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'max 100 ips'];
        }

        if (count($czyste) === 1) {
            return $this->badgeDlaIp($czyste[0]);
        }

        // Długie listy → POST JSON (unikamy limitu długości URL).
        if (count($czyste) > 20) {
            return $this->request('POST', 'api/badge', ['ips' => $czyste]);
        }

        return $this->request('GET', 'api/badge?' . http_build_query(['ips' => implode(',', $czyste)]));
    }

    /**
     * @param array<string, mixed>|null $jsonBody
     * @return array{ok:bool,http_code:int,data:?array,error:string}
     */
    private function request(string $method, string $path, ?array $jsonBody = null): array
    {
        $url = $this->baseUrl . ltrim($path, '/');
        $ch  = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'http_code' => 0, 'data' => null, 'error' => 'curl_init failed'];
        }

        $headers = [
            'Authorization: Bearer ' . $this->token,
            'Accept: application/json',
        ];
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($jsonBody !== null) {
            $headers[]                = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http_code' => $code, 'data' => null, 'error' => $err ?: 'request failed'];
        }

        $json = json_decode((string) $body, true);
        $ok   = $code === 200 && is_array($json);

        return [
            'ok'        => $ok,
            'http_code' => $code,
            'data'      => $ok ? $json : (is_array($json) ? $json : null),
            'error'     => $ok ? '' : ($err !== '' ? $err : 'HTTP ' . $code),
        ];
    }
}
