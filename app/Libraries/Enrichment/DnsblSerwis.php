<?php

namespace App\Libraries\Enrichment;

use App\Libraries\IncydentZapis;
use App\Libraries\PluginOpisPolaczenia;
use App\Libraries\Siec\As198050Matcher;
use App\Libraries\Siec\CidrMatcher;
use App\Libraries\WagaV1;
use App\Models\DnsblWierszeModel;
use App\Models\EnrichmentDnsblModel;
use App\Models\EnrichmentLmsModel;
use App\Models\PluginModel;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Skan DNSBL AS: LMS codziennie, pełne AS w dniu asScanWeekday.
 * Zapisuje mcs_dnsbl_wiersze + enrichment + IncydentZapis przy listed.
 */
class DnsblSerwis
{
    private DnsblClient $client;
    private EnrichmentDnsblModel $enrichment;
    private DnsblWierszeModel $wiersze;
    private EnrichmentLmsModel $lms;
    private As198050Matcher $asMatcher;
    private CidrMatcher $cidr;
    private IncydentZapis $zapis;
    private WagaV1 $waga;
    private BaseConnection $db;
    private int $cacheTtl;
    private string $fixturePath;
    private int $asScanWeekday;

    public function __construct(
        ?DnsblClient $client = null,
        ?EnrichmentDnsblModel $enrichment = null,
        ?DnsblWierszeModel $wiersze = null,
        ?EnrichmentLmsModel $lms = null,
        ?As198050Matcher $asMatcher = null,
        ?CidrMatcher $cidr = null,
        ?IncydentZapis $zapis = null,
        ?WagaV1 $waga = null,
        ?BaseConnection $db = null,
        ?int $cacheTtl = null,
        ?string $fixturePath = null,
        ?int $asScanWeekday = null,
    ) {
        $this->client         = $client ?? DnsblClient::fromEnv();
        $this->enrichment     = $enrichment ?? model(EnrichmentDnsblModel::class);
        $this->wiersze        = $wiersze ?? model(DnsblWierszeModel::class);
        $this->lms            = $lms ?? model(EnrichmentLmsModel::class);
        $this->asMatcher      = $asMatcher ?? new As198050Matcher();
        $this->cidr           = $cidr ?? $this->asMatcher->cidrMatcher();
        $this->zapis          = $zapis ?? new IncydentZapis();
        $this->waga           = $waga ?? new WagaV1();
        $this->db             = $db ?? Database::connect();
        $ttl                  = $cacheTtl ?? \App\Libraries\OpcjeMcs::int('mcs.dnsbl.cacheTtl');
        $this->cacheTtl       = max(0, $ttl);
        $this->fixturePath    = $fixturePath ?? trim((string) env('mcs.dnsbl.fixturePath', ''));
        if ($asScanWeekday !== null) {
            $weekday = $asScanWeekday;
        } else {
            try {
                $weekday = \App\Libraries\OpcjeMcs::int('mcs.dnsbl.asScanWeekday');
            } catch (\Throwable) {
                $weekday = (int) env('mcs.dnsbl.asScanWeekday', 0);
            }
        }
        $this->asScanWeekday  = max(0, min(6, $weekday));
    }

    public function hasFixture(): bool
    {
        return $this->fixturePath !== '' && is_readable($this->fixturePath);
    }

    public function tryb(): string
    {
        if ($this->hasFixture()) {
            return 'fixture';
        }

        return 'dns';
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
        $strefy = $this->client->aktywneStrefy();
        $lista  = implode(', ', array_keys($strefy));

        $skanLms = $this->opcjaTak('mcs.dnsbl.skanLms', true);
        $skanAs  = $this->opcjaTak('mcs.dnsbl.skanAs', true);
        $force   = $this->opcjaTekst('mcs.dnsbl.forceZakres', 'auto');

        return PluginOpisPolaczenia::plikIKanal(
            $this->fixturePath,
            'DNSBL multi',
            $lista !== '' ? $lista : '(brak list)',
            $strefy !== [],
            'dns',
            'DNS',
            [
                PluginOpisPolaczenia::pozycja(
                    'Spamhaus DQS',
                    PluginOpisPolaczenia::sekret($this->client->hasSpamhaus()),
                    false,
                    $this->client->hasSpamhaus(),
                ),
                PluginOpisPolaczenia::pozycja(
                    'Skan LMS / AS',
                    ($skanLms ? 'LMS' : '—') . ' / ' . ($skanAs ? 'AS(dzień ' . $this->asScanWeekday . ')' : '—'),
                    false,
                    true,
                ),
                PluginOpisPolaczenia::pozycja(
                    'Wymuszony zakres',
                    $force,
                    $force !== 'auto',
                    true,
                ),
                PluginOpisPolaczenia::pozycja(
                    'Konfiguracja WWW',
                    'admin/ustawienia/opcje (grupa DNSBL)',
                    false,
                    true,
                ),
            ],
        );
    }

