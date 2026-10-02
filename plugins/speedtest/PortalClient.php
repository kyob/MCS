<?php

namespace McsPluginy\speedtest;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

/**
 * Klient Ookla Speedtest Connect (account.ookla.com) — Auth0 + CSV export.
 *
 * Odkryte (SPA account.ookla.com, 2026-09):
 * - Auth0 domain: ookla.auth0.com, client_id SPA, audience https://api.st-connect.com,
 *   connection/realm OOKLA-AUTH; ROPG password-realm działa dla kont bez MFA.
 * - Export: GET /api/servers/reports?format=csv&startDate=&endDate=… (Bearer access_token).
 *
 * Fragile: endpointy / Auth0 client to niepubliczne API SPA; ToS Ookla może zabraniać
 * automatyzacji — trzymaj csvDir jako fallback.
 */
class PortalClient
{
    public const AUTH0_TOKEN_URL = 'https://auth.ookla.com/oauth/token';
    public const AUTH0_CLIENT_ID = 'qZ22rLQ3HmY102QN05DIQGqFkz3AHdFr';
    public const AUTH0_AUDIENCE  = 'https://api.st-connect.com';
    public const AUTH0_REALM     = 'OOKLA-AUTH';
    public const API_BASE        = 'https://account.ookla.com';

