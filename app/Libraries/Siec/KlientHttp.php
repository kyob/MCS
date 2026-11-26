<?php

namespace App\Libraries\Siec;

/**
 * Jedno miejsce na wywołania cURL w MCS (klienci API, pobieranie list).
 *
 * Dwa niezależne limity:
 * - timeout: twardy limit całego żądania (s),
 * - zastoj:  przerwanie, gdy przez tyle sekund transfer jest wolniejszy niż MIN_PREDKOSC
 *            (duże zrzuty z wolnych serwerów; 0 = wyłączone).
 */
final class KlientHttp
{
    public const MIN_PREDKOSC = 1024;

    /** Twardy limit dla dużych pobrań, gdy włączone jest wykrywanie zastoju (s). */
    public const MAKS_CZAS_POBRANIA = 600;

    /**
     * @param array{
     *   metoda?: string,
     *   body?: string|null,
     *   naglowki?: list<string>,
     *   timeout?: int,
     *   polaczenie?: int,
     *   zastoj?: int,
     *   przekierowania?: int,
     *   tls?: bool,
     *   user_agent?: string,
     *   kompresja?: bool,
     *   basic?: array{0: string, 1: string}|null,
     *   cert?: string,
     *   cert_klucz?: string,
     *   cert_haslo?: string,
     *   ca?: string,
     * } $o
     *
     * @return array{ok: bool, http_code: int, body: string, error: string, errno: int, naglowki: array<string, string>}
     *         ok = cURL zakończył transfer (kod HTTP sprawdza wołający); error = surowy curl_error
     */
    public static function wykonaj(string $url, array $o = []): array
    {
        $timeout    = max(1, (int) ($o['timeout'] ?? 30));
        $polaczenie = max(1, min($timeout, (int) ($o['polaczenie'] ?? 15)));
        $zastoj     = max(0, (int) ($o['zastoj'] ?? 0));
        $redirecty  = max(0, (int) ($o['przekierowania'] ?? 0));
        $tls        = (bool) ($o['tls'] ?? true);
        $metoda     = strtoupper((string) ($o['metoda'] ?? 'GET'));

        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'http_code' => 0, 'body' => '', 'error' => 'curl_init failed', 'errno' => 0, 'naglowki' => []];
        }

        $naglowki = [];
        $opcje    = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => $redirecty > 0,
            CURLOPT_CONNECTTIMEOUT => $polaczenie,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_SSL_VERIFYPEER => $tls,
            CURLOPT_SSL_VERIFYHOST => $tls ? 2 : 0,
            CURLOPT_HTTPHEADER     => $o['naglowki'] ?? [],
            CURLOPT_HEADERFUNCTION => static function ($ch, string $linia) use (&$naglowki): int {
                $poz = strpos($linia, ':');
                if ($poz !== false) {
                    $naglowki[strtolower(trim(substr($linia, 0, $poz)))] = trim(substr($linia, $poz + 1));
                }

                return strlen($linia);
            },
        ];
        if ($redirecty > 0) {
            // Redirecty tylko po https (egress https wymuszony także po redirectach).
            $opcje[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTPS;
            $opcje[CURLOPT_MAXREDIRS]       = $redirecty;
        }
        if ($zastoj > 0) {
            $opcje[CURLOPT_LOW_SPEED_LIMIT] = self::MIN_PREDKOSC;
            $opcje[CURLOPT_LOW_SPEED_TIME]  = $zastoj;
        }
        if ($metoda === 'POST') {
            $opcje[CURLOPT_POST] = true;
        } elseif ($metoda !== 'GET') {
            $opcje[CURLOPT_CUSTOMREQUEST] = $metoda;
        }
        if (array_key_exists('body', $o) && $o['body'] !== null) {
            $opcje[CURLOPT_POSTFIELDS] = (string) $o['body'];
        }
        if (! empty($o['user_agent'])) {
            $opcje[CURLOPT_USERAGENT] = (string) $o['user_agent'];
        }
        if (! empty($o['kompresja'])) {
            $opcje[CURLOPT_ENCODING] = '';
        }
        if (! empty($o['basic'])) {
            $opcje[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opcje[CURLOPT_USERPWD]  = $o['basic'][0] . ':' . $o['basic'][1];
        }
        if (! empty($o['cert'])) {
            $opcje[CURLOPT_SSLCERT] = (string) $o['cert'];
        }
        if (! empty($o['cert_klucz'])) {
            $opcje[CURLOPT_SSLKEY] = (string) $o['cert_klucz'];
        }
        if (! empty($o['cert_haslo'])) {
            $opcje[CURLOPT_SSLCERTPASSWD] = (string) $o['cert_haslo'];
        }
        if (! empty($o['ca'])) {
            $opcje[CURLOPT_CAINFO] = (string) $o['ca'];
        }

        curl_setopt_array($ch, $opcje);
        $out  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        $nr   = curl_errno($ch);
        curl_close($ch);

        if ($out === false) {
            return ['ok' => false, 'http_code' => $code, 'body' => '', 'error' => $err !== '' ? $err : 'curl_exec failed', 'errno' => $nr, 'naglowki' => $naglowki];
        }

        return ['ok' => true, 'http_code' => $code, 'body' => (string) $out, 'error' => '', 'errno' => 0, 'naglowki' => $naglowki];
    }

    /**
     * Nagłówek Retry-After w sekundach (0, gdy brak albo podany jako data).
     *
     * @param array<string, string> $naglowki
     */
    public static function retryAfter(array $naglowki): int
    {
        return (int) ($naglowki['retry-after'] ?? 0);
    }
}