    /**
     * Zakres: lms | as | none (nic do skanowania).
     */
    public function wybierzZakres(?int $dzienTygodnia = null): string
    {
        $skanLms = $this->opcjaTak('mcs.dnsbl.skanLms', true);
        $skanAs  = $this->opcjaTak('mcs.dnsbl.skanAs', true);

        $force = $this->opcjaTekst('mcs.dnsbl.forceZakres', 'auto');
        if ($force === '') {
            $force = trim((string) env('mcs.dnsbl.forceZakres', 'auto'));
        }
        if ($force === 'lms') {
            return $skanLms ? 'lms' : 'none';
        }
        if ($force === 'as') {
            return $skanAs ? 'as' : 'none';
        }

        $dzien = $dzienTygodnia ?? (int) gmdate('w');
        if ($dzien === $this->asScanWeekday && $skanAs) {
            return 'as';
        }
        if ($skanLms) {
            return 'lms';
        }
        if ($skanAs) {
            return 'as';
        }

        return 'none';
    }

    /**
     * @return list<string>
     */
    public function zbierzIpDoSkanu(string $zakres): array
    {
        if ($zakres === 'none' || $zakres === '') {
            return [];
        }
        if ($zakres === 'as') {
            return $this->ipZAs();
        }

        return $this->ipZLms();
    }

    private function opcjaTak(string $klucz, bool $domyslnie): bool
    {
        try {
            return \App\Libraries\OpcjeMcs::jestTak($klucz);
        } catch (\Throwable) {
            return $domyslnie;
        }
    }

    private function opcjaTekst(string $klucz, string $domyslnie): string
    {
        try {
            $v = trim(\App\Libraries\OpcjeMcs::tekst($klucz));

            return $v !== '' ? $v : $domyslnie;
        } catch (\Throwable) {
            return $domyslnie;
        }
    }

