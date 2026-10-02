<?php

namespace McsPluginy\spoofer;

/**
 * Klient REST publicznego CAIDA Spoofer Data API (Hydra, bez klucza).
 *
 * GET /sessions?asn={asn} — tylko sesje monitorowanego ASN.
 *
 * @see https://www.caida.org/projects/spoofer/data-api/
 * @see https://api.spoofer.caida.org/
 */
class ApiClient
{
    public const DEFAULT_BASE = 'https://api.spoofer.caida.org';

    private const MAX_PAGES = 50;

    private string $baseUrl;
    private bool $tlsVerifyPeer;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
     */
    public function __construct(
        string $baseUrl = self::DEFAULT_BASE,
        bool $tlsVerifyPeer = true,
        ?callable $httpRequest = null,
    ) {
        $this->baseUrl       = rtrim($baseUrl, '/');
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->httpRequest   = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $base = trim((string) env('mcs.spoofer.baseUrl', self::DEFAULT_BASE));
        if ($base === '') {
            $base = self::DEFAULT_BASE;
        }

        return new self(
            $base,
            filter_var(env('mcs.spoofer.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
            $httpRequest,
        );
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @return array{
     *   ok: bool,
     *   http_code: int,
     *   sessions: list<array<string, mixed>>,
     *   error: string,
     *   raw_bodies: list<string>,
     *   pages: int
     * }
     */
    public function fetchSessions(int $asn, ?string $timestampAfter = null): array
    {
        if ($asn < 1) {
            return [
                'ok'         => false,
                'http_code'  => 0,
                'sessions'   => [],
                'error'      => 'Spoofer: ASN musi byc > 0',
                'raw_bodies' => [],
                'pages'      => 0,
            ];
        }

        $query = [
            'asn'              => (string) $asn,
            'order[timestamp]' => 'desc',
        ];
        if ($timestampAfter !== null && trim($timestampAfter) !== '') {
            $query['timestamp[after]'] = trim($timestampAfter);
        }

        $url      = $this->baseUrl . '/sessions?' . http_build_query($query);
        $sessions = [];
        $bodies   = [];
        $pages    = 0;
        $lastCode = 0;

        while ($url !== null && $pages < max(1, \App\Libraries\OpcjeMcs::int('mcs.spoofer.maxPages'))) {
            $res = $this->get($url);
            $lastCode = $res['http_code'];
            $bodies[] = $res['body'];
            $pages++;

            if (! $res['ok']) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'sessions'   => [],
                    'error'      => $res['error'],
                    'raw_bodies' => $bodies,
                    'pages'      => $pages,
                ];
            }

            $decoded = json_decode($res['body'], true);
            if (! is_array($decoded)) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'sessions'   => [],
                    'error'      => 'Spoofer: odpowiedz nie jest JSON',
                    'raw_bodies' => $bodies,
                    'pages'      => $pages,
                ];
            }

            $parser = new Parser();
            foreach ($parser->parsujJson($res['body']) as $session) {
                $sessions[] = $session;
            }

            $next = $this->nastepnaStrona($decoded);
            $url  = $next !== null ? $this->absolutnyUrl($next) : null;
        }

        return [
            'ok'         => true,
            'http_code'  => $lastCode,
            'sessions'   => $sessions,
            'error'      => '',
            'raw_bodies' => $bodies,
            'pages'      => $pages,
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,body:string,error:string}
     */
    public function get(string $url): array
    {
        if ($this->httpRequest !== null) {
            return ($this->httpRequest)($url, [
                'verify_peer' => $this->tlsVerifyPeer,
            ]);
        }

        return $this->curlGet($url);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function nastepnaStrona(array $decoded): ?string
    {
        $view = $decoded['hydra:view'] ?? null;
        if (! is_array($view)) {
            return null;
        }
        $next = $view['hydra:next'] ?? null;
        if (! is_string($next) || trim($next) === '') {
            return null;
        }

        return trim($next);
    }

    private function absolutnyUrl(string $pathOrUrl): string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            return $pathOrUrl;
        }
        if (! str_starts_with($pathOrUrl, '/')) {
            $pathOrUrl = '/' . $pathOrUrl;
        }

        return $this->baseUrl . $pathOrUrl;
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
            CURLOPT_USERAGENT      => 'MCS-Spoofer/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => max(1, \App\Libraries\OpcjeMcs::int('mcs.spoofer.httpTimeout')),
            CURLOPT_CONNECTTIMEOUT => max(1, \App\Libraries\OpcjeMcs::int('mcs.spoofer.httpConnectTimeout')),
            CURLOPT_HTTPHEADER     => [
                'Accept: application/ld+json, application/json',
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
}
