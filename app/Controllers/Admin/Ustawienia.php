<?php

namespace App\Controllers\Admin;

use App\Controllers\BaseController;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Models\UstawieniaModel;

/**
 * Ustawienia MCS — m.in. matchowanie IP do AS198050 („nasze IP”).
 */
class Ustawienia extends BaseController
{
    public function index()
    {
        $matcher = new As198050Matcher(null, model(UstawieniaModel::class));

        return view('admin/ustawienia/index', [
            'title'           => 'Ustawienia — MCS',
            'navAktywny'      => 'ustawienia',
            'email'           => (string) session()->get('mcs_user_email'),
            'rola'            => (string) session()->get('mcs_user_rola'),
            'komunikat'       => session()->getFlashdata('komunikat'),
            'blad'            => session()->getFlashdata('blad'),
            'asWlaczone'      => $matcher->jestWlaczone(),
            'asCidryTekst'    => $matcher->cidryJakoTekst(),
            'asAsn'           => As198050Matcher::ASN,
            'asDomyslneCidry' => As198050Matcher::DOMYSLNE_CIDRY,
        ]);
    }

    public function zapisz()
    {
        $wlaczone = $this->request->getPost('as198050_wlaczone') === '1';
        $cidryRaw = (string) $this->request->getPost('as198050_cidry');

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

        if (! $ok1 || ! $ok2) {
            return redirect()->to('/admin/ustawienia')->with(
                'blad',
                'Nie udało się zapisać ustawień (sprawdź migrację mcs_ustawienia).',
            );
        }

        $ile = count($lista);
        $msg = $wlaczone
            ? "Zapisano matchowanie AS198050: włączone, {$ile} prefiks(ów)."
            : "Zapisano: matchowanie AS198050 wyłączone ({$ile} prefiks(ów) zachowanych).";

        return redirect()->to('/admin/ustawienia')->with('komunikat', $msg);
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
}
