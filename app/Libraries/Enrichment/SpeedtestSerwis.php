<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\As198050Matcher;
use App\Models\EnrichmentSpeedtestModel;
use McsPluginy\speedtest\Parser;
use McsPluginy\speedtest\PortalClient;

/**
 * Enrichment Speedtest: import CSV z raportów hostowanych serwerów Ookla.
 * Świadomie NIE zapisuje do mcs_incydenty / mcs_obserwacje / badge.
 * Agregaty UX: IPv4 → /24 (cidrZIp; Ookla anonimizuje do .0), IPv6 → /48
 * (Ookla często podaje tylko kolumnę „ipv6 address” gdy ipAddress jest puste).
 *
 * Priorytet źródeł: fixturePath > portal (email+password) > csvDir.
 */
class SpeedtestSerwis
{
    public const DOMYSLNY_MIN_DOWNLOAD_MBPS = 500;

    public const DOMYSLNY_MIN_UPLOAD_MBPS = 500;

    public const DOMYSLNY_TOP_N = 20;

    /** @var list<int> */
    public const OKNA = [0, 7, 30];

    /** @var list<string> */
    public const SORTY = ['download', 'upload', 'data', 'testy'];

    private Parser $parser;
    private EnrichmentSpeedtestModel $model;
    private As198050Matcher $matcher;
    private PortalClient $portal;
    private string $fixturePath;
    private string $csvDir;

    /** @var string|null CSV z portalu zbuforowany między checksumZrodel() a sync() */
    private ?string $portalCsvCache = null;

    public function __construct(
        ?Parser $parser = null,
        ?EnrichmentSpeedtestModel $model = null,
        ?As198050Matcher $matcher = null,
        ?string $fixturePath = null,
        ?string $csvDir = null,
        ?PortalClient $portal = null,
    ) {
        $this->parser      = $parser ?? new Parser();
        $this->model       = $model ?? model(EnrichmentSpeedtestModel::class);
        $this->matcher     = $matcher ?? new As198050Matcher();
        $this->fixturePath = $fixturePath ?? trim((string) env('mcs.speedtest.fixturePath', ''));
        $this->csvDir      = rtrim($csvDir ?? trim((string) env('mcs.speedtest.csvDir', '')), '/');
        $this->portal      = $portal ?? PortalClient::fromEnv();
    }

    public function hasFixture(): bool
    {
        return $this->fixturePath !== '' && is_readable($this->fixturePath);
    }

    public function hasCsvDir(): bool
    {
        return $this->csvDir !== '' && is_dir($this->csvDir);
    }

    public function hasPortal(): bool
    {
        return $this->portal->hasCredentials();
    }

    public function tryb(): string
    {
        if ($this->hasFixture()) {
            return 'fixture';
        }
        if ($this->hasPortal()) {
            return 'portal';
        }
        if ($this->hasCsvDir()) {
            return 'csv';
        }

        return 'brak_konfiguracji';
    }

    /**
     * @return array{
     *   skrot: string,
     *   tryb_aktywny: string,
     *   tryb_etykieta: string,
     *   pozycje: list<array{nazwa: string, wartosc: string, aktywna: bool, ustawiona: bool}>
     * }
     */
    public function opisPolaczenia(): array
    {
        $email   = $this->portal->getEmail();
        $hasPass = $this->portal->hasPassword();
        $tryb    = $this->tryb();
        $aktywnyPlik   = $tryb === 'fixture';
        $aktywnyPortal = $tryb === 'portal';
        $aktywnyCsv    = $tryb === 'csv';

        $trybKod = match ($tryb) {
            'fixture' => 'plik_testowy',
            'portal'  => 'portal',
            'csv'     => 'katalog_csv',
            default   => 'brak',
        };
        $etykieta = match ($tryb) {
            'fixture' => 'plik testowy (lokalny)',
            'portal'  => 'portal Ookla',
            'csv'     => 'katalog CSV',
            default   => 'brak konfiguracji',
        };
        $skrot = match ($tryb) {
            'fixture' => 'plik testowy · ' . basename($this->fixturePath),
            'portal'  => 'portal Ookla · ' . ($email !== '' ? $email : 'account.ookla.com'),
            'csv'     => 'katalog CSV · ' . $this->csvDir,
            default   => 'brak konfiguracji',
        };

        return PluginOpisPolaczenia::zloz($trybKod, $etykieta, $skrot, [
            PluginOpisPolaczenia::pozycja('Plik testowy (lokalny)', $this->fixturePath, $aktywnyPlik),
            PluginOpisPolaczenia::pozycja('Email Ookla', $email, $aktywnyPortal),
            PluginOpisPolaczenia::pozycja('Hasło', PluginOpisPolaczenia::sekret($hasPass), false, $hasPass),
            PluginOpisPolaczenia::pozycja('Katalog CSV', $this->csvDir, $aktywnyCsv),
        ]);
    }

