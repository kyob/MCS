<?php

namespace McsPluginy\blocklist_de;

/**
 * HTTPS GET list blocklist.de. Bez przekierowań. MD5 liczymy z treści po dekompresji.
 */
final class Klient
{
    public const HOST = 'lists.blocklist.de';

    public const USER_AGENT = 'MCS/1.0 (+https://github.com/kyob/MCS)';

    /** @var (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null */
    private $http;

    private int $timeout;

    /**
     * @param (callable(string, int): array{ok: bool, http_code: int, body: string, error: string})|null $http
     */
    public function __construct(?callable $http = null, int $timeout = 30)
    {
        $this->http    = $http;
        $this->timeout = max(1, $timeout);
    }

    public static function fromOpcje(?callable $http = null): self
    {
        return new self($http, \App\Libraries\OpcjeMcs::int('mcs.blocklistDe.httpTimeout'));
    }

    public static function urlTxt(string $nazwa): string
    {
        return 'https://' . self::HOST . '/lists/' . $nazwa . '.txt';
    }

    /**
     * @param list<string> $listy
     *
     * @return array{ok: bool, listy: array<string, list<string>>, md5_brak: list<string>, blad: string}
     */
    public function pobierz(array $listy): array
    {
        $parser  = new Parser();
        $zebrane = [];
        $md5Brak = [];

        foreach ($listy as $nazwa) {
            if (! in_array($nazwa, Listy::DOZWOLONE, true)) {
                return $this->blad('nieznana lista ' . $nazwa);
            }

            $txt = $this->pobierzUrl(self::urlTxt($nazwa));
            if (! $txt['ok']) {
                return $this->blad($nazwa . '.txt: ' . $txt['error']);
            }

            $md5 = $this->pobierzUrl(self::urlTxt($nazwa) . '.md5');
            if ($md5['ok']) {
                $kawałki    = preg_split('/\s+/', trim($md5['body'])) ?: [];
                $oczekiwany  = strtolower((string) ($kawałki[0] ?? ''));
                $rzeczywisty = hash('md5', $txt['body']);
                if (! preg_match('/^[a-f0-9]{32}$/', $oczekiwany) || ! hash_equals($oczekiwany, $rzeczywisty)) {
                    return $this->blad($nazwa . '.txt.md5 nie zgadza się z treścią');
                }
            } else {
                $md5Brak[] = $nazwa;
            }

            $parsed = $parser->parsuj($txt['body']);
            if (! $parsed['ok']) {
                return $this->blad($nazwa . '.txt: ' . $parsed['blad']);
            }
            $zebrane[$nazwa] = $parsed['ips'];
        }

        return ['ok' => true, 'listy' => $zebrane, 'md5_brak' => $md5Brak, 'blad' => ''];
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    private function pobierzUrl(string $url): array
    {
        $res = $this->http !== null
            ? ($this->http)($url, $this->timeout)
            : $this->curl($url);

        return [
            'ok'        => (bool) ($res['ok'] ?? false),
            'http_code' => (int) ($res['http_code'] ?? 0),
            'body'      => (string) ($res['body'] ?? ''),
            'error'     => (string) ($res['error'] ?? ''),
        ];
    }

    /**
     * @return array{ok: bool, listy: array<string, list<string>>, md5_brak: list<string>, blad: string}
     */
    private function blad(string $msg): array
    {
        return ['ok' => false, 'listy' => [], 'md5_brak' => [], 'blad' => $msg];
    }

    /**
     * @return array{ok: bool, http_code: int, body: string, error: string}
     */
    private function curl(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        if ($host !== self::HOST || ! str_starts_with($url, 'https://')) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'niedozwolony URL listy'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING       => '',
            CURLOPT_CONNECTTIMEOUT => min(15, $this->timeout),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_USERAGENT      => self::USER_AGENT,
            CURLOPT_HTTPHEADER     => ['Accept: text/plain'],
        ]);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'cURL: ' . $err];
        }
        if ($code !== 200) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => 'HTTP ' . $code];
        }

        return ['ok' => true, 'http_code' => 200, 'body' => (string) $out, 'error' => ''];
    }
}
