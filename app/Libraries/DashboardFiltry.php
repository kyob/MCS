<?php

namespace App\Libraries;

use App\Libraries\Siec\CidrMatcher;
use App\Models\UstawieniaModel;

/**
 * Domyślne i zapamiętane filtry przeglądu (ustawienia + sesja).
 */
final class DashboardFiltry
{
    public const OKNA = [1, 2, 7, 14, 30, 90];

    public const MIN_ZRODEL = [1, 2, 3, 4, 5];

    public const WAGI = ['critical', 'high', 'medium', 'low'];

    public const KLUCZ_OKNO = 'dashboard_okno';

    public const KLUCZ_ZRODLO = 'dashboard_zrodlo';

    public const KLUCZ_KATEGORIA = 'dashboard_kategoria';

    public const KLUCZ_PORT = 'dashboard_port';

    public const KLUCZ_WAGA = 'dashboard_waga';

    public const KLUCZ_MIN_ZRODEL = 'dashboard_min_zrodel';

    public const SESJA_KLUCZ = 'mcs_dashboard_filtry';

    /** @var list<string> */
    private const GET_KLUCZE = ['okno', 'zrodlo', 'ip', 'waga', 'min_zrodel', 'kategoria', 'port', 'cidr', 'podsiec'];

    private ?UstawieniaModel $ustawienia;

    /** @var array{get: callable, set: callable, forget?: callable}|null */
    private ?array $sesja;

    /**
     * @param array{get: callable(string): mixed, set: callable(string, mixed): void, forget?: callable(string): void}|null $sesja
     */
    public function __construct(?UstawieniaModel $ustawienia = null, ?array $sesja = null)
    {
        $this->ustawienia = $ustawienia;
        $this->sesja      = $sesja;
    }

    /**
     * @return array{
     *   okno: int,
     *   zrodlo: ?string,
     *   ip: ?string,
     *   ipSurowy: string,
     *   minWaga: ?string,
     *   minZrodel: int,
     *   kategoria: ?string,
     *   port: ?int,
     *   bladIp: bool,
     *   cidr: ?string,
     *   cidrSurowy: string,
     *   bladCidr: bool
     * }
     */
    public function domyslne(): array
    {
        return $this->zloz(
            $this->normalizujOkno($this->pobierzUstawienie(self::KLUCZ_OKNO), 7),
            $this->normalizujTekst($this->pobierzUstawienie(self::KLUCZ_ZRODLO)),
            null,
            '',
            $this->normalizujWage($this->pobierzUstawienie(self::KLUCZ_WAGA)),
            $this->normalizujMinZrodel($this->pobierzUstawienie(self::KLUCZ_MIN_ZRODEL), 1),
            $this->normalizujTekst($this->pobierzUstawienie(self::KLUCZ_KATEGORIA)),
            $this->normalizujPort($this->pobierzUstawienie(self::KLUCZ_PORT)),
            false,
        );
    }

    /**
     * GET (gdy jest) → sesja → domyślne z ustawień. GET zapisuje wybór w sesji.
     *
     * @param array<string, mixed> $get
     *
     * @return array{
     *   okno: int,
     *   zrodlo: ?string,
     *   ip: ?string,
     *   ipSurowy: string,
     *   minWaga: ?string,
     *   minZrodel: int,
     *   kategoria: ?string,
     *   port: ?int,
     *   bladIp: bool,
     *   cidr: ?string,
     *   cidrSurowy: string,
     *   bladCidr: bool
     * }
     */
    public function rozstrzygnij(array $get): array
    {
        if ((string) ($get['wyczysc'] ?? '') === '1') {
            $this->sesjaForget(self::SESJA_KLUCZ);

            return $this->domyslne();
        }

        if ($this->czyGetMaFiltry($get)) {
            $filtry = $this->zGet($get, $this->domyslne());
            $this->sesjaSet(self::SESJA_KLUCZ, $this->doSesji($filtry));

            return $filtry;
        }

        $zSesji = $this->sesjaGet(self::SESJA_KLUCZ);
        if (is_array($zSesji)) {
            return $this->zZapisanych($zSesji);
        }

        return $this->domyslne();
    }