    /**
     * Checksum źródeł do dedupu koperty importu.
     */
    public function checksumZrodel(): ?string
    {
        $tryb = $this->tryb();
        if ($tryb === 'portal') {
            $csv = $this->pobierzPortalCsv();
            if ($csv === null || $csv === '') {
                return null;
            }

            return hash('sha256', $csv);
        }

        $pliki = $this->plikiDoImportu();
        if ($pliki === []) {
            return null;
        }
        $czesci = [];
        foreach ($pliki as $plik) {
            $czesci[] = $plik . ':' . (string) filesize($plik) . ':' . (string) filemtime($plik);
        }

        return hash('sha256', implode('|', $czesci));
    }

    /**
     * @return array{
     *   ok: bool,
     *   tryb: string,
     *   zapisano: int,
     *   pominieto_obce: int,
     *   pominieto_zle: int,
     *   pliki: int,
     *   blad?: string
     * }
     */
    public function sync(): array
    {
        $tryb = $this->tryb();
        if ($tryb === 'brak_konfiguracji') {
            return [
                'ok'             => false,
                'tryb'           => $tryb,
                'zapisano'       => 0,
                'pominieto_obce' => 0,
                'pominieto_zle'  => 0,
                'pliki'          => 0,
                'blad'           => 'Brak mcs.speedtest.email+password, csvDir albo fixturePath — ustaw login Ookla albo wrzuć CSV z account.ookla.com/servers/reports.',
            ];
        }

        if ($tryb === 'portal') {
            $csv = $this->pobierzPortalCsv();
            if ($csv === null) {
                $blad = $this->portalOstatniBlad ?? 'nie udało się pobrać CSV z portalu Ookla';

                return [
                    'ok'             => false,
                    'tryb'           => $tryb,
                    'zapisano'       => 0,
                    'pominieto_obce' => 0,
                    'pominieto_zle'  => 0,
                    'pliki'          => 0,
                    'blad'           => $blad,
                ];
            }
            $part = $this->importujTresc($csv, 'portal');

            return [
                'ok'             => true,
                'tryb'           => 'portal',
                'zapisano'       => $part['zapisano'],
                'pominieto_obce' => $part['pominieto_obce'],
                'pominieto_zle'  => $part['pominieto_zle'],
                'pliki'          => 1,
            ];
        }

        $zrodloDanych = $tryb === 'fixture' ? 'fixture' : 'csv';
        $zapisano     = 0;
        $obce         = 0;
        $zle          = 0;
        $pliki        = $this->plikiDoImportu();
        foreach ($pliki as $plik) {
            $tresc = (string) file_get_contents($plik);
            $part  = $this->importujTresc($tresc, $zrodloDanych);
            $zapisano += $part['zapisano'];
            $obce     += $part['pominieto_obce'];
            $zle      += $part['pominieto_zle'];
        }

        return [
            'ok'             => true,
            'tryb'           => $tryb,
            'zapisano'       => $zapisano,
            'pominieto_obce' => $obce,
            'pominieto_zle'  => $zle,
            'pliki'          => count($pliki),
        ];
    }

    /**
     * @return array{ok: bool, zapisano: int, pominieto_obce: int, pominieto_zle: int}
     */
    public function importujTresc(string $tresc, string $zrodloDanych = 'csv'): array
    {
        $wiersze = $this->parser->parsujCsv($tresc);
        $zapisano = 0;
        $obce     = 0;
        foreach ($wiersze as $wiersz) {
            if (! $this->matcher->czyNaszeIp($wiersz['ip'])) {
                $obce++;
                continue;
            }
            $this->zapiszWiersz($wiersz, $zrodloDanych);
            $zapisano++;
        }

        return [
            'ok'             => true,
            'zapisano'       => $zapisano,
            'pominieto_obce' => $obce,
            'pominieto_zle'  => 0,
        ];
    }

