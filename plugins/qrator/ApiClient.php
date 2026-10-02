<?php

namespace McsPluginy\qrator;

/**
 * Klient REST Qrator Radar (BGP / sieć).
 *
 * Auth: nagłówek QRADAR-API-KEY.
 * OpenAPI: https://api.radar.qrator.net/openapi.json
 */
class ApiClient
{
    public const FEED_HIJACKS_AFFECTED = 'hijacks_affected';
    public const FEED_HIJACKS_CREATED  = 'hijacks_created';
    public const FEED_LEAKS_CREATED    = 'leaks_created';
    public const FEED_LEAKS_ACCEPTED   = 'leaks_accepted';
    public const FEED_AMPLIFIERS       = 'amplifiers';
    public const FEED_BOGONS_CREATED   = 'bogons_created';

    /** @var list<string> */
    public const DEFAULT_FEEDS = [
        self::FEED_HIJACKS_AFFECTED,
        self::FEED_HIJACKS_CREATED,
        self::FEED_LEAKS_CREATED,
        self::FEED_LEAKS_ACCEPTED,
    ];

    /** @var list<string> */
    public const ALLOWED_FEEDS = [
        self::FEED_HIJACKS_AFFECTED,
        self::FEED_HIJACKS_CREATED,
        self::FEED_LEAKS_CREATED,
        self::FEED_LEAKS_ACCEPTED,
        self::FEED_AMPLIFIERS,
        self::FEED_BOGONS_CREATED,
    ];

    private string $baseUrl;
    private string $apiKey;
    private bool $tlsVerifyPeer;

    /** @var callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null */
    private $httpRequest;

    /**
     * @param callable(string, array<string,mixed>): array{ok:bool,http_code:int,body:string,error:string}|null $httpRequest
     */
    public function __construct(
        string $apiKey,
        string $baseUrl = 'https://api.radar.qrator.net',
        bool $tlsVerifyPeer = true,
        ?callable $httpRequest = null,
    ) {
        $this->apiKey        = $apiKey;
        $this->baseUrl       = rtrim($baseUrl, '/');
        $this->tlsVerifyPeer = $tlsVerifyPeer;
        $this->httpRequest   = $httpRequest;
    }

    public static function fromEnv(?callable $httpRequest = null): self
    {
        $base = trim((string) env('mcs.qrator.baseUrl', 'https://api.radar.qrator.net'));
        if ($base === '') {
            $base = 'https://api.radar.qrator.net';
        }

        return new self(
            trim((string) env('mcs.qrator.apiKey', '')),
            $base,
            filter_var(env('mcs.qrator.tlsVerifyPeer', true), FILTER_VALIDATE_BOOLEAN),
            $httpRequest,
        );
    }

    public function hasKey(): bool
    {
        return $this->apiKey !== '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param list<string>|null $feeds
     *
     * @return array{
     *   ok: bool,
     *   http_code: int,
     *   events: list<array{feed: string, raw: array<string, mixed>}>,
     *   error: string,
     *   raw_bodies: array<string, string>
     * }
     */
    public function fetchFeeds(
        int $asn,
        string $ipVersion = 'all',
        bool $withArchive = false,
        ?array $feeds = null,
    ): array {
        if (! $this->hasKey()) {
            return [
                'ok'         => false,
                'http_code'  => 0,
                'events'     => [],
                'error'      => 'mcs.qrator.apiKey is not set',
                'raw_bodies' => [],
            ];
        }
        if ($asn < 1) {
            return [
                'ok'         => false,
                'http_code'  => 0,
                'events'     => [],
                'error'      => 'Qrator: ASN musi byc > 0',
                'raw_bodies' => [],
            ];
        }

        $ipVersion = $this->normalizeIpVersion($ipVersion);
        $feeds     = $feeds ?? self::DEFAULT_FEEDS;
        $events    = [];
        $bodies    = [];
        $lastCode  = 0;

        foreach ($feeds as $feed) {
            $feed = strtolower(trim((string) $feed));
            if ($feed === '') {
                continue;
            }

            $res = $this->fetchOneFeed($feed, $asn, $ipVersion, $withArchive);
            $lastCode        = $res['http_code'];
            $bodies[$feed]   = $res['raw_body'];

            if (! $res['ok']) {
                return [
                    'ok'         => false,
                    'http_code'  => $res['http_code'],
                    'events'     => [],
                    'error'      => $feed . ': ' . $res['error'],
                    'raw_bodies' => $bodies,
                ];
            }

            foreach ($res['items'] as $item) {
                $events[] = [
                    'feed' => $feed,
                    'raw'  => $item,
                ];
            }
        }

        return [
            'ok'         => true,
            'http_code'  => $lastCode,
            'events'     => $events,
            'error'      => '',
            'raw_bodies' => $bodies,
        ];
    }

    /**
     * @return array{ok:bool,http_code:int,items:list<array<string,mixed>>,error:string,raw_body:string}
     */
    public function fetchOneFeed(string $feed, int $asn, string $ipVersion, bool $withArchive): array
    {
        $path = $this->pathForFeed($feed, $asn);
        if ($path === null) {
            return [
                'ok'        => false,
                'http_code' => 0,
                'items'     => [],
                'error'     => 'nieznany feed: ' . $feed,
                'raw_body'  => '',
            ];
        }

        $query = [];
        if ($this->feedNeedsIpVersion($feed)) {
            $query['ip'] = $this->normalizeIpVersion($ipVersion);
        }
        if ($withArchive && $this->feedSupportsArchive($feed)) {
            $query['with-archive'] = 'true';
        }

        if ($query !== []) {
            $path .= '?' . http_build_query($query);
        }

        return $this->getJsonList($path);
    }

    /**
     * @return list<string>
     */
    public static function parsujFeeds(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') {
            return self::DEFAULT_FEEDS;
        }
        $parts = preg_split('/[\s,;]+/', $raw) ?: [];
        $out   = [];
        foreach ($parts as $p) {
            $p = strtolower(trim((string) $p));
            if ($p !== '' && in_array($p, self::ALLOWED_FEEDS, true)) {
                $out[] = $p;
            }
        }

        return $out !== [] ? array_values(array_unique($out)) : self::DEFAULT_FEEDS;
    }

