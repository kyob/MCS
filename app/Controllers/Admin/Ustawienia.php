<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\DashboardFiltry;
use App\Libraries\OpcjeMcs;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Libraries\Siec\RevDns;
use App\Models\IncydentModel;
use App\Models\UstawieniaModel;

/**
 * Ustawienia MCS — m.in. matchowanie IP do AS198050 („nasze IP”).
 */
class Ustawienia extends BaseController
{
    public function index()
    {
        $model   = model(UstawieniaModel::class);
        $matcher = new As198050Matcher(null, $model);
        $revDns  = new RevDns($model);
        $dash    = (new DashboardFiltry($model))->domyslne();
        $listy   = $this->listyFiltrow($dash);

        return view('admin/ustawienia/index', [
            'title'              => 'Ustawienia — MCS',
            'navAktywny'         => 'ustawienia',
            'email'              => (string) session()->get('mcs_user_email'),
            'rola'               => (string) session()->get('mcs_user_rola'),
            'komunikat'          => session()->getFlashdata('komunikat'),
            'blad'               => session()->getFlashdata('blad'),
            'asWlaczone'         => $matcher->jestWlaczone(),
            'asCidryTekst'       => $matcher->cidryJakoTekst(),
            'asAsn'              => As198050Matcher::asn(),
            'asDomyslneCidry'    => As198050Matcher::DOMYSLNE_CIDRY,
            'revDnsWlaczone'     => $revDns->jestWlaczone(),
            'dashboardOkno'      => $dash['okno'],
            'dashboardZrodlo'    => $dash['zrodlo'],
            'dashboardKategoria' => $dash['kategoria'],
            'dashboardPort'      => $dash['port'],
            'dashboardWaga'      => $dash['minWaga'],
            'dashboardMinZrodel' => $dash['minZrodel'],
            'zrodla'             => $listy['zrodla'],
            'kategorie'          => $listy['kategorie'],
        ]);
    }

    public function zapisz()
    {
        $wlaczone    = $this->request->getPost('as198050_wlaczone') === '1';
        $cidryRaw    = (string) $this->request->getPost('as198050_cidry');
        $revDnsWlacz = $this->request->getPost('pokazuj_rev_dns') === '1';

        $cidr    = new CidrMatcher();
        $lista   = $cidr->parsujListe($cidryRaw);
        $zle     = $cidr->niepoprawne($lista);

        if ($zle !== []) {
            return redirect()->to('/admin/ustawienia')->with(
                'blad',
                'Niepoprawne CIDR: ' . implode(', ', $zle)
                . '. Podaj adresy w formie 1.2.3.0/24 (IPv4) lub 2a0f:1700::/29 (IPv6), po jednym w linii.',
            );
        }

        $model = model(UstawieniaModel::class);
        $ok1   = $model->ustaw(As198050Matcher::KLUCZ_WLACZONE, $wlaczone ? '1' : '0');
        $ok2   = $model->ustaw(As198050Matcher::KLUCZ_CIDRY, implode("\n", $lista));
        $ok3   = $model->ustaw(RevDns::KLUCZ_WLACZONE, $revDnsWlacz ? '1' : '0');

        $dashLib = new DashboardFiltry($model);
        $post    = $this->request->getPost();
        $dash    = $dashLib->zFormularzaUstawien(is_array($post) ? $post : []);
        $okDash  = true;
        foreach ($dashLib->doUstawien($dash) as $klucz => $wartosc) {
            if (! $model->ustaw($klucz, $wartosc)) {
                $okDash = false;
            }
        }

        if (! $ok1 || ! $ok2 || ! $ok3 || ! $okDash) {
            return redirect()->to('/admin/ustawienia')->with(
                'blad',
                'Nie udało się zapisać ustawień (sprawdź migrację mcs_ustawienia).',
            );
        }

        $ile = count($lista);
        $czesci = [];
        $czesci[] = $wlaczone
            ? "AS198050 włączone ({$ile} prefiks(ów))"
            : "AS198050 wyłączone ({$ile} prefiks(ów) zachowanych)";
        $czesci[] = $revDnsWlacz ? 'reverse DNS włączone' : 'reverse DNS wyłączone';
        $czesci[] = 'domyślne filtry przeglądu (okno ' . (int) $dash['okno'] . ' dni)';

        return redirect()->to('/admin/ustawienia')->with(
            'komunikat',
            'Zapisano: ' . implode('; ', $czesci) . '.',
        );
    }