    /**
     * IPv4 → CIDR /24 (Ookla anonimizuje do *.0).
     * IPv6 → CIDR /48 (Ookla zwykle skraca hosta, np. 2a0f:1700:2::).
     */
    public static function cidrZIp(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $czesci = explode('.', $ip);
            if (count($czesci) !== 4) {
                return null;
            }

            return $czesci[0] . '.' . $czesci[1] . '.' . $czesci[2] . '.0/24';
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return null;
        }
        $siec = substr($bin, 0, 6) . str_repeat("\0", 10);
        $ntop = inet_ntop($siec);
        if ($ntop === false) {
            return null;
        }

        return strtolower($ntop) . '/48';
    }

    /**
     * Prefiks LIKE dla /24, np. „91.231.80.”.
     */
    public static function prefiks24ZCidr(string $cidr): ?string
    {
        $cidr = trim($cidr);
        if (! preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.0\/24$/', $cidr, $m)) {
            return null;
        }

        return $m[1] . '.';
    }

    /**
     * Filtry GET dashboardu Speedtest (wysycenie portu).
     *
     * @param array<string, mixed> $get
     *
     * @return array{
     *   okno: int,
     *   min_download_mbps: int,
     *   min_upload_mbps: int,
     *   cidr: string,
     *   sort: string,
     *   top_n: int,
     *   tylko_as: bool,
     *   wszystkie: bool
     * }
     */
    public static function parsujFiltry(array $get): array
    {
        $oknoRaw = $get['okno'] ?? 7;
        $okno    = is_numeric($oknoRaw) ? (int) $oknoRaw : 7;
        if (! in_array($okno, self::OKNA, true)) {
            $okno = 7;
        }

        $minDown = self::normalizujMbps($get['min_download_mbps'] ?? self::DOMYSLNY_MIN_DOWNLOAD_MBPS, self::DOMYSLNY_MIN_DOWNLOAD_MBPS);
        // Alias literówki mbbs → mbps (form/query).
        $minUpRaw = $get['min_upload_mbps'] ?? $get['min_upload_mbbs'] ?? self::DOMYSLNY_MIN_UPLOAD_MBPS;
        $minUp    = self::normalizujMbps($minUpRaw, self::DOMYSLNY_MIN_UPLOAD_MBPS);

        $cidr = trim((string) ($get['cidr'] ?? ''));
        if (mb_strlen($cidr) > 64) {
            $cidr = mb_substr($cidr, 0, 64);
        }

        $sort = (string) ($get['sort'] ?? 'download');
        if (! in_array($sort, self::SORTY, true)) {
            $sort = 'download';
        }

        $tylkoAs   = self::normalizujBool($get['tylko_as'] ?? null, true);
        $wszystkie = self::normalizujBool($get['wszystkie'] ?? null, false);
        if ($minDown === 0 && $minUp === 0) {
            $wszystkie = true;
        }

        $topNDefault = $wszystkie ? 100 : self::DOMYSLNY_TOP_N;
        $topNMax     = $wszystkie ? 500 : 100;
        $topN        = is_numeric($get['top_n'] ?? null) ? (int) $get['top_n'] : $topNDefault;
        $topN        = max(1, min($topNMax, $topN));

        return [
            'okno'              => $okno,
            'min_download_mbps' => $minDown,
            'min_upload_mbps'   => $minUp,
            'cidr'              => $cidr,
            'sort'              => $sort,
            'top_n'             => $topN,
            'tylko_as'          => $tylkoAs,
            'wszystkie'         => $wszystkie,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function historiaDlaIp(string $ip, int $limit = 20): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [];
        }

        return $this->model->dlaIp($ip, $limit);
    }

    /**
     * Historia testów w sieci /24 (IPv4) lub /48 (IPv6).
     *
     * @return list<array<string, mixed>>
     */
    public function historiaDlaCidr(string $cidr, int $limit = 50): array
    {
        $prefiks = self::prefiks24ZCidr($cidr);
        if ($prefiks !== null) {
            return $this->model->dlaPrefiksu24($prefiks, $limit);
        }

        $cidr = trim($cidr);
        if (! preg_match('/^[0-9a-f:]+\/48$/i', $cidr)) {
            return [];
        }
        $limit  = max(1, $limit);
        $wynik  = [];
        foreach ($this->model->wszystkieOd(null, 10000) as $w) {
            if (self::cidrZIp((string) ($w['ip'] ?? '')) === strtolower($cidr)) {
                $wynik[] = $w;
                if (count($wynik) >= $limit) {
                    break;
                }
            }
        }

        return $wynik;
    }

    /**
     * Skrót: /24 hosta IP (gdy IPv4).
     *
     * @return list<array<string, mixed>>
     */
    public function historiaDlaCidrZIp(string $ip, int $limit = 50): array
    {
        $cidr = self::cidrZIp($ip);
        if ($cidr === null) {
            return [];
        }

        return $this->historiaDlaCidr($cidr, $limit);
    }

    /**
     * Liczba testów w /24 hosta (0 gdy brak IPv4 / brak danych).
     */
    public function liczbaTestowDlaCidrZIp(string $ip, int $oknoDni = 0): int
    {
        $cidr = self::cidrZIp($ip);
        if ($cidr === null) {
            return 0;
        }
        if ($oknoDni <= 0) {
            return count($this->historiaDlaCidr($cidr, 10000));
        }
        foreach ($this->agregaty($oknoDni) as $wiersz) {
            if (($wiersz['cidr'] ?? '') === $cidr) {
                return (int) ($wiersz['liczba_testow'] ?? 0);
            }
        }

        return 0;
    }

    /**
     * Agregaty per /24. oknoDni=0 → cała historia.
     * $cidrContains — filtr „zawiera” na cidr (np. „91.231.80”).
     * $sort: testy|download|upload|data
     *
     * @return list<array{
     *   cidr: string,
     *   liczba_testow: int,
     *   avg_download_kbps: float|null,
     *   p50_download_kbps: int|null,
     *   avg_upload_kbps: float|null,
     *   p50_upload_kbps: int|null,
     *   avg_latency_ms: float|null,
     *   p50_latency_ms: float|null,
     *   max_download_kbps: int|null,
     *   max_upload_kbps: int|null,
     *   peak_download_ratio: float|null,
     *   peak_upload_ratio: float|null,
     *   ostatni_test_at: string|null
     * }>
     */
    public function agregaty(int $oknoDni = 0, string $cidrContains = '', string $sort = 'testy', bool $tylkoAs = true): array
    {
        $grupy = [];
        foreach ($this->wierszeWOknie($oknoDni, $cidrContains, $tylkoAs) as $w) {
            $cidr = self::cidrZIp((string) ($w['ip'] ?? ''));
            if ($cidr === null) {
                continue;
            }
            if (! isset($grupy[$cidr])) {
                $grupy[$cidr] = [
                    'cidr'            => $cidr,
                    'liczba_testow'   => 0,
                    'download'        => [],
                    'upload'          => [],
                    'latency'         => [],
                    'ostatni_test_at' => null,
                ];
            }
            $grupy[$cidr]['liczba_testow']++;
            if ($w['download_kbps'] !== null && $w['download_kbps'] !== '') {
                $grupy[$cidr]['download'][] = (int) $w['download_kbps'];
            }
            if ($w['upload_kbps'] !== null && $w['upload_kbps'] !== '') {
                $grupy[$cidr]['upload'][] = (int) $w['upload_kbps'];
            }
            if ($w['latency_ms'] !== null && $w['latency_ms'] !== '') {
                $grupy[$cidr]['latency'][] = (float) $w['latency_ms'];
            }
            $at = (string) ($w['zaobserwowano_at'] ?? '');
            if ($at !== '' && ($grupy[$cidr]['ostatni_test_at'] === null || $at > $grupy[$cidr]['ostatni_test_at'])) {
                $grupy[$cidr]['ostatni_test_at'] = $at;
            }
        }

        $wynik = [];
        foreach ($grupy as $g) {
            $avgDown = self::srednia($g['download']);
            $avgUp   = self::srednia($g['upload']);
            $maxDown = $g['download'] === [] ? null : max($g['download']);
            $maxUp   = $g['upload'] === [] ? null : max($g['upload']);
            $wynik[] = [
                'cidr'                => $g['cidr'],
                'liczba_testow'       => $g['liczba_testow'],
                'avg_download_kbps'   => $avgDown,
                'p50_download_kbps'   => self::medianaInt($g['download']),
                'avg_upload_kbps'     => $avgUp,
                'p50_upload_kbps'     => self::medianaInt($g['upload']),
                'avg_latency_ms'      => self::srednia($g['latency']),
                'p50_latency_ms'      => self::medianaFloat($g['latency']),
                'max_download_kbps'   => $maxDown,
                'max_upload_kbps'     => $maxUp,
                'peak_download_ratio' => self::peakRatio($maxDown, $avgDown),
                'peak_upload_ratio'   => self::peakRatio($maxUp, $avgUp),
                'ostatni_test_at'     => $g['ostatni_test_at'],
            ];
        }

        self::sortujAgregaty($wynik, $sort);

        return $wynik;
    }

    /**
     * Anomalie wysycenia: testy powyżej progów Mb/s (OR), top N surowe, /24 z max peakami.
     * Gdy wszystkie=true (lub oba progi 0): lista obejmuje wszystkie pomiary w filtrze;
     * liczba_powyzej nadal liczy tylko powyżej progów (gdy progi > 0).
     *
     * @param array{
     *   okno: int,
     *   min_download_mbps: int,
     *   min_upload_mbps: int,
     *   cidr: string,
     *   sort: string,
     *   top_n: int,
     *   tylko_as?: bool,
     *   wszystkie?: bool
     * } $filtry
     *
     * @return array{
     *   liczba_powyzej: int,
     *   liczba_pasujacych: int,
     *   top_testy: list<array{
     *     test_id: string,
     *     cidr: string,
     *     download_kbps: int|null,
     *     upload_kbps: int|null,
     *     latency_ms: float|null,
     *     zaobserwowano_at: string
     *   }>,
     *   cidr_peaki: list<array{
     *     cidr: string,
     *     liczba_testow: int,
     *     max_download_kbps: int|null,
     *     max_upload_kbps: int|null,
     *     avg_download_kbps: float|null,
     *     avg_upload_kbps: float|null,
     *     peak_download_ratio: float|null,
     *     peak_upload_ratio: float|null,
     *     liczba_powyzej: int,
     *     ostatni_test_at: string|null
     *   }>
     * }
     */
    public function anomalie(array $filtry): array
    {
        $okno      = (int) ($filtry['okno'] ?? 7);
        $minDown   = (int) ($filtry['min_download_mbps'] ?? 0);
        $minUp     = (int) ($filtry['min_upload_mbps'] ?? 0);
        $cidr      = (string) ($filtry['cidr'] ?? '');
        $sort      = (string) ($filtry['sort'] ?? 'download');
        $tylkoAs   = (bool) ($filtry['tylko_as'] ?? true);
        $wszystkie = (bool) ($filtry['wszystkie'] ?? false)
            || ($minDown === 0 && $minUp === 0);
        $topNMax   = $wszystkie ? 500 : 100;
        $topN      = max(1, min($topNMax, (int) ($filtry['top_n'] ?? ($wszystkie ? 100 : self::DOMYSLNY_TOP_N))));
        $minDownK  = $minDown > 0 ? $minDown * 1000 : 0;
        $minUpK    = $minUp > 0 ? $minUp * 1000 : 0;

        $lista   = [];
        $powyzej = 0;
        $perCidr = [];
        foreach ($this->wierszeWOknie($okno, $cidr, $tylkoAs) as $w) {
            $cidrW = self::cidrZIp((string) ($w['ip'] ?? ''));
            if ($cidrW === null) {
                continue;
            }
            $down = ($w['download_kbps'] !== null && $w['download_kbps'] !== '') ? (int) $w['download_kbps'] : null;
            $up   = ($w['upload_kbps'] !== null && $w['upload_kbps'] !== '') ? (int) $w['upload_kbps'] : null;
            $lat  = ($w['latency_ms'] !== null && $w['latency_ms'] !== '') ? (float) $w['latency_ms'] : null;
            $at   = (string) ($w['zaobserwowano_at'] ?? '');

            if (! isset($perCidr[$cidrW])) {
                $perCidr[$cidrW] = [
                    'cidr'            => $cidrW,
                    'liczba_testow'   => 0,
                    'download'        => [],
                    'upload'          => [],
                    'liczba_powyzej'  => 0,
                    'ostatni_test_at' => null,
                ];
            }
            $perCidr[$cidrW]['liczba_testow']++;
            if ($down !== null) {
                $perCidr[$cidrW]['download'][] = $down;
            }
            if ($up !== null) {
                $perCidr[$cidrW]['upload'][] = $up;
            }
            if ($at !== '' && ($perCidr[$cidrW]['ostatni_test_at'] === null || $at > $perCidr[$cidrW]['ostatni_test_at'])) {
                $perCidr[$cidrW]['ostatni_test_at'] = $at;
            }

            $trafiony = self::czyPowyzejProgu($down, $up, $minDownK, $minUpK);
            if ($trafiony) {
                $perCidr[$cidrW]['liczba_powyzej']++;
                $powyzej++;
            }

            if ($wszystkie || $trafiony) {
                $lista[] = [
                    'test_id'          => (string) ($w['test_id'] ?? ''),
                    'cidr'             => $cidrW,
                    'download_kbps'    => $down,
                    'upload_kbps'      => $up,
                    'latency_ms'       => $lat,
                    'zaobserwowano_at' => $at,
                ];
            }
        }

        self::sortujTesty($lista, $sort);
        $top = array_slice($lista, 0, $topN);

        $cidrPeaki = [];
        foreach ($perCidr as $g) {
            if ((int) $g['liczba_powyzej'] <= 0) {
                continue;
            }
            $avgDown = self::srednia($g['download']);
            $avgUp   = self::srednia($g['upload']);
            $maxDown = $g['download'] === [] ? null : max($g['download']);
            $maxUp   = $g['upload'] === [] ? null : max($g['upload']);
            $cidrPeaki[] = [
                'cidr'                => $g['cidr'],
                'liczba_testow'       => $g['liczba_testow'],
                'max_download_kbps'   => $maxDown,
                'max_upload_kbps'     => $maxUp,
                'avg_download_kbps'   => $avgDown,
                'avg_upload_kbps'     => $avgUp,
                'peak_download_ratio' => self::peakRatio($maxDown, $avgDown),
                'peak_upload_ratio'   => self::peakRatio($maxUp, $avgUp),
                'liczba_powyzej'      => $g['liczba_powyzej'],
                'ostatni_test_at'     => $g['ostatni_test_at'],
            ];
        }
        self::sortujAgregaty($cidrPeaki, $sort === 'testy' ? 'download' : $sort);

        return [
            'liczba_powyzej'    => $powyzej,
            'liczba_pasujacych' => count($lista),
            'top_testy'         => $top,
            'cidr_peaki'        => $cidrPeaki,
        ];
    }

    /**
     * KPI globalne w oknie. oknoDni=0 → cała historia.
     *
     * @return array{
     *   okno_dni: int,
     *   liczba_testow: int,
     *   liczba_cidr: int,
     *   avg_download_kbps: float|null,
     *   p50_download_kbps: int|null,
     *   avg_upload_kbps: float|null,
     *   p50_upload_kbps: int|null,
     *   avg_latency_ms: float|null,
     *   p50_latency_ms: float|null
     * }
     */
    public function kpi(int $oknoDni = 0, string $cidrContains = '', bool $tylkoAs = true): array
    {
        $wiersze  = $this->wierszeWOknie($oknoDni, $cidrContains, $tylkoAs);
        $cidrSet  = [];
        $download = [];
        $upload   = [];
        $latency  = [];
        foreach ($wiersze as $w) {
            $cidr = self::cidrZIp((string) ($w['ip'] ?? ''));
            if ($cidr !== null) {
                $cidrSet[$cidr] = true;
            }
            if ($w['download_kbps'] !== null && $w['download_kbps'] !== '') {
                $download[] = (int) $w['download_kbps'];
            }
            if ($w['upload_kbps'] !== null && $w['upload_kbps'] !== '') {
                $upload[] = (int) $w['upload_kbps'];
            }
            if ($w['latency_ms'] !== null && $w['latency_ms'] !== '') {
                $latency[] = (float) $w['latency_ms'];
            }
        }

        return [
            'okno_dni'          => $oknoDni,
            'liczba_testow'     => count($wiersze),
            'liczba_cidr'       => count($cidrSet),
            'avg_download_kbps' => self::srednia($download),
            'p50_download_kbps' => self::medianaInt($download),
            'avg_upload_kbps'   => self::srednia($upload),
            'p50_upload_kbps'   => self::medianaInt($upload),
            'avg_latency_ms'    => self::srednia($latency),
            'p50_latency_ms'    => self::medianaFloat($latency),
        ];
    }

    /**
     * Agregaty dzienne (chart-free lista). oknoDni=0 → cała historia.
     *
     * @return list<array{
     *   dzien: string,
     *   liczba_testow: int,
     *   avg_download_kbps: float|null,
     *   avg_upload_kbps: float|null,
     *   avg_latency_ms: float|null
     * }>
     */
    public function agregatyDzienne(int $oknoDni = 0, string $cidrContains = '', bool $tylkoAs = true): array
    {
        $grupy = [];
        foreach ($this->wierszeWOknie($oknoDni, $cidrContains, $tylkoAs) as $w) {
            $at = (string) ($w['zaobserwowano_at'] ?? '');
            if ($at === '') {
                continue;
            }
            $dzien = substr($at, 0, 10);
            if (! isset($grupy[$dzien])) {
                $grupy[$dzien] = [
                    'dzien'    => $dzien,
                    'liczba'   => 0,
                    'download' => [],
                    'upload'   => [],
                    'latency'  => [],
                ];
            }
            $grupy[$dzien]['liczba']++;
            if ($w['download_kbps'] !== null && $w['download_kbps'] !== '') {
                $grupy[$dzien]['download'][] = (int) $w['download_kbps'];
            }
            if ($w['upload_kbps'] !== null && $w['upload_kbps'] !== '') {
                $grupy[$dzien]['upload'][] = (int) $w['upload_kbps'];
            }
            if ($w['latency_ms'] !== null && $w['latency_ms'] !== '') {
                $grupy[$dzien]['latency'][] = (float) $w['latency_ms'];
            }
        }

        krsort($grupy);
        $wynik = [];
        foreach ($grupy as $g) {
            $wynik[] = [
                'dzien'             => $g['dzien'],
                'liczba_testow'     => $g['liczba'],
                'avg_download_kbps' => self::srednia($g['download']),
                'avg_upload_kbps'   => self::srednia($g['upload']),
                'avg_latency_ms'    => self::srednia($g['latency']),
            ];
        }

        return $wynik;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wierszeWOknie(int $oknoDni, string $cidrContains = '', bool $tylkoAs = true): array
    {
        $od = null;
        if ($oknoDni > 0) {
            $od = gmdate('Y-m-d H:i:s', time() - ($oknoDni * 86400));
        }

        $lista = $this->model->wszystkieOd($od);
        if ($tylkoAs) {
            $lista = array_values(array_filter(
                $lista,
                fn (array $w): bool => $this->matcher->czyNaszeIp((string) ($w['ip'] ?? '')),
            ));
        }

        $cidrContains = trim($cidrContains);
        if ($cidrContains === '') {
            return $lista;
        }

        $wynik = [];
        foreach ($lista as $w) {
            $cidr = self::cidrZIp((string) ($w['ip'] ?? ''));
            $ip   = (string) ($w['ip'] ?? '');
            if (($cidr !== null && str_contains($cidr, $cidrContains)) || str_contains($ip, $cidrContains)) {
                $wynik[] = $w;
            }
        }

        return $wynik;
    }

    private static function normalizujBool(mixed $raw, bool $domyslne): bool
    {
        if (is_array($raw)) {
            $raw = end($raw);
        }
        if ($raw === null || $raw === '') {
            return $domyslne;
        }
        if (is_bool($raw)) {
            return $raw;
        }
        $s = strtolower(trim((string) $raw));
        if (in_array($s, ['1', 'true', 'tak', 'on', 'yes'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'nie', 'off', 'no'], true)) {
            return false;
        }

        return $domyslne;
    }

    private static function normalizujMbps(mixed $raw, int $domyslne): int
    {
        if ($raw === null || $raw === '') {
            return $domyslne;
        }
        if (! is_numeric($raw)) {
            return $domyslne;
        }
        $n = (int) $raw;
        if ($n < 0) {
            return 0;
        }

        return min(100_000, $n);
    }

    private static function czyPowyzejProgu(?int $downKbps, ?int $upKbps, int $minDownKbps, int $minUpKbps): bool
    {
        if ($minDownKbps <= 0 && $minUpKbps <= 0) {
            return false;
        }
        if ($minDownKbps > 0 && $downKbps !== null && $downKbps >= $minDownKbps) {
            return true;
        }
        if ($minUpKbps > 0 && $upKbps !== null && $upKbps >= $minUpKbps) {
            return true;
        }

        return false;
    }

    private static function peakRatio(?int $max, ?float $avg): ?float
    {
        if ($max === null || $avg === null || $avg <= 0.0) {
            return null;
        }

        return $max / $avg;
    }

    /**
     * @param list<array<string, mixed>> $wiersze
     */
    private static function sortujAgregaty(array &$wiersze, string $sort): void
    {
        usort($wiersze, static function (array $a, array $b) use ($sort): int {
            $cmp = match ($sort) {
                'download' => ((int) ($b['max_download_kbps'] ?? 0)) <=> ((int) ($a['max_download_kbps'] ?? 0)),
                'upload'   => ((int) ($b['max_upload_kbps'] ?? 0)) <=> ((int) ($a['max_upload_kbps'] ?? 0)),
                'data'     => strcmp((string) ($b['ostatni_test_at'] ?? ''), (string) ($a['ostatni_test_at'] ?? '')),
                default    => ((int) ($b['liczba_testow'] ?? 0)) <=> ((int) ($a['liczba_testow'] ?? 0)),
            };
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['cidr'] ?? ''), (string) ($b['cidr'] ?? ''));
        });
    }

    /**
     * @param list<array<string, mixed>> $testy
     */
    private static function sortujTesty(array &$testy, string $sort): void
    {
        usort($testy, static function (array $a, array $b) use ($sort): int {
            $cmp = match ($sort) {
                'upload' => ((int) ($b['upload_kbps'] ?? 0)) <=> ((int) ($a['upload_kbps'] ?? 0)),
                'data'   => strcmp((string) ($b['zaobserwowano_at'] ?? ''), (string) ($a['zaobserwowano_at'] ?? '')),
                default  => ((int) ($b['download_kbps'] ?? 0)) <=> ((int) ($a['download_kbps'] ?? 0)),
            };
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($b['zaobserwowano_at'] ?? ''), (string) ($a['zaobserwowano_at'] ?? ''));
        });
    }

    /**
     * @param list<int|float> $wartosci
     */
    private static function srednia(array $wartosci): ?float
    {
        if ($wartosci === []) {
            return null;
        }

        return array_sum($wartosci) / count($wartosci);
    }

    /**
     * @param list<int> $wartosci
     */
    private static function medianaInt(array $wartosci): ?int
    {
        if ($wartosci === []) {
            return null;
        }
        sort($wartosci, SORT_NUMERIC);
        $n = count($wartosci);
        $srodek = (int) floor(($n - 1) / 2);

        return (int) $wartosci[$srodek];
    }

    /**
     * @param list<float> $wartosci
     */
    private static function medianaFloat(array $wartosci): ?float
    {
        if ($wartosci === []) {
            return null;
        }
        sort($wartosci, SORT_NUMERIC);
        $n = count($wartosci);
        $srodek = (int) floor(($n - 1) / 2);

        return (float) $wartosci[$srodek];
    }

    private ?string $portalOstatniBlad = null;

    private function pobierzPortalCsv(): ?string
    {
        if ($this->portalCsvCache !== null) {
            return $this->portalCsvCache;
        }

        $wynik = $this->portal->pobierzCsvRaport();
        if (! $wynik['ok']) {
            $this->portalOstatniBlad = (string) $wynik['error'];
            $this->portalCsvCache   = null;

            return null;
        }

        $this->portalOstatniBlad = null;
        $this->portalCsvCache    = $wynik['csv'];

        return $this->portalCsvCache;
    }

    /**
     * @return list<string>
     */
    private function plikiDoImportu(): array
    {
        if ($this->hasFixture()) {
            return [$this->fixturePath];
        }
        if (! $this->hasCsvDir()) {
            return [];
        }

        $maski = array_merge(
            glob($this->csvDir . '/*.csv') ?: [],
            glob($this->csvDir . '/*.CSV') ?: [],
        );
        $pliki = [];
        foreach (array_unique($maski) as $plik) {
            if (is_file($plik) && is_readable($plik)) {
                $pliki[] = $plik;
            }
        }
        sort($pliki);

        return $pliki;
    }

    /**
     * @param array{
     *   test_id: string,
     *   ip: string,
     *   zaobserwowano_at: string,
     *   download_kbps: ?int,
     *   upload_kbps: ?int,
     *   latency_ms: ?float,
     *   isp: ?string,
     *   platform: ?string,
     *   server_name: ?string,
     *   raw: array<string, string>
     * } $wiersz
     */
    private function zapiszWiersz(array $wiersz, string $zrodloDanych): void
    {
        $rawJson = json_encode($wiersz['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->model->upsertPoTestId($wiersz['test_id'], [
            'ip'               => $wiersz['ip'],
            'zaobserwowano_at' => $wiersz['zaobserwowano_at'],
            'download_kbps'    => $wiersz['download_kbps'],
            'upload_kbps'      => $wiersz['upload_kbps'],
            'latency_ms'       => $wiersz['latency_ms'],
            'isp'              => $wiersz['isp'],
            'platform'         => $wiersz['platform'],
            'server_name'      => $wiersz['server_name'],
            'raw_json'         => $rawJson === false ? null : $rawJson,
            'zrodlo_danych'    => $zrodloDanych,
        ]);
    }
}
