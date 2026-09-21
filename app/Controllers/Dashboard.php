<?php

namespace App\Controllers;

use App\Libraries\DashboardFiltry;
use App\Libraries\Enrichment\SpeedtestSerwis;
use App\Libraries\KolejkaDnia;
use App\Libraries\KontekstIp;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\RevDns;
use App\Models\IncydentModel;
use App\Models\ObserwacjaModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Pager\Pager;

/**
 * Przegląd / korelacja indeksu mcs_incydenty — KPI, trendy, listy IP i skrótów.
 *
 * Gdy matchowanie AS jest włączone w ustawieniach, przegląd pokazuje wyłącznie IP z prefiksów AS.
 */
class Dashboard extends BaseController
{
    private const LISTA_NA_STRONE = 25;

    private const LISTA_MAX = 1000;

    public function index()
    {
        $wyczysc = $this->przekierujWyczysc('/');
        if ($wyczysc !== null) {
            return $wyczysc;
        }

        $ctx = $this->przygotujKontekst();
        $bladFiltrow = $this->bladFiltrow($ctx['filtry']);
        if ($bladFiltrow !== null) {
            return view('dashboard/index', $this->daneWidoku('dashboard', array_merge(
                $this->daneFiltrow($ctx, '/'),
                [
                    'blad'   => $bladFiltrow,
                    'kpi'    => $this->pusteKpi($ctx['filtry']['okno']),
                    'trendy' => $this->pusteTrendy($ctx['filtry']['okno']),
                ],
            )));
        }

        $filtry = $ctx['filtry'];
        $model  = model(IncydentModel::class);
        $trendy = model(ObserwacjaModel::class)->trendyDzienne(
            $filtry['okno'],
            $filtry['zrodlo'],
            $filtry['ip'],
            $filtry['kategoria'],
            $filtry['port'],
            $ctx['akceptujIp'],
        );

        return view('dashboard/index', $this->daneWidoku('dashboard', array_merge(
            $this->daneFiltrow($ctx, '/'),
            [
                'blad'   => null,
                'kpi'    => $model->kpiPrzegladu(
                    $filtry['okno'],
                    $filtry['zrodlo'],
                    $filtry['ip'],
                    $ctx['akceptujIp'],
                    $filtry['kategoria'],
                    $filtry['port'],
                ),
                'trendy' => $trendy,
            ],
        )));
    }

    public function problematyczneIp()
    {
        $wyczysc = $this->przekierujWyczysc('/dashboard/problematyczne-ip');
        if ($wyczysc !== null) {
            return $wyczysc;
        }

        $ctx = $this->przygotujKontekst();
        $bladFiltrow = $this->bladFiltrow($ctx['filtry']);
        if ($bladFiltrow !== null) {
            return view('dashboard/problematyczne_ip', $this->daneWidoku('problematyczne-ip', array_merge(
                $this->daneFiltrow($ctx, 'dashboard/problematyczne-ip'),
                $this->pustaStronaListy(),
                [
                    'blad'           => $bladFiltrow,
                    'revDnsWlaczone' => false,
                    'topIp'          => [],
                ],
            )));
        }

        $filtry = $ctx['filtry'];

        return view('dashboard/problematyczne_ip', $this->daneWidoku(
            'problematyczne-ip',
            $this->daneListyProblematycznych($ctx, $filtry, 'dashboard/problematyczne-ip'),
        ));
    }

    /**
     * Kolejka dnia — preset 48h / high+; GET okno/waga są ignorowane.
     */
    public function kolejka()
    {
        $preset = (new KolejkaDnia())->filtryBazowe();
        $ctx    = $this->przygotujKontekst();
        $filtry = array_merge($ctx['filtry'], $preset, [
            'ipSurowy'   => '',
            'bladIp'     => false,
            'cidr'       => null,
            'cidrSurowy' => '',
            'bladCidr'   => false,
        ]);
        $ctx['filtry']       = $filtry;
        $ctx['queryEksport'] = $this->queryEksport($filtry);

        return view('dashboard/kolejka', $this->daneWidoku(
            'kolejka',
            $this->daneListyProblematycznych($ctx, $filtry, 'dashboard/kolejka', ['trybKolejka' => true]),
        ));
    }