    /**
     * Przywraca listę prefiksów do seeda RIPE (nie zmienia przełącznika włącz/wyłącz
     * poza formularzem — ustawia wartości i zapisuje).
     */
    public function przywrocDomyslne()
    {
        $model = model(UstawieniaModel::class);
        $ok    = $model->ustaw(
            As198050Matcher::KLUCZ_CIDRY,
            implode("\n", As198050Matcher::DOMYSLNE_CIDRY),
        );

        if (! $ok) {
            return redirect()->to('/admin/ustawienia')->with(
                'blad',
                'Nie udało się przywrócić domyślnych prefiksów.',
            );
        }

        return redirect()->to('/admin/ustawienia')->with(
            'komunikat',
            'Przywrócono domyślne prefiksy AS198050 (RIPE). Sprawdź listę i kliknij „Zapisz ustawienia”, jeśli zmieniasz też przełącznik.',
        );
    }

    /**
     * Opcje strojenia (progi, limity, TTL, timeouty, ASN).
     * Typ secret (np. klucz SNDS) nie jest w tym formularzu — osobny UI.
     */
    public function opcje()
    {
        OpcjeMcs::ustawModel(model(UstawieniaModel::class));

        return view('admin/ustawienia/opcje', [
            'title'      => 'Opcje strojenia — MCS',
            'navAktywny' => 'ustawienia',
            'email'      => (string) session()->get('mcs_user_email'),
            'rola'       => (string) session()->get('mcs_user_rola'),
            'komunikat'  => session()->getFlashdata('komunikat'),
            'blad'       => session()->getFlashdata('blad'),
            'bledy'      => session()->getFlashdata('bledyOpcji') ?? [],
            'grupy'      => OpcjeMcs::doWidoku(),
        ]);
    }

    public function zapiszOpcje()
    {
        $post     = $this->request->getPost();
        $post     = is_array($post) ? $post : [];
        $wartosci = is_array($post['opcja'] ?? null) ? $post['opcja'] : [];
        $przywroc = is_array($post['przywroc'] ?? null) ? array_values(array_map('strval', array_keys($post['przywroc']))) : [];

        $wynik = OpcjeMcs::zapisz(model(UstawieniaModel::class), $wartosci, $przywroc);
        if (! $wynik['ok']) {
            return redirect()->to('/admin/ustawienia/opcje')
                ->with('blad', 'Nie zapisano opcji — popraw błędy.')
                ->with('bledyOpcji', $wynik['bledy']);
        }

        return redirect()->to('/admin/ustawienia/opcje')->with(
            'komunikat',
            'Zapisano opcje (zmienionych: ' . $wynik['zmienione'] . ').',
        );
    }

    /**
     * @param array<string, mixed> $dash
     *
     * @return array{zrodla: list<string>, kategorie: list<string>}
     */
    private function listyFiltrow(array $dash): array
    {
        $zrodla    = [];
        $kategorie = [];
        try {
            $inc = model(IncydentModel::class);
            $zrodla    = $inc->widoczneZrodla();
            $kategorie = $inc->widoczneKategorie();
        } catch (\Throwable) {
            // brak tabeli / indeksu
        }

        $wybraneZ = is_string($dash['zrodlo'] ?? null) ? (string) $dash['zrodlo'] : '';
        if ($wybraneZ !== '' && ! in_array($wybraneZ, $zrodla, true)) {
            $zrodla[] = $wybraneZ;
            sort($zrodla);
        }
        $wybraneK = is_string($dash['kategoria'] ?? null) ? (string) $dash['kategoria'] : '';
        if ($wybraneK !== '' && ! in_array($wybraneK, $kategorie, true)) {
            $kategorie[] = $wybraneK;
            sort($kategorie);
        }

        return ['zrodla' => $zrodla, 'kategorie' => $kategorie];
    }
}
