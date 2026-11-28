<?php

namespace McsPluginy\botnet_cl;

use App\Libraries\Siec\AsMatcher;
use App\Libraries\Siec\KlientHttp;

/**
 * GET https://botnet.cl/asn/{asn}. Host jest stały — baseUrl z .env nie może wskazać innego serwera.
 */
final class Klient
{
    public const HOST = 'botnet.cl';

    public const USER_AGENT = 'MCS/1.0 (+https://github.com/kyob/MCS)';

    /** @var (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null */
    private $http;

    private int $timeout;

    /**
     * @param (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null $http
     */
    public function __construct(?callable $http = null, int $timeout = 20)
    {
        $this->http    = $http;
        $this->timeout = max(1, $timeout);
    }

    public static function url(int $asn): string
    {
        return 'https://' . self::HOST . '/asn/' . $asn;
    }

    public static function asn(): int
    {
        $surowe = trim((string) env('mcs.botnet_cl.asn', ''));
        if ($surowe !== '' && ctype_digit($surowe)) {
            $asn = (int) $surowe;
            if ($asn >= 1 && $asn <= 4294967295) {
                return $asn;
            }
        }

        return AsMatcher::asn();
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string, url: string}
     */
    public function pobierz(int $asn): array
    {
        $url = self::url($asn);
        $res = $this->http !== null
            ? ($this->http)($url, $this->timeout)
            : $this->curl($url);

        $ok = (bool) ($res['ok'] ?? false) && (int) ($res['http_code'] ?? 0) === 200 && (string) ($res['body'] ?? '') !== '';

        return [
            'ok'        => $ok,
            'http_code' => (int) ($res['http_code'] ?? 0),
            'body'      => $ok ? (string) $res['body'] : '',
            'error'     => $ok ? '' : ((string) ($res['error'] ?? '') !== '' ? (string) $res['error'] : 'HTTP ' . (int) ($res['http_code'] ?? 0)),
            'url'       => $url,
        ];
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    private function curl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== self::HOST || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'niedozwolony URL botnet.cl'];
        }

        $res = KlientHttp::wykonaj($url, [
            'timeout'    => $this->timeout,
            'polaczenie' => min(15, $this->timeout),
            'zastoj'     => 0,
            'kompresja'  => true,
            'user_agent' => self::USER_AGENT,
            'naglowki'   => ['Accept: text/html'],
        ]);

        if (! $res['ok']) {
            return ['ok' => false, 'http_code' => $res['http_code'], 'body' => '', 'error' => 'cURL: ' . $res['error']];
        }

        return ['ok' => true, 'http_code' => $res['http_code'], 'body' => $res['body'], 'error' => ''];
    }
}