    /**
     * @param array<string, mixed> $post
     *
     * @return array{
     *   okno: int,
     *   zrodlo: ?string,
     *   ip: ?string,
     *   ipSurowy: string,
     *   minWaga: ?string,
     *   minZrodel: int,
     *   kategoria: ?string,
     *   port: ?int,
     *   bladIp: bool,
     *   cidr: ?string,
     *   cidrSurowy: string,
     *   bladCidr: bool
     * }
     */
    public function zFormularzaUstawien(array $post): array
    {
        return $this->zloz(
            $this->normalizujOkno($post['dashboard_okno'] ?? null, 7),
            $this->normalizujTekst($post['dashboard_zrodlo'] ?? null),
            null,
            '',
            $this->normalizujWage($post['dashboard_waga'] ?? null),
            $this->normalizujMinZrodel($post['dashboard_min_zrodel'] ?? null, 1),
            $this->normalizujTekst($post['dashboard_kategoria'] ?? null),
            $this->normalizujPort($post['dashboard_port'] ?? null),
            false,
        );
    }

    /**
     * @param array<string, mixed> $filtry
     */
    public function doQuery(array $filtry): string
    {
        $params = ['okno' => (int) ($filtry['okno'] ?? 7)];
        if (! empty($filtry['zrodlo'])) {
            $params['zrodlo'] = (string) $filtry['zrodlo'];
        }
        if (! empty($filtry['ip'])) {
            $params['ip'] = (string) $filtry['ip'];
        }
        if (! empty($filtry['minWaga'])) {
            $params['waga'] = (string) $filtry['minWaga'];
        }
        if ((int) ($filtry['minZrodel'] ?? 1) > 1) {
            $params['min_zrodel'] = (int) $filtry['minZrodel'];
        }
        if (! empty($filtry['kategoria'])) {
            $params['kategoria'] = (string) $filtry['kategoria'];
        }
        if (! empty($filtry['port'])) {
            $params['port'] = (int) $filtry['port'];
        }
        if (! empty($filtry['cidr'])) {
            $params['cidr'] = (string) $filtry['cidr'];
        }

        return http_build_query($params);
    }

    /**
     * Wartości kontrolek Podsieć / CIDR do formularza.
     *
     * @param array<string, mixed> $filtry
     * @param list<string>         $podsieciIpv4
     *
     * @return array{podsiec: ?string, cidrPole: string}
     */
    public function doFormularza(array $filtry, array $podsieciIpv4): array
    {
        $cidr   = isset($filtry['cidr']) && is_string($filtry['cidr']) ? $filtry['cidr'] : null;
        $surowy = (string) ($filtry['cidrSurowy'] ?? '');
        if (! empty($filtry['bladCidr'])) {
            return ['podsiec' => null, 'cidrPole' => $surowy];
        }
        if ($cidr !== null && in_array($cidr, $podsieciIpv4, true)) {
            return ['podsiec' => $cidr, 'cidrPole' => ''];
        }

        return ['podsiec' => null, 'cidrPole' => $cidr ?? ''];
    }

    /**
     * @return array<string, string>
     */
    public function doUstawien(array $filtry): array
    {
        return [
            self::KLUCZ_OKNO       => (string) (int) ($filtry['okno'] ?? 7),
            self::KLUCZ_ZRODLO     => (string) ($filtry['zrodlo'] ?? ''),
            self::KLUCZ_KATEGORIA  => (string) ($filtry['kategoria'] ?? ''),
            self::KLUCZ_PORT       => isset($filtry['port']) && $filtry['port'] !== null && $filtry['port'] !== ''
                ? (string) (int) $filtry['port']
                : '',
            self::KLUCZ_WAGA       => (string) ($filtry['minWaga'] ?? ''),
            self::KLUCZ_MIN_ZRODEL => (string) (int) ($filtry['minZrodel'] ?? 1),
        ];
    }

