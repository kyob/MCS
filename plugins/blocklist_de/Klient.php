<?php

namespace McsPluginy\blocklist_de;

/**
 * HTTPS GET list blocklist.de. Bez przekierowań. MD5 liczymy z treści po dekompresji.
 * Gdy suma nie zgadza się z plikiem, a dwa pobrania .txt są identyczne, suma jest nieaktualna
 * (blocklist.de publikuje listę i .md5 osobno) i przebieg idzie dalej.
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
     * @return array{ok: bool, listy: array<string, list<string>>, md5_brak: list<string>, md5_stare: list<string>, blad: string}
     */
    public function pobierz(array $listy): array
    {
        $parser   = new Parser();
        $zebrane  = [];
        $md5Brak  = [];
        $md5Stare = [];

        foreach ($listy as $nazwa) {
            if (! in_array($nazwa, Listy::DOZWOLONE, true)) {
                return $this->blad('nieznana lista ' . $nazwa);
            }

            $para = $this->para($nazwa, $parser);
            if ($para['status'] === 'md5_niezgodne' && $para['stare']) {
                $zebrane[$nazwa] = $para['ips'];
                $md5Stare[]      = $nazwa;

                continue;
            }
            if ($para['status'] !== 'ok') {
                return $this->blad($para['blad']);
            }
            if ($para['md5_brak']) {
                $md5Brak[] = $nazwa;
            }
            $zebrane[$nazwa] = $para['ips'];
        }

        return ['ok' => true, 'listy' => $zebrane, 'md5_brak' => $md5Brak, 'md5_stare' => $md5Stare, 'blad' => ''];
    }

    /**
     * @return array{status: string, blad: string, stare: bool, ips: list<string>, md5_brak: bool}
     */
    private function para(string $nazwa, Parser $parser): array
    {
        $txt = $this->pobierzUrl(self::urlTxt($nazwa));
        if (! $txt['ok']) {
            return $this->paraBlad($nazwa . '.txt: ' . $txt['error']);
        }

        $md5 = $this->pobierzUrl(self::urlTxt($nazwa) . '.md5');
        $werdykt = $this->werdyktMd5($txt['body'], $md5);
        if ($werdykt === 'brak') {
            return $this->poParsowaniu($nazwa, $parser, $txt['body'], true);
        }
        if ($werdykt === 'ok') {
            return $this->poParsowaniu($nazwa, $parser, $txt['body'], false);
        }
        if ($werdykt === 'format') {
            return $this->paraBlad($nazwa . '.txt.md5 nie zgadza się z treścią');
        }

        $poprzednie = $txt['body'];
        for ($i = 0; $i < 2; $i++) {
            $txt = $this->pobierzUrl(self::urlTxt($nazwa));
            if (! $txt['ok']) {
                return $this->paraBlad($nazwa . '.txt: ' . $txt['error']);
            }
            $md5     = $this->pobierzUrl(self::urlTxt($nazwa) . '.md5');
            $werdykt = $this->werdyktMd5($txt['body'], $md5);
            if ($werdykt === 'brak') {
                return $this->poParsowaniu($nazwa, $parser, $txt['body'], true);
            }
            if ($werdykt === 'ok') {
                return $this->poParsowaniu($nazwa, $parser, $txt['body'], false);
            }
            if ($werdykt === 'format') {
                return $this->paraBlad($nazwa . '.txt.md5 nie zgadza się z treścią');
            }
            if ($txt['body'] === $poprzednie) {
                return $this->nieaktualneMd5($nazwa, $parser, $txt['body']);
            }
            $poprzednie = $txt['body'];
        }

        return [
            'status'   => 'md5_niezgodne',
            'blad'     => $nazwa . '.txt.md5 nie zgadza się z treścią',
            'stare'    => false,
            'ips'      => [],
            'md5_brak' => false,
        ];
    }

    /**
     * @return array{status: string, blad: string, stare: bool, ips: list<string>, md5_brak: bool}
     */
    private function nieaktualneMd5(string $nazwa, Parser $parser, string $body): array
    {
        $parsed = $parser->parsuj($body);
        if (! $parsed['ok']) {
            return $this->paraBlad($nazwa . '.txt: ' . $parsed['blad']);
        }

        return [
            'status'   => 'md5_niezgodne',
            'blad'     => $nazwa . '.txt.md5 nie zgadza się z treścią',
            'stare'    => true,
            'ips'      => $parsed['ips'],
            'md5_brak' => false,
        ];
    }

    /**
     * @return array{status: string, blad: string, stare: bool, ips: list<string>, md5_brak: bool}
     */
    private function poParsowaniu(string $nazwa, Parser $parser, string $body, bool $md5Brak): array
    {
        $parsed = $parser->parsuj($body);
        if (! $parsed['ok']) {
            return $this->paraBlad($nazwa . '.txt: ' . $parsed['blad']);
        }

        return [
            'status'   => 'ok',
            'blad'     => '',
            'stare'    => false,
            'ips'      => $parsed['ips'],
            'md5_brak' => $md5Brak,
        ];
    }

    /**
     * @param array{ok: bool, body: string} $md5
     *
     * @return 'brak'|'format'|'ok'|'rozjazd'
     */
    private function werdyktMd5(string $tresc, array $md5): string
    {
        if (! $md5['ok']) {
            return 'brak';
        }
        $kawałki    = preg_split('/\s+/', trim($md5['body'])) ?: [];
        $oczekiwany = strtolower((string) ($kawałki[0] ?? ''));
        if (! preg_match('/^[a-f0-9]{32}$/', $oczekiwany)) {
            return 'format';
        }
        if (hash_equals($oczekiwany, hash('md5', $tresc))) {
            return 'ok';
        }

        return 'rozjazd';
    }

    /**
     * @return array{status: string, blad: string, stare: bool, ips: list<string>, md5_brak: bool}
     */
    private function paraBlad(string $msg): array
    {
        return ['status' => 'blad', 'blad' => $msg, 'stare' => false, 'ips' => [], 'md5_brak' => false];
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
     * @return array{ok: bool, listy: array<string, list<string>>, md5_brak: list<string>, md5_stare: list<string>, blad: string}
     */
    private function blad(string $msg): array
    {
        return ['ok' => false, 'listy' => [], 'md5_brak' => [], 'md5_stare' => [], 'blad' => $msg];
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