    public function incydenty()
    {
        $wyczysc = $this->przekierujWyczysc('/dashboard/incydenty');
        if ($wyczysc !== null) {
            return $wyczysc;
        }

        $ctx = $this->przygotujKontekst();
        $bladFiltrow = $this->bladFiltrow($ctx['filtry']);
        if ($bladFiltrow !== null) {
            return view('dashboard/incydenty', $this->daneWidoku('incydenty', array_merge(
                $this->daneFiltrow($ctx, 'dashboard/incydenty'),
                $this->pustaStronaListy(),
                [
                    'blad'     => $bladFiltrow,
                    'ostatnie' => [],
                ],
            )));
        }

        $filtry   = $ctx['filtry'];
        $ostatnie = model(IncydentModel::class)->ostatnieIncydenty(
            $filtry['okno'],
            $filtry['zrodlo'],
            $filtry['ip'],
            $filtry['minWaga'],
            self::LISTA_MAX,
            $ctx['akceptujIp'],
            $filtry['kategoria'],
            $filtry['port'],
        );
        $strona = $this->stronaListy($ostatnie, 'dashboard/incydenty');

        return view('dashboard/incydenty', $this->daneWidoku('incydenty', array_merge(
            $this->daneFiltrow($ctx, 'dashboard/incydenty'),
            [
                'blad'          => null,
                'ostatnie'      => $strona['wiersze'],
                'pager'         => $strona['pager'],
                'listaOd'       => $strona['od'],
                'listaDo'       => $strona['do'],
                'listaTotal'    => $strona['total'],
                'listaNaStrone' => self::LISTA_NA_STRONE,
            ],
        )));
    }

    /**
     * Agregaty Speedtest /24 — enrichment poza indeksem/badge.
     * Filtry: okno, progi Mb/s (wysycenie), CIDR contains, sort, tylko AS, wszystkie pomiary.
     */
    public function speedtest()
    {
        $filtry = SpeedtestSerwis::parsujFiltry($this->request->getGet() ?? []);
        $serwis = new SpeedtestSerwis();
        try {
            $kpi      = $serwis->kpi($filtry['okno'], $filtry['cidr'], $filtry['tylko_as']);
            $agregaty = $serwis->agregaty($filtry['okno'], $filtry['cidr'], $filtry['sort'], $filtry['tylko_as']);
            $dzienne  = $serwis->agregatyDzienne($filtry['okno'], $filtry['cidr'], $filtry['tylko_as']);
            $anomalie = $serwis->anomalie($filtry);
        } catch (\Throwable) {
            $kpi = [
                'okno_dni'          => $filtry['okno'],
                'liczba_testow'     => 0,
                'liczba_cidr'       => 0,
                'avg_download_kbps' => null,
                'p50_download_kbps' => null,
                'avg_upload_kbps'   => null,
                'p50_upload_kbps'   => null,
                'avg_latency_ms'    => null,
                'p50_latency_ms'    => null,
            ];
            $agregaty = [];
            $dzienne  = [];
            $anomalie = [
                'liczba_powyzej'    => 0,
                'liczba_pasujacych' => 0,
                'top_testy'         => [],
                'cidr_peaki'        => [],
            ];
        }

        return view('dashboard/speedtest', $this->daneWidoku('speedtest', [
            'filtry'   => $filtry,
            'okno'     => $filtry['okno'],
            'kpi'      => $kpi,
            'agregaty' => $agregaty,
            'dzienne'  => $dzienne,
            'anomalie' => $anomalie,
        ]));
    }

