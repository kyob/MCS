<?php

namespace App\Libraries;

use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Karty źródeł na `/ip/{ip}` — skrót WHY (waga, kategoria, kluczowe pola tabeli źródła).
 */
class KartyZrodel
{
    /** @var array<string, list<string>> pola z tabeli źródła pokazywane na karcie */
    private const POLA_KLUCZOWE = [
        'shadowserver' => ['tag', 'port', 'protokol', 'severity'],
        'play'         => ['message_type', 'event_type', 'cidr'],
        'snds'         => ['filtr'],
        'cert_n6'      => ['category', 'name', 'confidence', 'dport', 'product', 'fqdn'],
        'mojecert'     => ['report_type', 'severity', 'target'],
        'wanguard'     => ['anomaly', 'direction', 'severity', 'peak_bits_s', 'mitigated'],
        'spoofer'      => ['routedspoof', 'privatespoof', 'prefix', 'ip_wersja', 'country'],
        'wazuh'        => ['kategoria_wazuh', 'liczba', 'poziom_max', 'hosty_docelowe', 'dzien'],
        'spamhaus'     => ['lista', 'wpis', 'sblid', 'nasz_prefiks', 'zakres'],
    ];

    private BaseConnection $db;
    private SzczegolyZrodla $zrodla;
    private EtykietyUi $etykiety;

    public function __construct(
        ?BaseConnection $db = null,
        ?SzczegolyZrodla $zrodla = null,
        ?EtykietyUi $etykiety = null,
    ) {
        $this->db       = $db ?? Database::connect();
        $this->zrodla   = $zrodla ?? new SzczegolyZrodla($this->db);
        $this->etykiety = $etykiety ?? new EtykietyUi();
    }

    /**
     * @param list<array<string, mixed>> $incydenty wiersze z IncydentModel::dlaPodmiotuIp
     *
     * @return list<array{
     *   incydent_id: int,
     *   zrodlo: string,
     *   zrodlo_etykieta: string,
     *   waga: string,
     *   kategoria: string,
     *   kategoria_etykieta: string,
     *   tytul: string,
     *   port_tekst: string,
     *   ostatnio_widziane: string,
     *   url: string,
     *   pola: list<array{klucz: string, wartosc: string}>,
     *   liczba_obserwacji: int
     * }>
     */
    public function dlaIncydentow(string $ip, array $incydenty): array
    {
        $ip = trim($ip);
        if ($ip === '' || $incydenty === []) {
            return [];
        }

        $ids = [];
        foreach ($incydenty as $inc) {
            $id = (int) ($inc['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }

        $metaObs = $this->metaObserwacji($ids);
        $karty   = [];

        foreach ($incydenty as $inc) {
            $id = (int) ($inc['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $zrodlo = trim((string) ($inc['zrodlo'] ?? ''));
            $waga   = trim((string) ($inc['waga'] ?? ''));
            $kat    = trim((string) ($inc['kategoria'] ?? ''));
            $port   = $inc['port'] ?? null;
            $proto  = $inc['protokol'] ?? null;
            $portTekst = ($port !== null && $port !== '')
                ? (string) $port . ($proto ? '/' . $proto : '')
                : '—';

            $idWZrodle = $metaObs[$id]['id_w_zrodle'] ?? null;
            $wiersz    = ($idWZrodle !== null) ? $this->zrodla->znajdz($zrodlo, $idWZrodle) : null;
            $pola      = $this->polaKluczowe($zrodlo, $wiersz);

            $karty[] = [
                'incydent_id'        => $id,
                'zrodlo'             => $zrodlo,
                'zrodlo_etykieta'    => $this->etykiety->zrodlo($zrodlo),
                'waga'               => $waga,
                'kategoria'          => $kat,
                'kategoria_etykieta' => $this->etykiety->kategoria($kat),
                'tytul'              => (string) ($inc['tytul'] ?? ''),
                'port_tekst'         => $portTekst,
                'ostatnio_widziane'  => (string) ($inc['ostatnio_widziane'] ?? ''),
                'url'                => site_url('ip/' . $ip . '/incydent/' . $id),
                'pola'               => $pola,
                'liczba_obserwacji'  => (int) ($metaObs[$id]['liczba'] ?? 0),
            ];
        }

        return $karty;
    }

    /**
     * @param list<int> $incydentIds
     *
     * @return array<int, array{id_w_zrodle: ?string, liczba: int}>
     */
    private function metaObserwacji(array $incydentIds): array
    {
        $meta = [];
        foreach ($incydentIds as $id) {
            $meta[$id] = ['id_w_zrodle' => null, 'liczba' => 0];
        }

        $wiersze = $this->db->table('mcs_obserwacje')
            ->select('incydent_id, id_w_zrodle, zaobserwowano_at, id')
            ->whereIn('incydent_id', $incydentIds)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->get()
            ->getResultArray();

        if (! is_array($wiersze)) {
            return $meta;
        }

        foreach ($wiersze as $w) {
            $iid = (int) ($w['incydent_id'] ?? 0);
            if (! isset($meta[$iid])) {
                continue;
            }
            $meta[$iid]['liczba']++;
            if ($meta[$iid]['id_w_zrodle'] !== null) {
                continue;
            }
            $kandydat = isset($w['id_w_zrodle']) ? trim((string) $w['id_w_zrodle']) : '';
            if ($kandydat !== '') {
                $meta[$iid]['id_w_zrodle'] = $kandydat;
            }
        }

        return $meta;
    }

    /**
     * @param array<string, mixed>|null $wiersz
     *
     * @return list<array{klucz: string, wartosc: string}>
     */
    private function polaKluczowe(string $zrodlo, ?array $wiersz): array
    {
        if ($wiersz === null) {
            return [];
        }

        $klucze = self::POLA_KLUCZOWE[$zrodlo] ?? [];
        $pola   = [];

        foreach ($klucze as $klucz) {
            $wartosc = $wiersz[$klucz] ?? null;
            if (($wartosc === null || $wartosc === '') && isset($wiersz['payload']) && is_array($wiersz['payload'])) {
                $wartosc = $wiersz['payload'][$klucz] ?? null;
            }
            if ($wartosc === null || $wartosc === '') {
                continue;
            }
            if (is_bool($wartosc)) {
                $wartosc = $wartosc ? 'tak' : 'nie';
            } elseif (is_array($wartosc) || is_object($wartosc)) {
                $wartosc = json_encode($wartosc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $pola[] = [
                'klucz'   => $klucz,
                'wartosc' => (string) $wartosc,
            ];
            if (count($pola) >= 5) {
                break;
            }
        }

        return $pola;
    }
}
