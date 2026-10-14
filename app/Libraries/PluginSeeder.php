<?php

namespace App\Libraries;

use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;

class PluginSeeder
{
    /**
     * Placeholdery źródeł Fazy 1a — bez kodu importera.
     * Pole `opis` jest opcjonalne; gdy podane, upsert aktualizuje je też na istniejących wierszach.
     *
     * @var list<array{zrodlo: string, nazwa: string, opis?: string}>
     */
    public const PLACEHOLDERY = [
        ['zrodlo' => 'shadowserver', 'nazwa' => 'Shadowserver'],
        [
            'zrodlo' => 'play',
            'nazwa'  => 'Play Scrubbing',
            'opis'   => 'Play Ochrona Anty-DDoS — MCS importuje alerty e-mail (IMAP) jako incydenty.',
        ],
        ['zrodlo' => 'snds', 'nazwa' => 'SNDS'],
        ['zrodlo' => 'mojecert', 'nazwa' => 'MojeCert'],
        ['zrodlo' => 'cert_n6', 'nazwa' => 'CERT N6'],
        ['zrodlo' => 'wanguard', 'nazwa' => 'Wanguard'],
        ['zrodlo' => 'cloudflare_botnet', 'nazwa' => 'Cloudflare Botnet'],
        [
            'zrodlo' => 'qrator',
            'nazwa'  => 'Qrator Radar',
            'opis'   => 'Qrator Radar — API płatne / niedostępne w MCS; import wstrzymany, źródło ukryte w UI.',
        ],
        [
            'zrodlo' => 'lms_mapowanie',
            'nazwa'  => 'LMS mapowanie',
            'opis'   => 'Mapowanie IP → komputer (node) i abonent (customer) z LMS; cache w MCS, poza indeksem badge. Host: mcs.lms.baseUrl (np. lms.example.net).',
        ],
        [
            'zrodlo' => 'ismalicious',
            'nazwa'  => 'isMalicious',
            'opis'   => 'Enrichment reputacji IP (api.ismalicious.com/check). Wymaga apiKey+apiSecret. Panel na /ip/{ip}.',
        ],
        [
            'zrodlo' => 'spamrats',
            'nazwa'  => 'SpamRats',
            'opis'   => 'Enrichment DNSBL RATS-All (dyna/noptr/spam/auth). Wymaga mcs.spamrats.apiKey. Panel na /ip/{ip}; poza indeksem badge.',
        ],
        [
            'zrodlo' => 'dnsbl',
            'nazwa'  => 'DNSBL (AS)',
            'opis'   => 'Skan DNSBL własnych IP: codziennie LMS∩AS, raz/tydzień pełne AS. Listy i skan regulujesz w /admin/ustawienia/opcje (grupa DNSBL). UCEPROTECT+SpamCop+DroneBL (+opcjonalny Spamhaus DQS w .env). Incydenty + /dashboard/dnsbl + /ip/{ip}.',
        ],
        [
            'zrodlo' => 'spoofer',
            'nazwa'  => 'CAIDA Spoofer',
            'opis'   => 'Pomiary BCP38 (czy sieć przepuszcza spoofing). Publiczne API CAIDA, filtr ASN (domyślnie AS198050), prefiksy — poza badge hosta.',
        ],
        [
            'zrodlo' => 'speedtest',
            'nazwa'  => 'Speedtest',
            'opis'   => 'Historia testów Speedtest Servers reporting (portal Ookla lub CSV). Filtr CIDR AS198050, agregaty /24 na /dashboard/speedtest (Ookla anonimizuje do *.0), poza indeksem badge.',
        ],
        [
            'zrodlo' => 'abusix',
            'nazwa'  => 'Abusix',
            'opis'   => 'Wymaga płatnej subskrypcji Abusix (AbuseHQ / Guardian Ops). Na planie free konto nie dostaje spraw. Import wstrzymany. Guardian Ops API (sprawy, zdarzenia, klienci); token w Wtyczki → Abusix → Klucz.',
        ],
        [
            'zrodlo' => 'wazuh',
            'nazwa'  => 'Wazuh',
            'opis'   => 'Własna telemetria serwerów (Wazuh SIEM): IP klientów AS198050, które spamują lub atakują nasze serwery (SMTP/SSH/WWW). Eksport przez SSH forced command.',
        ],
        [
            'zrodlo' => 'spamhaus',
            'nazwa'  => 'Spamhaus DROP',
            'opis'   => 'Darmowe listy Spamhaus DROP, DROPv6 i ASN-DROP (JSON, raz na dobę), dopasowane lokalnie do as198050_cidry i ASN. Trafienie = incydent. Stan na /dashboard/spamhaus.',
        ],
    ];

    /** Źródła wymuszane jako wstrzymane + ukryte w UI przy każdym seedzie (np. płatne API). */
    private const WYMUS_WSTRZYMANE_UKRYTE = ['qrator'];

    /** Źródła wymuszane jako wstrzymane przy każdym seedzie, ale widoczne w UI (wymagają płatnej subskrypcji). */
    private const WYMUS_WSTRZYMANE = ['abusix'];

    public function __construct(private readonly ?BaseConnection $db = null)
    {
    }

    /**
     * Dopisuje brakujące źródła. Dla istniejących aktualizuje `opis` (gdy zdefiniowany)
     * oraz wymusza wstrzymanie/ukrycie dla źródeł z WYMUS_WSTRZYMANE_UKRYTE (np. qrator).
     * Nie rusza flag innych źródeł ani health_*.
     *
     * @return array{nowe: list<string>, juz_byly: list<string>, opis_zaktualizowany: list<string>}
     */
    public function zapisz(): array
    {
        $model              = new PluginModel($this->db);
        $nowe               = [];
        $juzByly            = [];
        $opisZaktualizowany = [];

        foreach (self::PLACEHOLDERY as $wiersz) {
            $opis           = isset($wiersz['opis']) ? (string) $wiersz['opis'] : null;
            $wymusUkryte    = in_array($wiersz['zrodlo'], self::WYMUS_WSTRZYMANE_UKRYTE, true);
            $istniejacy     = $model->find($wiersz['zrodlo']);

            if ($istniejacy !== null) {
                $juzByly[] = $wiersz['zrodlo'];
                $aktualizuj = [];
                if ($opis !== null && $opis !== '') {
                    $aktualizuj['opis'] = $opis;
                    $opisZaktualizowany[] = $wiersz['zrodlo'];
                }
                if ($wymusUkryte) {
                    $aktualizuj['import_wstrzymany'] = true;
                    $aktualizuj['ukryty_w_ui']       = true;
                } elseif (in_array($wiersz['zrodlo'], self::WYMUS_WSTRZYMANE, true)) {
                    $aktualizuj['import_wstrzymany'] = true;
                }
                if ($aktualizuj !== []) {
                    $model->update($wiersz['zrodlo'], $aktualizuj);
                }
                continue;
            }

            $insert = [
                'zrodlo'            => $wiersz['zrodlo'],
                'nazwa'             => $wiersz['nazwa'],
                'import_wstrzymany' => true,
                'ukryty_w_ui'       => $wymusUkryte,
            ];
            if ($opis !== null && $opis !== '') {
                $insert['opis'] = $opis;
            }

            $model->insert($insert);
            $nowe[] = $wiersz['zrodlo'];
        }

        return [
            'nowe'               => $nowe,
            'juz_byly'           => $juzByly,
            'opis_zaktualizowany' => $opisZaktualizowany,
        ];
    }
}