    /**
     * CSV ranking problematycznych IP — te same filtry co lista.
     */
    public function eksportCsv()
    {
        $filtry = $this->parsujFiltry();
        $bladFiltrow = $this->bladFiltrow($filtry, true);
        if ($bladFiltrow !== null) {
            return redirect()->to('/dashboard/problematyczne-ip')->with('blad', $bladFiltrow);
        }

        $matcher    = new As198050Matcher();
        $akceptujIp = $matcher->filtrAkceptujIp($filtry['cidr'] ?? null);

        $topIp = model(IncydentModel::class)->topProblematyczneIp(
            $filtry['okno'],
            $filtry['zrodlo'],
            $filtry['ip'],
            $filtry['minWaga'],
            $filtry['minZrodel'],
            self::LISTA_MAX,
            $akceptujIp,
            $filtry['kategoria'],
            $filtry['port'],
        );

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return redirect()->to('/dashboard/problematyczne-ip')->with('blad', 'Nie udało się przygotować eksportu CSV.');
        }

        fputcsv($fh, [
            'ip',
            'liczba_zrodel',
            'zrodla',
            'kategorie',
            'max_waga',
            'liczba_incydentow',
            'ostatnio_widziane',
        ]);

        foreach ($topIp as $wiersz) {
            fputcsv($fh, [
                (string) ($wiersz['ip'] ?? ''),
                (int) ($wiersz['liczba_zrodel'] ?? 0),
                implode('|', $wiersz['zrodla'] ?? []),
                implode('|', $wiersz['kategorie'] ?? []),
                (string) ($wiersz['max_waga'] ?? ''),
                (int) ($wiersz['liczba_incydentow'] ?? 0),
                (string) ($wiersz['ostatnio_widziane'] ?? ''),
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        $nazwa = 'mcs-problematyczne-ip-' . date('Ymd-His') . '.csv';

        return $this->response
            ->setHeader('Content-Type', 'text/csv; charset=utf-8')
            ->setHeader('Content-Disposition', 'attachment; filename="' . $nazwa . '"')
            ->setBody(($csv !== false ? $csv : ''));
    }

    /**
     * @param list<array<string, mixed>> $wiersze
     *
     * @return list<array<string, mixed>>
     */
    /**
     * Lista problematycznych IP + kontekst/revDNS/paginacja — wspólna dla problematyczne-ip i kolejki.
     *
     * @param array<string, mixed> $ctx
     * @param array<string, mixed> $filtry
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function daneListyProblematycznych(array $ctx, array $filtry, string $sciezka, array $extra = []): array
    {
        $topIp = model(IncydentModel::class)->topProblematyczneIp(
            $filtry['okno'],
            $filtry['zrodlo'],
            $filtry['ip'],
            $filtry['minWaga'],
            $filtry['minZrodel'],
            self::LISTA_MAX,
            $ctx['akceptujIp'],
            $filtry['kategoria'],
            $filtry['port'],
        );
        $topIp  = $this->dolaczKontekstIp($topIp);
        $topIp  = $this->dolaczRevDns($topIp);
        $strona = $this->stronaListy($topIp, $sciezka);

        return array_merge(
            $this->daneFiltrow($ctx, $sciezka),
            [
                'blad'           => null,
                'revDnsWlaczone' => (new RevDns())->jestWlaczone(),
                'topIp'          => $strona['wiersze'],
                'pager'          => $strona['pager'],
                'listaOd'        => $strona['od'],
                'listaDo'        => $strona['do'],
                'listaTotal'     => $strona['total'],
                'listaNaStrone'  => self::LISTA_NA_STRONE,
            ],
            $extra,
        );
    }

    private function dolaczKontekstIp(array $wiersze): array
    {
        $kontekst = new KontekstIp();
        foreach ($wiersze as &$w) {
            $w['kontekst'] = $kontekst->zbuduj($w);
        }
        unset($w);

        return $wiersze;
    }

    /**
     * Reverse DNS tylko z cache (bez cold DNS dla całej listy).
     *
     * @param list<array<string, mixed>> $wiersze
     *
     * @return list<array<string, mixed>>
     */
    private function dolaczRevDns(array $wiersze): array
    {
        $rev  = new RevDns();
        $ips  = [];
        foreach ($wiersze as $w) {
            $ip = (string) ($w['ip'] ?? '');
            if ($ip !== '') {
                $ips[] = $ip;
            }
        }
        $mapa = $rev->mapaZCache($ips);
        foreach ($wiersze as &$w) {
            $ip = (string) ($w['ip'] ?? '');
            $w['rev_dns'] = $mapa[$ip] ?? null;
        }
        unset($w);

        return $wiersze;
    }

    /**
     * Szybkie przejście z nawigacji: /szukaj?ip=…
     */
    public function szukaj()
    {
        $ip = trim((string) $this->request->getGet('ip'));
        if ($ip === '' || ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return redirect()->to('/')->with('blad', 'Podaj prawidłowy adres IP.');
        }

        return redirect()->to('/ip/' . rawurlencode($ip));
    }

    private function przekierujWyczysc(string $sciezka): ?RedirectResponse
    {
        if ((string) $this->request->getGet('wyczysc') === '1') {
            (new DashboardFiltry())->rozstrzygnij(['wyczysc' => '1']);

            return redirect()->to($sciezka);
        }

        return null;
    }

    /**
     * @return array{
     *   filtry: array{
     *     okno: int,
     *     zrodlo: ?string,
     *     ip: ?string,
     *     ipSurowy: string,
     *     minWaga: ?string,
     *     minZrodel: int,
     *     kategoria: ?string,
     *     port: ?int,
     *     bladIp: bool,
     *     cidr: ?string,
     *     cidrSurowy: string,
     *     bladCidr: bool
     *   },
     *   asMatchWlaczone: bool,
     *   akceptujIp: (callable(string): bool)|null,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   podsieciAs: list<string>,
     *   queryEksport: string
     * }
     */
    private function przygotujKontekst(): array
    {
        $filtry          = $this->parsujFiltry();
        $matcher         = new As198050Matcher();
        $asMatchWlaczone = $matcher->jestWlaczone();
        $cidr            = ! empty($filtry['bladCidr']) ? null : ($filtry['cidr'] ?? null);
        $akceptujIp      = $matcher->filtrAkceptujIp(is_string($cidr) ? $cidr : null);
        $model           = model(IncydentModel::class);

        return [
            'filtry'          => $filtry,
            'asMatchWlaczone' => $asMatchWlaczone,
            'akceptujIp'      => $akceptujIp,
            'zrodla'          => $model->widoczneZrodla(),
            'kategorie'       => $model->widoczneKategorie(),
            'podsieciAs'      => $matcher->cidryIpv4(),
            'queryEksport'    => $this->queryEksport($filtry),
        ];
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private function daneFiltrow(array $ctx, string $sciezka): array
    {
        $filtry    = $ctx['filtry'];
        $akcja     = site_url($sciezka);
        $podsieci  = is_array($ctx['podsieciAs'] ?? null) ? $ctx['podsieciAs'] : [];
        $formularz = (new DashboardFiltry())->doFormularza($filtry, $podsieci);

        return [
            'okno'            => $filtry['okno'],
            'filtrZrodlo'     => $filtry['zrodlo'],
            'filtrIp'         => $filtry['bladIp'] ? $filtry['ipSurowy'] : $filtry['ip'],
            'filtrMinWaga'    => $filtry['minWaga'],
            'filtrMinZrodel'  => $filtry['minZrodel'],
            'filtrKategoria'  => $filtry['kategoria'],
            'filtrPort'       => $filtry['port'],
            'filtrPodsiec'    => $formularz['podsiec'],
            'filtrCidr'       => $formularz['cidrPole'],
            'filtrCidrEfekt'  => ! empty($filtry['bladCidr']) ? null : ($filtry['cidr'] ?? null),
            'podsieciAs'      => $podsieci,
            'asMatchWlaczone' => $ctx['asMatchWlaczone'],
            'zrodla'          => $ctx['zrodla'],
            'kategorie'       => $ctx['kategorie'],
            'queryEksport'    => $ctx['queryEksport'],
            'formAkcja'       => $akcja,
            'wyczyscHref'     => $akcja . '?wyczysc=1',
        ];
    }

    /**
     * @param list<array<string, mixed>> $lista
     *
     * @return array{wiersze: list, pager: Pager, od: int, do: int, total: int}
     */
    private function stronaListy(array $lista, string $sciezka): array
    {
        $perPage  = self::LISTA_NA_STRONE;
        $total    = count($lista);
        $ostatnia = $total > 0 ? (int) ceil($total / $perPage) : 1;
        $page     = max(1, (int) $this->request->getGet('page'));
        if ($page > $ostatnia) {
            $page = $ostatnia;
        }
        $offset  = ($page - 1) * $perPage;
        $wiersze = array_slice($lista, $offset, $perPage);
        $pager   = service('pager');
        $pager->store('default', $page, $perPage, $total);
        $pager->setPath($sciezka);

        return [
            'wiersze' => $wiersze,
            'pager'   => $pager,
            'od'      => $total === 0 ? 0 : $offset + 1,
            'do'      => $offset + count($wiersze),
            'total'   => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function pustaStronaListy(): array
    {
        return [
            'pager'         => null,
            'listaOd'       => 0,
            'listaDo'       => 0,
            'listaTotal'    => 0,
            'listaNaStrone' => self::LISTA_NA_STRONE,
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
    private function parsujFiltry(?DashboardFiltry $lib = null): array
    {
        $get = $this->request->getGet();
        if (! is_array($get)) {
            $get = [];
        }

        return ($lib ?? new DashboardFiltry())->rozstrzygnij($get);
    }

    /**
     * @param array<string, mixed> $filtry
     */
    private function bladFiltrow(array $filtry, bool $eksport = false): ?string
    {
        $dopisek = $eksport ? ' eksportu' : '';
        if (! empty($filtry['bladIp'])) {
            return 'Nieprawidłowy adres IP w filtrze' . $dopisek . '.';
        }
        if (! empty($filtry['bladCidr'])) {
            return 'Nieprawidłowy CIDR w filtrze' . $dopisek . '.';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $filtry
     */
    private function queryEksport(array $filtry): string
    {
        return (new DashboardFiltry())->doQuery($filtry);
    }

    /**
     * @param array<string, mixed> $dane
     *
     * @return array<string, mixed>
     */
    private function daneWidoku(string $nav, array $dane): array
    {
        $title = match ($nav) {
            'problematyczne-ip' => 'Problematyczne IP — MCS',
            'kolejka'           => 'Kolejka dnia — MCS',
            'incydenty'         => 'Ostatnie incydenty — MCS',
            'speedtest'         => 'Speedtest — MCS',
            default             => 'Przegląd — MCS',
        };

        return array_merge([
            'title'      => $title,
            'navAktywny' => $nav,
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'flashBlad'  => session()->getFlashdata('blad'),
            'flashOk'    => session()->getFlashdata('komunikat'),
        ], $dane);
    }

    /**
     * @return array{
     *   okno_dni: int,
     *   liczba_incydentow: int,
     *   liczba_ip: int,
     *   ip_wielozrodlowe: int,
     *   critical_high: int,
     *   max_waga: ?string
     * }
     */
    private function pusteKpi(int $okno): array
    {
        return [
            'okno_dni'          => $okno,
            'liczba_incydentow' => 0,
            'liczba_ip'         => 0,
            'ip_wielozrodlowe'  => 0,
            'critical_high'     => 0,
            'max_waga'          => null,
        ];
    }

    /**
     * @return array{
     *   okno_dni: int,
     *   dni: list,
     *   zrodla_suma: array,
     *   max_obserwacje: int,
     *   suma_obserwacji: int
     * }
     */
    private function pusteTrendy(int $okno): array
    {
        return [
            'okno_dni'        => $okno,
            'dni'             => [],
            'zrodla_suma'     => [],
            'max_obserwacje'  => 0,
            'suma_obserwacji' => 0,
        ];
    }
}