    /**
     * Pełny przebieg skanu (cron / pobierz).
     *
     * @return array{
     *   ok: bool,
     *   zakres: string,
     *   sprawdzono: int,
     *   listed: int,
     *   incydenty: int,
     *   blad: ?string
     * }
     */
    public function skanuj(?int $importId = null, ?string $zakres = null): array
    {
        $zakres = $zakres ?? $this->wybierzZakres();
        $ips    = $this->zbierzIpDoSkanu($zakres);
        $listed = 0;
        $inc    = 0;
        $ok     = 0;

        foreach ($ips as $ip) {
            $wynik = $this->sprawdzIZapisz($ip, true, $zakres, $importId);
            if (! $wynik['ok']) {
                continue;
            }
            $ok++;
            if (! empty($wynik['wynik']['listed_any'])) {
                $listed++;
            }
            $inc += (int) ($wynik['incydenty'] ?? 0);
        }

        return [
            'ok'         => true,
            'zakres'     => $zakres,
            'sprawdzono' => $ok,
            'listed'     => $listed,
            'incydenty'  => $inc,
            'blad'       => null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function podgladCache(string $ip): ?array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $cache = $this->enrichment->znajdzPoIp($ip);
        if (! $this->enrichment->cacheWazny($cache, $this->cacheTtl) || ! is_array($cache)) {
            return null;
        }

        return $this->wierszDoWyniku($cache);
    }

    /**
     * Lookup pojedynczego IP (panel / CLI). Przy force zapisuje też wiersze źródła + incydenty.
     *
     * @return array{
     *   ok:bool,
     *   z_cache:bool,
     *   tryb:string,
     *   blad:?string,
     *   ip:string,
     *   wynik:?array<string,mixed>,
     *   incydenty?:int
     * }
     */
    public function sprawdz(string $ip, bool $force = false, string $zakres = 'lms', ?int $importId = null): array
    {
        return $this->sprawdzIZapisz($ip, $force, $zakres, $importId);
    }

    /**
     * @return array{
     *   ok:bool,
     *   z_cache:bool,
     *   tryb:string,
     *   blad:?string,
     *   ip:string,
     *   wynik:?array<string,mixed>,
     *   incydenty?:int
     * }
     */
    private function sprawdzIZapisz(string $ip, bool $force, string $zakres, ?int $importId): array
    {
        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => $this->tryb(),
                'blad'    => 'Wymagany prawidłowy adres IP.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => $this->tryb(),
                'blad'    => 'DNSBL obsługuje tylko IPv4.',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        if (! $force) {
            $cache = $this->enrichment->znajdzPoIp($ip);
            if ($this->enrichment->cacheWazny($cache, $this->cacheTtl)) {
                return [
                    'ok'      => true,
                    'z_cache' => true,
                    'tryb'    => 'cache',
                    'blad'    => null,
                    'ip'      => $ip,
                    'wynik'   => $this->wierszDoWyniku($cache),
                    'incydenty'=> 0,
                ];
            }
        }

        if ($this->hasFixture()) {
            return $this->zFixture($ip, $zakres, $importId);
        }

        $resp = $this->client->checkIp($ip);
        if (! $resp['ok'] || ! is_array($resp['data'])) {
            return [
                'ok'      => false,
                'z_cache' => false,
                'tryb'    => 'dns',
                'blad'    => $resp['error'] !== '' ? $resp['error'] : 'Błąd DNS DNSBL',
                'ip'      => $ip,
                'wynik'   => null,
            ];
        }

        $inc = $this->zapiszWynik($ip, $resp['data'], 'dns', $zakres, $importId);

        return [
            'ok'       => true,
            'z_cache'  => false,
            'tryb'     => 'dns',
            'blad'     => null,
            'ip'       => $ip,
            'wynik'    => $this->wierszDoWyniku($this->enrichment->znajdzPoIp($ip) ?? []),
            'incydenty'=> $inc,
        ];
    }

    /**
     * @return array{ok:bool,z_cache:bool,tryb:string,blad:?string,ip:string,wynik:?array<string,mixed>,incydenty?:int}
     */
    private function zFixture(string $ip, string $zakres, ?int $importId): array
    {
        $raw = file_get_contents($this->fixturePath);
        if ($raw === false) {
            return [
                'ok' => false, 'z_cache' => false, 'tryb' => 'fixture',
                'blad' => 'Nie można odczytać fixturePath.', 'ip' => $ip, 'wynik' => null,
            ];
        }
        $json = json_decode($raw, true);
        if (! is_array($json)) {
            return [
                'ok' => false, 'z_cache' => false, 'tryb' => 'fixture',
                'blad' => 'Fixture DNSBL nie jest poprawnym JSON.', 'ip' => $ip, 'wynik' => null,
            ];
        }

        $data = [
            'listed_any' => (bool) ($json['listed_any'] ?? false),
            'listy'      => is_array($json['listy'] ?? null) ? $json['listy'] : [],
        ];
        $inc = $this->zapiszWynik($ip, $data, 'fixture', $zakres, $importId);

        return [
            'ok'       => true,
            'z_cache'  => false,
            'tryb'     => 'fixture',
            'blad'     => null,
            'ip'       => $ip,
            'wynik'    => $this->wierszDoWyniku($this->enrichment->znajdzPoIp($ip) ?? []),
            'incydenty'=> $inc,
        ];
    }

    /**
     * @param array{listed_any: bool, listy: array<string, mixed>} $data
     */
    private function zapiszWynik(string $ip, array $data, string $zrodloDanych, string $zakres, ?int $importId): int
    {
        $teraz = gmdate('Y-m-d H:i:sP');
        $listy = [];
        foreach ($data['listy'] as $id => $info) {
            if (! is_array($info)) {
                continue;
            }
            $listy[(string) $id] = [
                'listed' => (bool) ($info['listed'] ?? false),
                'kody'   => is_array($info['kody'] ?? null) ? array_values(array_filter($info['kody'], 'is_string')) : [],
                'error'  => (string) ($info['error'] ?? ''),
            ];
        }

        $this->enrichment->upsertDlaIp($ip, [
            'listed_any'    => (bool) ($data['listed_any'] ?? false),
            'listy_json'    => json_encode($listy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'raw_json'      => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'zrodlo_danych' => $zrodloDanych,
            'zakres_skanu'  => $zakres,
            'checked_at'    => $teraz,
        ]);

        $incydenty = 0;
        foreach ($listy as $listaId => $info) {
            $incydenty += $this->upsertWierszZrodla(
                $ip,
                $listaId,
                $info,
                $zakres,
                $teraz,
                $importId,
            ) ? 1 : 0;
        }

        return $incydenty;
    }

    /**
     * @param array{listed: bool, kody: list<string>, error: string} $info
     */
    private function upsertWierszZrodla(
        string $ip,
        string $lista,
        array $info,
        string $zakres,
        string $teraz,
        ?int $importId,
    ): bool {
        if ($info['error'] !== '') {
            return false;
        }

        $listed = $info['listed'];
        $kodA   = $info['kody'][0] ?? null;
        $klucz  = $ip . '|' . $lista;
        $ist    = $this->wiersze->znajdzPoIpLista($ip, $lista);

        $payload = [
            'listed'       => $listed,
            'kod_a'        => $kodA,
            'kody'         => $info['kody'],
            'zakres_skanu' => $zakres,
        ];

        if (is_array($ist)) {
            $update = [
                'listed'         => $listed,
                'kod_a'          => $kodA,
                'zakres_skanu'   => $zakres,
                'ostatni_seen_at'=> $teraz,
                'import_id'      => $importId,
                'payload_json'   => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ];
            if ($listed) {
                $this->db->table('mcs_dnsbl_wiersze')->where('id', $ist['id'])->update($update);
            } else {
                $this->db->table('mcs_dnsbl_wiersze')->where('id', $ist['id'])->update($update);

                return false;
            }
            $idWZrodle = (string) $ist['id'];
        } else {
            if (! $listed) {
                return false;
            }
            $this->db->table('mcs_dnsbl_wiersze')->insert([
                'import_id'        => $importId,
                'ip'               => $ip,
                'lista'            => $lista,
                'kod_a'            => $kodA,
                'zakres_skanu'     => $zakres,
                'listed'           => true,
                'pierwszy_seen_at' => $teraz,
                'ostatni_seen_at'  => $teraz,
                'klucz_dedupu'     => $klucz,
                'payload_json'     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $idWZrodle = (string) $this->db->insertID();
        }

        $wagaMeta = $this->waga->wylicz('reputation');
        $this->zapis->zapisz([
            'zrodlo'           => 'dnsbl',
            'klucz_dedupu'     => $klucz,
            'podmiot_typ'      => 'ip',
            'podmiot'          => $ip,
            'zaobserwowano_at' => $teraz,
            'waga'             => $wagaMeta['waga'],
            'wersja_wagi'      => $wagaMeta['wersja'],
            'kategoria'        => 'reputation',
            'tytul'            => 'DNSBL ' . $lista . ': ' . $ip,
            'port'             => null,
            'protokol'         => null,
            'import_id'        => $importId,
            'id_w_zrodle'      => $idWZrodle,
            'payload'          => [
                'lista'        => $lista,
                'kod_a'        => $kodA,
                'kody'         => $info['kody'],
                'zakres_skanu' => $zakres,
                'waga_meta'    => $wagaMeta,
            ],
        ]);

        return true;
    }

    /**
     * @return list<string>
     */
    private function ipZLms(): array
    {
        $rows = $this->db->table('mcs_enrichment_lms')->select('ip')->get()->getResultArray();
        $out  = [];
        foreach ($rows as $row) {
            $ip = trim((string) ($row['ip'] ?? ''));
            if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                continue;
            }
            if ($this->asMatcher->jestWlaczone() && ! $this->asMatcher->czyNaszeIp($ip)) {
                continue;
            }
            $out[$ip] = $ip;
        }

        return array_values($out);
    }

    /**
     * @return list<string>
     */
    private function ipZAs(): array
    {
        $out = [];
        foreach ($this->asMatcher->cidryIpv4() as $cidr) {
            foreach ($this->cidr->enumerujIpv4($cidr) as $ip) {
                $out[$ip] = $ip;
            }
        }

        return array_values($out);
    }

    /**
     * @param array<string, mixed> $wiersz
     *
     * @return array<string, mixed>
     */
    private function wierszDoWyniku(array $wiersz): array
    {
        $listy = $wiersz['listy_json'] ?? [];
        if (is_string($listy)) {
            $decoded = json_decode($listy, true);
            $listy   = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($listy)) {
            $listy = [];
        }

        $listedAny = $wiersz['listed_any'] ?? null;
        if (is_string($listedAny) || is_int($listedAny)) {
            $listedAny = PluginModel::jakoBool($listedAny);
        }

        return [
            'ip'            => (string) ($wiersz['ip'] ?? ''),
            'listed_any'    => $listedAny,
            'listy'         => $listy,
            'zrodlo_danych' => $wiersz['zrodlo_danych'] ?? null,
            'zakres_skanu'  => $wiersz['zakres_skanu'] ?? null,
            'checked_at'    => $wiersz['checked_at'] ?? null,
        ];
    }
}
