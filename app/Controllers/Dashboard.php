<?php

namespace App\Controllers;

use App\Libraries\Siec\As198050Matcher;
use App\Models\IncydentModel;

/**
 * Przegląd / korelacja indeksu mcs_incydenty — problematyczne IP, KPI, ostatnie skróty.
 */
class Dashboard extends BaseController
{
    private const OKNA = [1, 7, 14, 30, 90];

    /** Filtr sieci: wszystkie | nasze (AS198050) | obce */
    private const SIECI = ['', 'nasze', 'obce'];

    public function index()
    {
        $okno = (int) $this->request->getGet('okno');
        if (! in_array($okno, self::OKNA, true)) {
            $okno = 7;
        }

        $zrodlo = $this->request->getGet('zrodlo');
        $zrodlo = is_string($zrodlo) && $zrodlo !== '' ? $zrodlo : null;

        $ip = $this->request->getGet('ip');
        $ip = is_string($ip) ? trim($ip) : '';
        if ($ip !== '' && ! filter_var($ip, FILTER_VALIDATE_IP)) {
            return view('dashboard/index', $this->daneWidoku([
                'okno'              => $okno,
                'filtrZrodlo'       => $zrodlo,
                'filtrIp'           => $ip,
                'filtrMinWaga'      => null,
                'filtrMinZrodel'    => 1,
                'filtrSiec'         => '',
                'asMatchWlaczone'   => false,
                'blad'              => 'Nieprawidłowy adres IP w filtrze.',
                'kpi'               => $this->pusteKpi($okno),
                'topIp'             => [],
                'ostatnie'          => [],
                'zrodla'            => model(IncydentModel::class)->widoczneZrodla(),
            ]));
        }
        $ip = $ip !== '' ? $ip : null;

        $minWaga = $this->request->getGet('waga');
        $minWaga = is_string($minWaga) && isset(IncydentModel::rangaWag()[$minWaga]) ? $minWaga : null;

        $minZrodel = (int) $this->request->getGet('min_zrodel');
        if ($minZrodel < 1) {
            $minZrodel = 1;
        }
        if ($minZrodel > 5) {
            $minZrodel = 5;
        }

        $filtrSiec = $this->request->getGet('siec');
        $filtrSiec = is_string($filtrSiec) && in_array($filtrSiec, self::SIECI, true) ? $filtrSiec : '';

        $matcher         = new As198050Matcher();
        $asMatchWlaczone = $matcher->jestWlaczone();
        if (! $asMatchWlaczone) {
            $filtrSiec = '';
        }

        $model   = model(IncydentModel::class);
        $topIp   = $model->topProblematyczneIp($okno, $zrodlo, $ip, $minWaga, $minZrodel, 50);
        $ostatnie = $model->ostatnieIncydenty($okno, $zrodlo, $ip, $minWaga, 40);

        $topIp    = $this->oznaczIFiltrujIp($topIp, 'ip', $matcher, $filtrSiec);
        $ostatnie = $this->oznaczIFiltrujIp($ostatnie, 'podmiot', $matcher, $filtrSiec);

        return view('dashboard/index', $this->daneWidoku([
            'okno'            => $okno,
            'filtrZrodlo'     => $zrodlo,
            'filtrIp'         => $ip,
            'filtrMinWaga'    => $minWaga,
            'filtrMinZrodel'  => $minZrodel,
            'filtrSiec'       => $filtrSiec,
            'asMatchWlaczone' => $asMatchWlaczone,
            'blad'            => null,
            'kpi'             => $model->kpiPrzegladu($okno, $zrodlo, $ip),
            'topIp'           => $topIp,
            'ostatnie'        => $ostatnie,
            'zrodla'          => $model->widoczneZrodla(),
        ]));
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

    /**
     * @param list<array<string, mixed>> $wiersze
     *
     * @return list<array<string, mixed>>
     */
    private function oznaczIFiltrujIp(array $wiersze, string $poleIp, As198050Matcher $matcher, string $filtrSiec): array
    {
        $out = [];
        foreach ($wiersze as $w) {
            $adres = (string) ($w[$poleIp] ?? '');
            $nasze = $adres !== '' && $matcher->czyNaszeIp($adres);
            $w['nasze_ip'] = $nasze;

            if ($filtrSiec === 'nasze' && ! $nasze) {
                continue;
            }
            if ($filtrSiec === 'obce' && $nasze) {
                continue;
            }
            $out[] = $w;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $dane
     *
     * @return array<string, mixed>
     */
    private function daneWidoku(array $dane): array
    {
        return array_merge([
            'title'      => 'Przegląd — MCS',
            'navAktywny' => 'dashboard',
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'flashBlad'  => session()->getFlashdata('blad'),
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
}