    /**
     * @param array<string, mixed> $get
     */
    private function czyGetMaFiltry(array $get): bool
    {
        foreach (self::GET_KLUCZE as $klucz) {
            if (array_key_exists($klucz, $get)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $baza
     *
     * @return array<string, mixed>
     */
    private function zGet(array $get, array $baza): array
    {
        $okno = array_key_exists('okno', $get)
            ? $this->normalizujOkno($get['okno'], (int) $baza['okno'])
            : (int) $baza['okno'];

        $zrodlo = array_key_exists('zrodlo', $get)
            ? $this->normalizujTekst($get['zrodlo'])
            : ($baza['zrodlo'] ?? null);

        $kategoria = array_key_exists('kategoria', $get)
            ? $this->normalizujTekst($get['kategoria'])
            : ($baza['kategoria'] ?? null);

        $minWaga = array_key_exists('waga', $get)
            ? $this->normalizujWage($get['waga'])
            : ($baza['minWaga'] ?? null);

        $minZrodel = array_key_exists('min_zrodel', $get)
            ? $this->normalizujMinZrodel($get['min_zrodel'], (int) $baza['minZrodel'])
            : (int) $baza['minZrodel'];

        $port = array_key_exists('port', $get)
            ? $this->normalizujPort($get['port'])
            : ($baza['port'] ?? null);

        $ipSurowy = '';
        $ip       = null;
        $bladIp   = false;
        if (array_key_exists('ip', $get)) {
            $ipSurowy = is_string($get['ip']) ? trim($get['ip']) : trim((string) $get['ip']);
            $bladIp   = $ipSurowy !== '' && ! filter_var($ipSurowy, FILTER_VALIDATE_IP);
            $ip       = (! $bladIp && $ipSurowy !== '') ? $ipSurowy : null;
        }

        $cidrSurowy = $this->wygranyCidrSurowy($get, $baza);
        $bladCidr   = $cidrSurowy !== '' && ! (new CidrMatcher())->czyPoprawnyCidr($cidrSurowy);
        $cidr       = (! $bladCidr && $cidrSurowy !== '') ? $cidrSurowy : null;

        return $this->zloz($okno, $zrodlo, $ip, $ipSurowy, $minWaga, $minZrodel, $kategoria, $port, $bladIp, $cidr, $cidrSurowy, $bladCidr);
    }

    /**
     * @param array<string, mixed> $dane
     *
     * @return array<string, mixed>
     */
    private function zZapisanych(array $dane): array
    {
        $ipSurowy = isset($dane['ip']) && is_string($dane['ip']) ? trim($dane['ip']) : '';
        $bladIp   = $ipSurowy !== '' && ! filter_var($ipSurowy, FILTER_VALIDATE_IP);
        $ip       = (! $bladIp && $ipSurowy !== '') ? $ipSurowy : null;

        $cidrSurowy = isset($dane['cidr']) && is_string($dane['cidr']) ? trim($dane['cidr']) : '';
        $bladCidr   = $cidrSurowy !== '' && ! (new CidrMatcher())->czyPoprawnyCidr($cidrSurowy);
        $cidr       = (! $bladCidr && $cidrSurowy !== '') ? $cidrSurowy : null;

        return $this->zloz(
            $this->normalizujOkno($dane['okno'] ?? null, 7),
            $this->normalizujTekst($dane['zrodlo'] ?? null),
            $ip,
            $ipSurowy,
            $this->normalizujWage($dane['minWaga'] ?? null),
            $this->normalizujMinZrodel($dane['minZrodel'] ?? null, 1),
            $this->normalizujTekst($dane['kategoria'] ?? null),
            $this->normalizujPort($dane['port'] ?? null),
            $bladIp,
            $cidr,
            $cidrSurowy,
            $bladCidr,
        );
    }

    /**
     * @param array<string, mixed> $filtry
     *
     * @return array<string, mixed>
     */
    private function doSesji(array $filtry): array
    {
        return [
            'okno'      => (int) $filtry['okno'],
            'zrodlo'    => $filtry['zrodlo'] ?? null,
            'ip'        => ! empty($filtry['bladIp']) ? null : ($filtry['ip'] ?? null),
            'minWaga'   => $filtry['minWaga'] ?? null,
            'minZrodel' => (int) $filtry['minZrodel'],
            'kategoria' => $filtry['kategoria'] ?? null,
            'port'      => $filtry['port'] ?? null,
            'cidr'      => ! empty($filtry['bladCidr']) ? null : ($filtry['cidr'] ?? null),
        ];
    }

    /**
     * @return array{
     *   okno: int,
     *   zrodlo: ?string,
     *   ip: ?string,
     *   ipSurowy: string,
     *   minWaga: ?string,
     *   minZrodel: int,
     *   kategoria: ?string,
     *   port: ?int,
     *   bladIp: bool,
     *   cidr: ?string,
     *   cidrSurowy: string,
     *   bladCidr: bool
     * }
     */
    private function zloz(
        int $okno,
        ?string $zrodlo,
        ?string $ip,
        string $ipSurowy,
        ?string $minWaga,
        int $minZrodel,
        ?string $kategoria,
        ?int $port,
        bool $bladIp,
        ?string $cidr = null,
        string $cidrSurowy = '',
        bool $bladCidr = false,
    ): array {
        return [
            'okno'       => $okno,
            'zrodlo'     => $zrodlo,
            'ip'         => $ip,
            'ipSurowy'   => $ipSurowy,
            'minWaga'    => $minWaga,
            'minZrodel'  => $minZrodel,
            'kategoria'  => $kategoria,
            'port'       => $port,
            'bladIp'     => $bladIp,
            'cidr'       => $cidr,
            'cidrSurowy' => $cidrSurowy,
            'bladCidr'   => $bladCidr,
        ];
    }

    private function normalizujOkno(mixed $raw, int $domyslne): int
    {
        $okno = (int) $raw;
        if (! in_array($okno, self::OKNA, true)) {
            return $domyslne;
        }

        return $okno;
    }

    private function normalizujMinZrodel(mixed $raw, int $domyslne): int
    {
        $n = (int) $raw;
        if (! in_array($n, self::MIN_ZRODEL, true)) {
            return $domyslne;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $get
     * @param array<string, mixed> $baza
     */
    private function wygranyCidrSurowy(array $get, array $baza): string
    {
        $cidrPole = array_key_exists('cidr', $get) ? $this->trimMieszany($get['cidr']) : null;
        $podsiec  = array_key_exists('podsiec', $get) ? $this->trimMieszany($get['podsiec']) : null;

        if ($cidrPole !== null && $cidrPole !== '') {
            return $cidrPole;
        }
        if ($podsiec !== null && $podsiec !== '') {
            return $podsiec;
        }
        if ($cidrPole !== null || $podsiec !== null) {
            return '';
        }

        if (! empty($baza['cidrSurowy']) && is_string($baza['cidrSurowy'])) {
            return $baza['cidrSurowy'];
        }
        if (! empty($baza['cidr']) && is_string($baza['cidr'])) {
            return $baza['cidr'];
        }

        return '';
    }

    private function trimMieszany(mixed $raw): string
    {
        if (is_string($raw)) {
            return trim($raw);
        }
        if (is_scalar($raw)) {
            return trim((string) $raw);
        }

        return '';
    }

    private function normalizujWage(mixed $raw): ?string
    {
        $w = is_string($raw) ? trim($raw) : (is_scalar($raw) ? trim((string) $raw) : '');
        if ($w === '' || ! in_array($w, self::WAGI, true)) {
            return null;
        }

        return $w;
    }

    private function normalizujPort(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_string($raw) && trim($raw) === '') {
            return null;
        }
        $port = (int) (is_string($raw) ? trim($raw) : $raw);
        if ($port < 1 || $port > 65535) {
            return null;
        }

        return $port;
    }

    private function normalizujTekst(mixed $raw): ?string
    {
        if (! is_string($raw) && ! is_numeric($raw)) {
            return null;
        }
        $t = trim((string) $raw);

        return $t !== '' ? $t : null;
    }

    private function pobierzUstawienie(string $klucz): ?string
    {
        $model = $this->ustawienia ?? $this->sprobujModel();
        if ($model === null) {
            return null;
        }

        try {
            return $model->pobierz($klucz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sprobujModel(): ?UstawieniaModel
    {
        try {
            $model = model(UstawieniaModel::class);

            return $model instanceof UstawieniaModel ? $model : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sesjaGet(string $klucz): mixed
    {
        if ($this->sesja !== null) {
            return ($this->sesja['get'])($klucz);
        }

        try {
            return session()->get($klucz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function sesjaSet(string $klucz, mixed $wartosc): void
    {
        if ($this->sesja !== null) {
            ($this->sesja['set'])($klucz, $wartosc);

            return;
        }

        try {
            session()->set($klucz, $wartosc);
        } catch (\Throwable) {
            // brak sesji w CLI / testach
        }
    }

    private function sesjaForget(string $klucz): void
    {
        if ($this->sesja !== null) {
            $forget = $this->sesja['forget'] ?? null;
            if (is_callable($forget)) {
                $forget($klucz);
            }

            return;
        }

        try {
            session()->remove($klucz);
        } catch (\Throwable) {
            // brak sesji
        }
    }
}