    public function normalizeIpVersion(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match ($raw) {
            '4', 'ipv4', 'v4' => '4',
            '6', 'ipv6', 'v6' => '6',
            default           => 'all',
        };
    }

    /**
     * @return array{ok:bool,http_code:int,items:list<array<string,mixed>>,error:string,raw_body:string}
     */
    private function getJsonList(string $path): array
    {
        $url = $this->baseUrl . $path;
        $res = $this->get($url);
        if (! $res['ok']) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'items'     => [],
                'error'     => $res['error'],
                'raw_body'  => $res['body'],
            ];
        }

        $decoded = json_decode($res['body'], true);
        if (! is_array($decoded)) {
            return [
                'ok'        => false,
                'http_code' => $res['http_code'],
                'items'     => [],
                'error'     => 'Qrator: odpowiedz nie jest JSON',
                'raw_body'  => $res['body'],
            ];
        }

        // Część endpointów owija w {meta,data}; v2 hijacks/leaks zwracają tablicę.
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            $rows = $decoded['data'];
        } elseif (array_is_list($decoded)) {
            $rows = $decoded;
        } else {
            $rows = [$decoded];
        }

        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                // Amplifiers / wrapped: czasem [prefix_or_ip, {...}]
                if (array_is_list($row) && count($row) === 2 && is_array($row[1])) {
                    $extra = $row[1];
                    if (is_string($row[0]) || is_int($row[0])) {
                        if (! isset($extra['prefix']) && ! isset($extra['ip']) && str_contains((string) $row[0], '/')) {
                            $extra['prefix'] = (string) $row[0];
                        } elseif (! isset($extra['ip']) && filter_var((string) $row[0], FILTER_VALIDATE_IP)) {
                            $extra['ip'] = (string) $row[0];
                        }
                    }
                    $items[] = $extra;
                    continue;
                }
                $items[] = $row;
            }
        }

        return [
            'ok'        => true,
            'http_code' => $res['http_code'],
            'items'     => $items,
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
                'api_key'     => $this->apiKey,
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
            CURLOPT_USERAGENT      => 'MCS-QratorRadar/1.0',
            CURLOPT_URL            => $url,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'QRADAR-API-KEY: ' . $this->apiKey,
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

    private function pathForFeed(string $feed, int $asn): ?string
    {
        return match ($feed) {
            self::FEED_HIJACKS_AFFECTED => '/v2/hijacks/affected/asn/' . $asn,
            self::FEED_HIJACKS_CREATED  => '/v2/hijacks/created/asn/' . $asn,
            self::FEED_LEAKS_CREATED    => '/v2/leaks/created/asn/' . $asn,
            self::FEED_LEAKS_ACCEPTED   => '/v2/leaks/accepted/asn/' . $asn,
            self::FEED_AMPLIFIERS       => '/v2/amplifiers/asn/' . $asn,
            self::FEED_BOGONS_CREATED   => '/v2/bogons/prefix/created/asn/' . $asn,
            default                     => null,
        };
    }

    private function feedNeedsIpVersion(string $feed): bool
    {
        return $feed !== self::FEED_AMPLIFIERS;
    }

    private function feedSupportsArchive(string $feed): bool
    {
        return $feed !== self::FEED_AMPLIFIERS;
    }
}
