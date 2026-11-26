<?php

namespace App\Libraries\Enrichment;

use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\AsMatcher;
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
    private AsMatcher $matcher;
    private PortalClient $portal;
    private string $fixturePath;
    private string $csvDir;

    /** @var string|null CSV z portalu zbuforowany między checksumZrodel() a sync() */
    private ?string $portalCsvCache = null;

    public function __construct(
        ?Parser $parser = null,
        ?EnrichmentSpeedtestModel $model = null,
        ?AsMatcher $matcher = null,
        ?string $fixturePath = null,
        ?string $csvDir = null,
        ?PortalClient $portal = null,
    ) {
        $this->parser      = $parser ?? new Parser();
        $this->model       = $model ?? model(EnrichmentSpeedtestModel::class);
        $this->matcher     = $matcher ?? new AsMatcher();
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
        $wiersze  = $this->parser->parsujCsv($tresc);
        $zapisano = 0;
        $obce     = 0;
        $doZapisu = [];
        foreach ($wiersze as $wiersz) {
            if (! $this->matcher->czyNaszeIp($wiersz['ip'])) {
                $obce++;
                continue;
            }
            $doZapisu[] = $this->wierszDoZapisu($wiersz, $zrodloDanych);
            $zapisano++;
        }
        // batch upsert (issue #448): jedno INSERT … ON CONFLICT na partię zamiast per wiersz
        $this->model->upsertWiele($doZapisu);

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
    /**
     * Domyślne top N (opcja mcs.speedtest.topN; domyślnie DOMYSLNY_TOP_N).
     */
    public static function domyslnyTopN(): int
    {
        return max(1, min(100, \App\Libraries\OpcjeMcs::int('mcs.speedtest.topN')));
    }

    private static function opcjaNieujemna(string $klucz, int $domyslna): int
    {
        $v = \App\Libraries\OpcjeMcs::int($klucz);

        return $v >= 0 ? $v : $domyslna;
    }

    public static function parsujFiltry(array $get): array
    {
        $oknoRaw = $get['okno'] ?? 7;
        $okno    = is_numeric($oknoRaw) ? (int) $oknoRaw : 7;
        if (! in_array($okno, self::OKNA, true)) {
            $okno = 7;
        }

        $domDown = self::opcjaNieujemna('mcs.speedtest.minDownloadMbps', self::DOMYSLNY_MIN_DOWNLOAD_MBPS);
        $domUp   = self::opcjaNieujemna('mcs.speedtest.minUploadMbps', self::DOMYSLNY_MIN_UPLOAD_MBPS);
        $minDown = self::normalizujMbps($get['min_download_mbps'] ?? $domDown, $domDown);
        // Alias literówki mbbs → mbps (form/query).
        $minUpRaw = $get['min_upload_mbps'] ?? $get['min_upload_mbbs'] ?? $domUp;
        $minUp    = self::normalizujMbps($minUpRaw, $domUp);

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

        $topNDefault = $wszystkie ? 100 : self::domyslnyTopN();
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

        return $this->model->dlaCidr(strtolower($cidr), $limit);
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
            return $this->model->liczbaDlaCidr($cidr);
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
        // agregacja w SQL (issue #448): GROUP BY cidr + AVG/MAX/mediana w WHERE, bez limitu wierszy
        $wiersze = $this->model->agregatyPerCidr($this->odDlaOkna($oknoDni), $this->grupyCidrAs($tylkoAs), $cidrContains);

        $wynik = [];
        foreach ($wiersze as $w) {
            $avgDown  = $w['avg_download_kbps'] !== null ? (float) $w['avg_download_kbps'] : null;
            $avgUp    = $w['avg_upload_kbps'] !== null ? (float) $w['avg_upload_kbps'] : null;
            $avgLat   = $w['avg_latency_ms'] !== null ? (float) $w['avg_latency_ms'] : null;
            $maxDown  = $w['max_download_kbps'] !== null ? (int) $w['max_download_kbps'] : null;
            $maxUp    = $w['max_upload_kbps'] !== null ? (int) $w['max_upload_kbps'] : null;
            $ostatni  = ($w['ostatni_test_at'] ?? '') !== '' && $w['ostatni_test_at'] !== null ? (string) $w['ostatni_test_at'] : null;
            $wynik[] = [
                'cidr'                => (string) $w['cidr'],
                'liczba_testow'       => (int) $w['liczba_testow'],
                'avg_download_kbps'   => $avgDown,
                'p50_download_kbps'   => $w['p50_download_kbps'] !== null ? (int) $w['p50_download_kbps'] : null,
                'avg_upload_kbps'     => $avgUp,
                'p50_upload_kbps'     => $w['p50_upload_kbps'] !== null ? (int) $w['p50_upload_kbps'] : null,
                'avg_latency_ms'      => $avgLat,
                'p50_latency_ms'      => $w['p50_latency_ms'] !== null ? (float) $w['p50_latency_ms'] : null,
                'max_download_kbps'   => $maxDown,
                'max_upload_kbps'     => $maxUp,
                'peak_download_ratio' => self::peakRatio($maxDown, $avgDown),
                'peak_upload_ratio'   => self::peakRatio($maxUp, $avgUp),
                'ostatni_test_at'     => $ostatni,
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
        $topN      = max(1, min($topNMax, (int) ($filtry['top_n'] ?? ($wszystkie ? 100 : self::domyslnyTopN()))));
        $minDownK  = $minDown > 0 ? $minDown * 1000 : 0;
        $minUpK    = $minUp > 0 ? $minUp * 1000 : 0;

        // agregacja w SQL (issue #448): statystyki per CIDR i top-N w WHERE, bez limitu wierszy
        $od       = $this->odDlaOkna($okno);
        $grupy    = $this->grupyCidrAs($tylkoAs);
        $perCidr  = $this->model->anomaliePerCidr($od, $grupy, $cidr, $minDownK, $minUpK);
        $top      = $this->model->topTestySql($od, $grupy, $cidr, $minDownK, $minUpK, $wszystkie, $sort, $topN);

        $powyzej   = 0;
        $pasujacych = 0;
        $cidrPeaki = [];
        foreach ($perCidr as $g) {
            $liczbaPowyzej = (int) $g['liczba_powyzej'];
            $powyzej      += $liczbaPowyzej;
            $pasujacych   += $wszystkie ? (int) $g['liczba_testow'] : $liczbaPowyzej;
            if ($liczbaPowyzej <= 0) {
                continue;
            }
            $avgDown = $g['avg_download_kbps'] !== null ? (float) $g['avg_download_kbps'] : null;
            $avgUp   = $g['avg_upload_kbps'] !== null ? (float) $g['avg_upload_kbps'] : null;
            $maxDown = $g['max_download_kbps'] !== null ? (int) $g['max_download_kbps'] : null;
            $maxUp   = $g['max_upload_kbps'] !== null ? (int) $g['max_upload_kbps'] : null;
            $ostatni = ($g['ostatni_test_at'] ?? '') !== '' && $g['ostatni_test_at'] !== null ? (string) $g['ostatni_test_at'] : null;
            $cidrPeaki[] = [
                'cidr'                => (string) $g['cidr'],
                'liczba_testow'       => (int) $g['liczba_testow'],
                'max_download_kbps'   => $maxDown,
                'max_upload_kbps'     => $maxUp,
                'avg_download_kbps'   => $avgDown,
                'avg_upload_kbps'     => $avgUp,
                'peak_download_ratio' => self::peakRatio($maxDown, $avgDown),
                'peak_upload_ratio'   => self::peakRatio($maxUp, $avgUp),
                'liczba_powyzej'      => $liczbaPowyzej,
                'ostatni_test_at'     => $ostatni,
            ];
        }
        self::sortujAgregaty($cidrPeaki, $sort === 'testy' ? 'download' : $sort);

        $topTesty = [];
        foreach ($top as $w) {
            $topTesty[] = [
                'test_id'          => (string) $w['test_id'],
                'cidr'             => (string) $w['cidr'],
                'download_kbps'    => $w['download_kbps'] !== null ? (int) $w['download_kbps'] : null,
                'upload_kbps'      => $w['upload_kbps'] !== null ? (int) $w['upload_kbps'] : null,
                'latency_ms'       => $w['latency_ms'] !== null ? (float) $w['latency_ms'] : null,
                'zaobserwowano_at' => (string) ($w['zaobserwowano_at'] ?? ''),
            ];
        }

        return [
            'liczba_powyzej'    => $powyzej,
            'liczba_pasujacych' => $pasujacych,
            'top_testy'         => $topTesty,
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
        // KPI w SQL (issue #448) — bez pobierania wierszy i bez limitu 10 000
        $w = $this->model->kpiWOkie($this->odDlaOkna($oknoDni), $this->grupyCidrAs($tylkoAs), $cidrContains);

        return [
            'okno_dni'          => $oknoDni,
            'liczba_testow'     => (int) ($w['liczba_testow'] ?? 0),
            'liczba_cidr'       => (int) ($w['liczba_cidr'] ?? 0),
            'avg_download_kbps' => ($w['avg_download_kbps'] ?? null) !== null ? (float) $w['avg_download_kbps'] : null,
            'p50_download_kbps' => ($w['p50_download_kbps'] ?? null) !== null ? (int) $w['p50_download_kbps'] : null,
            'avg_upload_kbps'   => ($w['avg_upload_kbps'] ?? null) !== null ? (float) $w['avg_upload_kbps'] : null,
            'p50_upload_kbps'   => ($w['p50_upload_kbps'] ?? null) !== null ? (int) $w['p50_upload_kbps'] : null,
            'avg_latency_ms'    => ($w['avg_latency_ms'] ?? null) !== null ? (float) $w['avg_latency_ms'] : null,
            'p50_latency_ms'    => ($w['p50_latency_ms'] ?? null) !== null ? (float) $w['p50_latency_ms'] : null,
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
        // agregacja dzienna w SQL (issue #448), najnowszy dzień pierwszy (ORDER BY dzien DESC)
        $wiersze = $this->model->agregatyDzienneSql($this->odDlaOkna($oknoDni), $this->grupyCidrAs($tylkoAs), $cidrContains);

        $wynik = [];
        foreach ($wiersze as $w) {
            $wynik[] = [
                'dzien'             => (string) $w['dzien'],
                'liczba_testow'     => (int) $w['liczba_testow'],
                'avg_download_kbps' => ($w['avg_download_kbps'] ?? null) !== null ? (float) $w['avg_download_kbps'] : null,
                'avg_upload_kbps'   => ($w['avg_upload_kbps'] ?? null) !== null ? (float) $w['avg_upload_kbps'] : null,
                'avg_latency_ms'    => ($w['avg_latency_ms'] ?? null) !== null ? (float) $w['avg_latency_ms'] : null,
            ];
        }

        return $wynik;
    }

    /**
     * Data OD dla okna dni (null = cała historia).
     */
    private function odDlaOkna(int $oknoDni): ?string
    {
        return $oknoDni > 0 ? gmdate('Y-m-d H:i:s', time() - ($oknoDni * 86400)) : null;
    }

    /**
     * Grupy CIDR filtra AS dla SQL — null gdy tylko_as=false; pusta grupa gdy AS wyłączone
     * (czyNaszeIp zwraca wtedy false dla każdego IP, więc wynik ma być pusty).
     *
     * @return list<list<string>>|null
     */
    private function grupyCidrAs(bool $tylkoAs): ?array
    {
        if (! $tylkoAs) {
            return null;
        }
        if (! $this->matcher->jestWlaczone()) {
            return [[]];
        }
        $cidry = array_values(array_filter($this->matcher->cidry(), 'is_string'));

        return $cidry === [] ? [[]] : [$cidry];
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
     *
     * @return array<string, mixed>
     */
    private function wierszDoZapisu(array $wiersz, string $zrodloDanych): array
    {
        $rawJson = json_encode($wiersz['raw'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return [
            'test_id'          => $wiersz['test_id'],
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
        ];
    }
}
