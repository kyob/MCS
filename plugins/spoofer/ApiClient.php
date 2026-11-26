<?php

namespace McsPluginy\spoofer;

use App\Libraries\Siec\BezpiecznyUrl;

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
        // SSRF: baseUrl z .env tylko https na publicznym hoście (inaczej odrzucony + warning w logu).
        $base = BezpiecznyUrl::baseUrlZEnv((string) env('mcs.spoofer.baseUrl', self::DEFAULT_BASE), 'mcs.spoofer.baseUrl');
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
            if ($next !== null && $url === null) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'sessions'   => [],
                    'error'      => 'Spoofer: hydra:next wskazuje inny host niż baseUrl (pominięto, ochrona przed SSRF)',
                    'raw_bodies' => $bodies,
                    'pages'      => $pages,
                ];
            }
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

    /**
     * Pełny URL z odpowiedzi API przyjmujemy tylko dla tego samego schematu, hosta i portu co baseUrl.
     */
    private function absolutnyUrl(string $pathOrUrl): ?string
    {
        if (str_starts_with($pathOrUrl, 'http://') || str_starts_with($pathOrUrl, 'https://')) {
            $cel  = parse_url($pathOrUrl);
            $baza = parse_url($this->baseUrl);
            if (! is_array($cel) || ! is_array($baza)) {
                return null;
            }
            $portCel  = $cel['port'] ?? ($cel['scheme'] === 'https' ? 443 : 80);
            $portBaza = $baza['port'] ?? (($baza['scheme'] ?? '') === 'https' ? 443 : 80);
            $ten = strtolower((string) ($cel['scheme'] ?? '')) === strtolower((string) ($baza['scheme'] ?? ''))
                && strtolower((string) ($cel['host'] ?? '')) === strtolower((string) ($baza['host'] ?? ''))
                && $portCel === $portBaza;

            return $ten ? $pathOrUrl : null;
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
        $res      = \App\Libraries\Siec\KlientHttp::wykonaj($url, [
            'timeout'        => max(1, \App\Libraries\OpcjeMcs::int('mcs.spoofer.httpTimeout')),
            'polaczenie'     => max(1, \App\Libraries\OpcjeMcs::int('mcs.spoofer.httpConnectTimeout')),
            'przekierowania' => 3,
            'tls'            => $this->tlsVerifyPeer,
            'user_agent'     => 'MCS-Spoofer/1.0',
            'naglowki'       => ['Accept: application/ld+json, application/json'],
        ]);
        $output   = $res['ok'] ? $res['body'] : false;
        $httpCode = $res['http_code'];
        $errno    = $res['errno'];
        $errstr   = $res['ok'] ? '' : ($res['error'] === 'curl_exec failed' ? '' : $res['error']);

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
