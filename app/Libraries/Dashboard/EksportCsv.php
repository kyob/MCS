<?php

namespace App\Libraries\Dashboard;

use App\Libraries\CsvBezpieczenstwo;
use App\Libraries\DashboardFiltry;
use App\Libraries\Siec\AsMatcher;
use App\Models\IncydentModel;

/**
 * Eksport CSV rankingu problematycznych IP (GET dashboard/eksport.csv) — wydzielony
 * z kontrolera Dashboard (issue #464). Te same filtry co lista problematycznych IP.
 *
 * Wszystkie pola CSV przechodzą przez CsvBezpieczenstwo::pole() (formula injection, issue #429) —
 * wartości mogą pochodzić z zewnętrznych feedów.
 */
final class EksportCsv
{
    /** Limit wierszy eksportu (jak lista, ale bez paginacji). */
    public const LISTA_MAX = 1000;

    private DashboardFiltry $filtryLib;

    private IncydentModel $model;

    private AsMatcher $matcher;

    public function __construct(
        ?DashboardFiltry $filtry = null,
        ?IncydentModel $model = null,
        ?AsMatcher $matcher = null,
    ) {
        $this->filtryLib = $filtry ?? new DashboardFiltry();
        $this->model     = $model ?? model(IncydentModel::class);
        $this->matcher   = $matcher ?? new AsMatcher();
    }

    /**
     * Filtry eksportu rozstrzygnięte z GET (sesja/ustawienia jak w widoku listy).
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
    public function filtry(array $get): array
    {
        return $this->filtryLib->rozstrzygnij($get);
    }

    /**
     * Błąd filtrów eksportu (zły IP / CIDR) albo null, gdy filtry są poprawne.
     *
     * @param array<string, mixed> $filtry
     */
    public function bladFiltrow(array $filtry): ?string
    {
        if (! empty($filtry['bladIp'])) {
            return 'Nieprawidłowy adres IP w filtrze eksportu.';
        }
        if (! empty($filtry['bladCidr'])) {
            return 'Nieprawidłowy CIDR w filtrze eksportu.';
        }

        return null;
    }

    /**
     * Grupy CIDR dla filtra SQL agregacji: prefiksy AS (gdy włączone) oraz opcjonalny CIDR
     * z filtra — AND między grupami, OR w grupie (jak filtrAkceptujIp).
     *
     * @return list<list<string>>
     */
    public function grupyCidr(?string $cidr): array
    {
        $grupy = [];
        if ($this->matcher->jestWlaczone()) {
            $cidryAs = $this->matcher->cidry();
            if ($cidryAs !== []) {
                $grupy[] = $cidryAs;
            }
        }
        $cidr = $cidr !== null ? trim($cidr) : '';
        if ($cidr !== '') {
            $grupy[] = [$cidr];
        }

        return $grupy;
    }

    /**
     * Wiersze rankingu problematycznych IP dla eksportu (limit LISTA_MAX, bez paginacji).
     *
     * @param array<string, mixed> $filtry
     *
     * @return list<array<string, mixed>>
     */
    public function wiersze(array $filtry): array
    {
        $topIp = $this->model->topProblematyczneIp(
            $filtry['okno'],
            $filtry['zrodlo'],
            $filtry['ip'],
            $filtry['minWaga'],
            $filtry['minZrodel'],
            self::LISTA_MAX,
            $this->grupyCidr($filtry['cidr'] ?? null),
            $filtry['kategoria'],
            $filtry['port'],
        );

        return is_array($topIp) ? $topIp : [];
    }

    /**
     * Zawartość CSV (z wierszem nagłówkowym); null gdy nie udało się otworzyć strumienia.
     *
     * @param list<array<string, mixed>> $wiersze
     */
    public function csv(array $wiersze): ?string
    {
        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            return null;
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

        foreach ($wiersze as $wiersz) {
            // Wszystkie pola neutralizowane przed formula injection (dane z zewnętrznych feedów).
            fputcsv($fh, [
                CsvBezpieczenstwo::pole($wiersz['ip'] ?? ''),
                CsvBezpieczenstwo::pole($wiersz['liczba_zrodel'] ?? 0),
                CsvBezpieczenstwo::pole(implode('|', $wiersz['zrodla'] ?? [])),
                CsvBezpieczenstwo::pole(implode('|', $wiersz['kategorie'] ?? [])),
                CsvBezpieczenstwo::pole($wiersz['max_waga'] ?? ''),
                CsvBezpieczenstwo::pole($wiersz['liczba_incydentow'] ?? 0),
                CsvBezpieczenstwo::pole($wiersz['ostatnio_widziane'] ?? ''),
            ]);
        }

        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);

        return $csv !== false ? $csv : '';
    }

    /**
     * Nazwa pliku eksportu: mcs-problematyczne-ip-YYYYmmdd-HHmmss.csv.
     */
    public function nazwaPliku(): string
    {
        return 'mcs-problematyczne-ip-' . date('Ymd-His') . '.csv';
    }
}