    private string $email;
    private string $password;
    private string $apiBase;
    private bool $tlsVerifyPeer;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string,headers?:array<string,string>}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string,headers?:array<string,string>}|null $httpRequest
     */
    public function __construct(
        string $email = '',
        string $password = '',
        ?string $apiBase = null,
        ?callable $httpRequest = null,
        bool $tlsVerifyPeer = true,
    ) {
        $this->email         = trim($email);
        $this->password      = $password;
        $this->apiBase       = rtrim($apiBase ?? self::API_BASE, '/');
        $this->httpRequest   = $httpRequest;
        $this->tlsVerifyPeer = $tlsVerifyPeer;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        return new self(
            trim((string) env('mcs.speedtest.email', '')),
            (string) env('mcs.speedtest.password', ''),
            null,
            $httpRequest,
            filter_var(env('mcs.speedtest.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function hasCredentials(): bool
    {
        return $this->email !== '' && $this->hasPassword();
    }

    public function hasPassword(): bool
    {
        return trim($this->password) !== '';
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * @return array{ok:bool,csv:string,error:string,http_code:int,filename:?string}
     */
    public function pobierzCsvRaport(
        ?DateTimeInterface $startDate = null,
        ?DateTimeInterface $endDate = null,
    ): array {
        if (! $this->hasCredentials()) {
            return [
                'ok'        => false,
                'csv'       => '',
                'error'     => 'Brak mcs.speedtest.email / mcs.speedtest.password',
                'http_code' => 0,
                'filename'  => null,
            ];
        }

        $token = $this->pobierzAccessToken();
        if (! $token['ok']) {
            return [
                'ok'        => false,
                'csv'       => '',
                'error'     => $token['error'],
                'http_code' => $token['http_code'],
                'filename'  => null,
            ];
        }

        [$start, $end] = $this->oknoDat($startDate, $endDate);
        $query         = http_build_query([
            'startDate'      => $start,
            'endDate'        => $end,
            // CSV export zwraca pełne okno (SPA używa page* przy JSON); trzymaj duże limity.
            'pageNumber'     => 1,
            'resultsPerPage' => 10000,
            'format'         => 'csv',
            'techType'       => '',
        ]);
        $url = $this->apiBase . '/api/servers/reports?' . $query;

        $res = $this->request($url, [
            'method'  => 'GET',
            'headers' => [
                'Authorization' => 'Bearer ' . $token['access_token'],
                'Accept'        => 'text/csv, application/json;q=0.9, */*;q=0.8',
            ],
        ]);

        if (! $res['ok']) {
            return [
                'ok'        => false,
                'csv'       => '',
                'error'     => 'Ookla reports CSV: ' . $res['error'],
                'http_code' => $res['http_code'],
                'filename'  => null,
            ];
        }

        $csv = (string) $res['body'];
        if (trim($csv) === '' || (! str_contains($csv, ',') && ! str_contains($csv, ';'))) {
            return [
                'ok'        => false,
                'csv'       => '',
                'error'     => 'Ookla reports CSV: pusta lub nie-CSV odpowiedź',
                'http_code' => $res['http_code'],
                'filename'  => null,
            ];
        }

        $filename = $this->nazwaZContentDisposition($res['headers']['content-disposition'] ?? null);

        return [
            'ok'        => true,
            'csv'       => $csv,
            'error'     => '',
            'http_code' => $res['http_code'],
            'filename'  => $filename,
        ];
    }

    /**
     * @return array{ok:bool,access_token:string,error:string,http_code:int}
     */
    private function pobierzAccessToken(): array
    {
        $payload = json_encode([
            'grant_type' => 'http://auth0.com/oauth/grant-type/password-realm',
            'username'   => $this->email,
            'password'   => $this->password,
            'client_id'  => self::AUTH0_CLIENT_ID,
            'audience'   => self::AUTH0_AUDIENCE,
            'scope'      => 'openid profile email',
            'realm'      => self::AUTH0_REALM,
        ], JSON_UNESCAPED_SLASHES);

        if ($payload === false) {
            return [
                'ok'           => false,
                'access_token' => '',
                'error'        => 'Ookla Auth0: nie udało się zbudować żądania tokenu',
                'http_code'    => 0,
            ];
        }

        $res = $this->request(self::AUTH0_TOKEN_URL, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'body'    => $payload,
        ]);

        $decoded = json_decode($res['body'], true);
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if (! $res['ok'] || ! isset($decoded['access_token']) || ! is_string($decoded['access_token']) || $decoded['access_token'] === '') {
            $error = (string) ($decoded['error'] ?? '');
            $desc  = (string) ($decoded['error_description'] ?? $decoded['message'] ?? '');
            $msg   = trim($error . ($desc !== '' ? (': ' . $desc) : ''));
            if ($msg === '') {
                $msg = $res['error'] !== '' ? $res['error'] : ('HTTP ' . $res['http_code']);
            }
            if (stripos($msg, 'mfa') !== false || stripos($error, 'mfa') !== false) {
                $msg = 'Ookla Auth0 wymaga MFA (' . $msg . ') — wyłącz 2FA dla konta raportowego albo użyj mcs.speedtest.csvDir';
            } else {
                $msg = 'Ookla Auth0 login: ' . $msg;
            }

            return [
                'ok'           => false,
                'access_token' => '',
                'error'        => $msg,
                'http_code'    => $res['http_code'],
            ];
        }

        return [
            'ok'           => true,
            'access_token' => $decoded['access_token'],
            'error'        => '',
            'http_code'    => $res['http_code'],
        ];
    }

    /**
     * Okno jak SPA „t30”: od ~1 miesiąc wstecz do dziś+2 dni.
     *
     * @return array{0:string,1:string} Y-m-d
     */
    private function oknoDat(?DateTimeInterface $startDate, ?DateTimeInterface $endDate): array
    {
        $tz = new DateTimeZone('UTC');
        if ($startDate !== null && $endDate !== null) {
            return [
                DateTimeImmutable::createFromInterface($startDate)->setTimezone($tz)->format('Y-m-d'),
                DateTimeImmutable::createFromInterface($endDate)->setTimezone($tz)->format('Y-m-d'),
            ];
        }

        $teraz  = new DateTimeImmutable('now', $tz);
        $start  = $teraz->sub(new DateInterval('P1M'));
        $end    = $teraz->add(new DateInterval('P2D'));

        return [$start->format('Y-m-d'), $end->format('Y-m-d')];
    }

    private function nazwaZContentDisposition(?string $header): ?string
    {
        if ($header === null || $header === '') {
            return null;
        }
        if (preg_match('/filename\*?=(?:UTF-8\'\')?"?([^";]+)"?/i', $header, $m) === 1) {
            return rawurldecode(trim($m[1]));
        }

        return null;
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{ok:bool,http_code:int,body:string,error:string,headers:array<string,string>}
     */
    private function request(string $url, array $opts): array
    {
        if ($this->httpRequest !== null) {
            $res = ($this->httpRequest)($url, $opts);
            $headers = [];
            if (isset($res['headers']) && is_array($res['headers'])) {
                foreach ($res['headers'] as $k => $v) {
                    $headers[strtolower((string) $k)] = (string) $v;
                }
            }

            return [
                'ok'        => (bool) ($res['ok'] ?? false),
                'http_code' => (int) ($res['http_code'] ?? 0),
                'body'      => (string) ($res['body'] ?? ''),
                'error'     => (string) ($res['error'] ?? ''),
                'headers'   => $headers,
            ];
        }

        return $this->curlRequest($url, $opts);
    }

    /**
     * @param array<string, mixed> $opts
     *
     * @return array{ok:bool,http_code:int,body:string,error:string,headers:array<string,string>}
     */
    private function curlRequest(string $url, array $opts): array
    {
        $method  = strtoupper((string) ($opts['method'] ?? 'GET'));
        $headers = [];
        if (isset($opts['headers']) && is_array($opts['headers'])) {
            foreach ($opts['headers'] as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }

        $headerLines = [];
        $ch          = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_SSL_VERIFYPEER => $this->tlsVerifyPeer,
            CURLOPT_SSL_VERIFYHOST => $this->tlsVerifyPeer ? 2 : 0,
            CURLOPT_USERAGENT      => 'MCS-Speedtest/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_TIMEOUT        => max(1, \App\Libraries\OpcjeMcs::int('mcs.speedtest.httpTimeout')),
            CURLOPT_CONNECTTIMEOUT => max(1, \App\Libraries\OpcjeMcs::int('mcs.speedtest.httpConnectTimeout')),
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headerLines): int {
                $len = strlen($line);
                $line = trim($line);
                if ($line !== '' && str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headerLines[strtolower(trim($name))] = trim($value);
                }

                return $len;
            },
        ]);
        if (isset($opts['body']) && is_string($opts['body']) && $opts['body'] !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['body']);
        }

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
                'headers'   => $headerLines,
            ];
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            $snippet = substr((string) $output, 0, 400);

            return [
                'ok'        => false,
                'http_code' => $httpCode,
                'body'      => (string) $output,
                'error'     => 'HTTP ' . $httpCode . ($snippet !== '' ? (': ' . $snippet) : ''),
                'headers'   => $headerLines,
            ];
        }

        return [
            'ok'        => true,
            'http_code' => $httpCode,
            'body'      => (string) $output,
            'error'     => '',
            'headers'   => $headerLines,
        ];
    }
}
