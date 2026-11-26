<?php

namespace McsPluginy\threatfox;

use App\Libraries\BladUi;
use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\WagaV1;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Przebieg pluginu threatfox: harmonogram → POST get_iocs → parsowanie → dopasowanie IP do prefiksów AS
 * → stan/IOC/trafienia/historia → incydenty.
 *
 * Zasada: wynik „czysto” zapisujemy tylko po kompletnym pobraniu i sparsowaniu odpowiedzi
 * (query_status ok z danymi albo no_results). Błąd pobrania/parsowania nie zmienia wyniku
 * ani trafień — tylko licznik błędów i backoff.
 */
class Serwis
{
    public const ZRODLO = 'threatfox';

    /** Fallback kategorii dla nieznanego threat_type — każdy IOC ThreatFox to zgłoszenie abuse. */
    public const KATEGORIA_DOMYSLNA = 'abuse';

    /**
     * Mapowanie threat_type ThreatFox → kategoria MCS (z dozwolonych przez EtykietyUi/WagaV1:
     * malware, amplification, vulnerability, abuse, reputation, exposure, other, spam, hijack).
     *
     * @var array<string, string>
     */
    public const MAPA_KATEGORII = [
        'malware'             => 'malware',
        'botnet_c2'           => 'malware',
        'command_and_control' => 'malware',
        'botnet'              => 'malware',
        'dropper'             => 'malware',
        'loader'              => 'malware',
        'ransomware'          => 'malware',
        'trojan'              => 'malware',
        'phishing'            => 'abuse',
        'scam'                => 'abuse',
        'spam'                => 'spam',
        'brute_force'         => 'abuse',
        'bruteforce'          => 'abuse',
        'scanner'             => 'abuse',
        'ids_alert'           => 'abuse',
        'torrent'             => 'abuse',
        'exploit'             => 'vulnerability',
        'vulnerability'       => 'vulnerability',
        'proxy'               => 'reputation',
        'vpn'                 => 'reputation',
        'dyn_node'            => 'reputation',
    ];

    private BaseConnection $db;
    private Klient $klient;
    private Parser $parser;
    private IncydentZapis $zapis;
    private ImportKoperta $koperta;
    private WagaV1 $waga;

    /** @var callable(): int */
    private $zegar;

    /** @var callable(): list<string> */
    private $naszeCidry;

    /** @var callable(): int */
    private $losowaMinuta;

    private int $interwalGodzin;

    /**
     * @param (callable(): list<string>)|null $naszeCidry
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?Klient $klient = null,
        ?IncydentZapis $zapis = null,
        ?ImportKoperta $koperta = null,
        ?callable $zegar = null,
        ?callable $naszeCidry = null,
        ?int $interwalGodzin = null,
        ?callable $losowaMinuta = null,
    ) {
        $this->db             = $db ?? Database::connect();
        $this->klient         = $klient ?? Klient::fromOpcje();
        $this->parser         = new Parser();
        $this->zapis          = $zapis ?? new IncydentZapis($this->db);
        $this->koperta        = $koperta ?? new ImportKoperta(null, $this->db);
        $this->waga           = new WagaV1();
        $this->zegar          = $zegar ?? static fn (): int => time();
        $this->naszeCidry     = $naszeCidry ?? static fn (): array => (new \App\Libraries\Siec\AsMatcher())->cidry();
        $this->interwalGodzin = $interwalGodzin ?? \App\Libraries\OpcjeMcs::int('mcs.threatfox.interwalGodzin');
        $this->losowaMinuta   = $losowaMinuta ?? static fn (): int => random_int(0, 59);
    }

    /**
     * @return array{status: string, msg: string, trafienia?: int, rekordy?: int, nowe?: int, zdjete?: int}
     *         status: pominieto | ok | blad
     */
    public function uruchom(): array
    {
        $teraz = ($this->zegar)();
        $stan  = $this->stan();

        $nastepne = self::ts($stan['nastepne_po'] ?? null);
        $proba    = self::ts($stan['ostatnia_proba_at'] ?? null);
        if (! Harmonogram::czyPora($teraz, $nastepne, $proba)) {
            $po = max((int) $nastepne, (int) $proba + Harmonogram::MIN_ODSTEP_S);

            return [
                'status' => 'pominieto',
                'msg'    => 'pominięto (następne po ' . gmdate('Y-m-d H:i', $po) . ' UTC)',
            ];
        }

        $this->zapiszStan(['ostatnia_proba_at' => self::fmt($teraz)]);
        $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, ['opis' => 'ThreatFox IOC (get_iocs, dni=' . $this->klient->dni() . ')']);

        $retry = 0;
        try {
            $res = $this->klient->pobierz();
            if (! $res['ok']) {
                $retry = (int) ($res['retry_after'] ?? 0);
                throw new RuntimeException(
                    'ThreatFox: ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code'])
                );
            }
            $sha     = hash('sha256', $res['body']);
            $rekordy = $this->parser->parsuj($res['body']);

            // ThreatFox zwraca ten sam ioc wielokrotnie (różne threat_type/id) — UNIQUE(ioc): dedup, pierwszy wygrywa.
            $unikalne = [];
            foreach ($rekordy as $r) {
                $unikalne[(string) $r['ioc']] ??= $r;
            }
            $rekordy = array_values($unikalne);

            $nasze = array_values(array_filter(
                array_map(static fn ($c) => Dopasowanie::normalizujCidr((string) $c), ($this->naszeCidry)()),
                static fn ($c) => $c !== null,
            ));
            if ($nasze === []) {
                throw new RuntimeException('ThreatFox: brak prefiksów w mcs_ustawienia.as_cidry — nie można ocenić');
            }

            $trafienia = Dopasowanie::dopasujIps($rekordy, $nasze);
            $wynik     = $this->zapiszWynik($teraz, $rekordy, $trafienia, $sha);
            $incydenty = $this->zapiszIncydenty($teraz, $importId);

            $zIp = count(array_filter($rekordy, static fn (array $r): bool => is_string($r['ip'] ?? null) && $r['ip'] !== ''));
            $msg = sprintf(
                'ThreatFox %s: ioc=%d (z ip=%d), trafienia=%d (nowe=%d, zdjęte=%d), prefiksów=%d, dni=%d',
                $trafienia === [] ? 'czysto' : 'TRAFIENIE',
                count($rekordy),
                $zIp,
                count($trafienia),
                $wynik['nowe'],
                $wynik['zdjete'],
                count($nasze),
                $this->klient->dni(),
            );
            if ($importId !== null) {
                $this->koperta->zakonczOk($importId, [
                    'rekordy'   => count($rekordy),
                    'trafienia' => count($trafienia),
                    'incydenty' => $incydenty,
                    'sha256'    => $sha,
                ]);
            }

            return [
                'status'    => 'ok',
                'msg'       => $msg,
                'trafienia' => count($trafienia),
                'rekordy'   => count($rekordy),
                'nowe'      => $wynik['nowe'],
                'zdjete'    => $wynik['zdjete'],
            ];
        } catch (\Throwable $e) {
            // #433: do UI/JSON tylko komunikat z whitelisty albo generyczny + ID zdarzenia (pełny błąd w logu).
            $bladUi = BladUi::komunikat($e, 'threatfox pobieranie');
            $bledy  = (int) ($stan['bledy_z_rzedu'] ?? 0) + 1;
            $po     = Harmonogram::poBledzie($teraz, $bledy, $retry);
            $this->zapiszStan([
                'bledy_z_rzedu'   => $bledy,
                'ostatni_blad'    => mb_substr($bladUi, 0, 1000),
                'ostatni_blad_at' => self::fmt($teraz),
                'nastepne_po'     => self::fmt($po),
            ]);
            $this->historia($teraz, 'blad', null, null, $bladUi . ' (ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC)');
            if ($importId !== null) {
                $this->koperta->zakonczBlad($importId, $bladUi);
            }

            return [
                'status' => 'blad',
                'msg'    => $bladUi . ' — wynik bez zmian, ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC',
            ];
        }
    }

    /**
     * threat_type ThreatFox → kategoria MCS (fallback: KATEGORIA_DOMYSLNA).
     */
    public static function kategoriaDla(string $threatType): string
    {
        $t = strtolower(trim($threatType));

        return self::MAPA_KATEGORII[$t] ?? self::KATEGORIA_DOMYSLNA;
    }

    /**
     * confidence_level (0–100) → etykieta wagi dla WagaV1 (max z bazą kategorii); null = bez sygnału.
     */
    public static function etykietaUfnosci(?int $confidence): ?string
    {
        if ($confidence === null) {
            return null;
        }
        if ($confidence >= 90) {
            return 'critical';
        }
        if ($confidence >= 60) {
            return 'high';
        }
        if ($confidence >= 30) {
            return 'medium';
        }

        return null;
    }

    /**
     * Zapis wyniku przebiegu (issue #443): bez pełnych skanów tabel — diff po kluczu
     * whereIn('ioc', paczka), upsert INSERT … ON CONFLICT (ioc) DO UPDATE,
     * trafienia upsert ON CONFLICT (ioc, nasz_prefiks), zdjęcia zbiorczo per chunk,
     * historia insertBatch; jedna transakcja na paczkę 500 rekordów.
     *
     * @param list<array<string, mixed>>   $rekordy
     * @param array<string, array<string, mixed>> $trafienia klucz: ioc|nasz_prefiks
     *
     * @return array{nowe: int, zdjete: int}
     */
    private function zapiszWynik(int $teraz, array $rekordy, array $trafienia, string $sha): array
    {
        $czas   = self::fmt($teraz);
        $nowe   = 0;
        $zdjete = 0;

        $widzianeIoc = [];
        foreach ($rekordy as $r) {
            $widzianeIoc[(string) $r['ioc']] = true;
        }
        // trafienia pogrupowane po ioc (klucz trafienia: ioc|nasz_prefiks)
        $trafWgIoc = [];
        foreach ($trafienia as $klucz => $t) {
            $trafWgIoc[(string) $t['ioc']][$klucz] = $t;
        }

        // --- paczki po 500 rekordów: upsert IOC + upsert trafień + historia, transakcja per paczka ---
        foreach (array_chunk($rekordy, 500, true) as $paczka) {
            $histPaczki = [];

            $this->db->transException(true)->transStart();
            try {
                // IOC: upsert ON CONFLICT (ioc) — pierwszy_import_at tylko przy nowym wierszu
                $wierszeIoc = [];
                foreach ($paczka as $r) {
                    $wierszeIoc[(string) $r['ioc']] = $this->wierszIoc($r, $czas);
                }
                \App\Libraries\ZapisBatch::upsert(
                    $this->db,
                    'mcs_threatfox_ioc',
                    array_values($wierszeIoc),
                    '(ioc)',
                    [
                        'ioc_type = excluded.ioc_type',
                        'ip = excluded.ip',
                        'port = excluded.port',
                        'threat_type = excluded.threat_type',
                        'threat_type_desc = excluded.threat_type_desc',
                        'malware = excluded.malware',
                        'malware_printable = excluded.malware_printable',
                        'confidence_level = excluded.confidence_level',
                        'first_seen = excluded.first_seen',
                        'last_seen = excluded.last_seen',
                        'reporter = excluded.reporter',
                        'reference = excluded.reference',
                        'tags = excluded.tags',
                        'threatfox_id = excluded.threatfox_id',
                        'aktywne = excluded.aktywne',
                        'zdjete_at = excluded.zdjete_at',
                        'ostatni_import_at = excluded.ostatni_import_at',
                    ],
                );

                // trafienia tej paczki: diff po kluczu whereIn('ioc', …) zamiast pełnego ->get()
                $trafPaczki = [];
                foreach ($paczka as $r) {
                    foreach ($trafWgIoc[(string) $r['ioc']] ?? [] as $klucz => $t) {
                        $trafPaczki[$klucz] = $t;
                    }
                }
                if ($trafPaczki !== []) {
                    $istniejace = [];
                    foreach ($this->db->table('mcs_threatfox_trafienia')
                        ->whereIn('ioc', array_values(array_unique(array_column($trafPaczki, 'ioc'))))
                        ->get()->getResultArray() as $w) {
                        $istniejace[(string) $w['ioc'] . '|' . (string) $w['nasz_prefiks']] = $w;
                    }

                    $wierszeTraf = [];
                    foreach ($trafPaczki as $klucz => $t) {
                        $stary = $istniejace[$klucz] ?? null;
                        if ($stary === null) {
                            $nowe++;
                            $histPaczki[] = $this->wierszHistoria($teraz, 'nowe', (string) $t['ioc'], (string) $t['nasz_prefiks'], $this->opisTrafienia((string) $t['ioc'], (string) ($t['threat_type_desc'] ?? ''), (string) ($t['threat_type'] ?? ''), (string) $t['nasz_prefiks']));
                        } elseif (! \App\Models\PluginModel::jakoBool($stary['aktywne'])) {
                            $nowe++;
                            $histPaczki[] = $this->wierszHistoria($teraz, 'nowe', (string) $t['ioc'], (string) $t['nasz_prefiks'], 'ponownie: ' . $this->opisTrafienia((string) $t['ioc'], (string) ($t['threat_type_desc'] ?? ''), (string) ($t['threat_type'] ?? ''), (string) $t['nasz_prefiks']));
                        }
                        $wierszeTraf[] = $this->wierszTrafienia($t, $czas);
                    }
                    \App\Libraries\ZapisBatch::upsert(
                        $this->db,
                        'mcs_threatfox_trafienia',
                        $wierszeTraf,
                        '(ioc, nasz_prefiks)',
                        [
                            'ip = excluded.ip',
                            'port = excluded.port',
                            'threat_type = excluded.threat_type',
                            'kategoria = excluded.kategoria',
                            'aktywne = excluded.aktywne',
                            'ostatnie_at = excluded.ostatnie_at',
                            'zdjete_at = excluded.zdjete_at',
                            'payload_json = excluded.payload_json',
                        ],
                    );
                }

                if ($histPaczki !== []) {
                    foreach (array_chunk($histPaczki, 500) as $h) {
                        $this->db->table('mcs_threatfox_historia')->insertBatch($h);
                    }
                }

                $this->db->transComplete();
                if ($this->db->transStatus() === false) {
                    throw new RuntimeException('Transakcja zapisu wyniku ThreatFox nieudana (paczka IOC/trafień)');
                }
            } catch (\Throwable $e) {
                if ($this->db->transStatus() !== false) {
                    $this->db->transRollback();
                }
                $this->db->resetTransStatus();
                throw $e;
            }
        }

        // --- zdjęcia zbiorczo (tylko aktywne, diff w PHP) + historia „pobrano” + stan: jedna transakcja ---
        $histZdjete = [];
        $this->db->transException(true)->transStart();
        try {
            // IOC zdjęte: aktywne i nieobecne w tej odpowiedzi
            $iocZdjete  = 0;
            $doZdjecia  = [];
            foreach ($this->db->table('mcs_threatfox_ioc')->select('id, ioc')->where('aktywne', true)->get()->getResultArray() as $w) {
                if (isset($widzianeIoc[(string) $w['ioc']])) {
                    continue;
                }
                $doZdjecia[] = (int) $w['id'];
                $iocZdjete++;
            }
            foreach (array_chunk($doZdjecia, 500) as $pac) {
                $this->db->table('mcs_threatfox_ioc')->whereIn('id', $pac)->update(['aktywne' => false, 'zdjete_at' => $czas]);
            }

            // trafienia zdjęte: aktywne i nieobecne w tej odpowiedzi
            $doZdjecia = [];
            foreach ($this->db->table('mcs_threatfox_trafienia')->select('id, ioc, nasz_prefiks')->where('aktywne', true)->get()->getResultArray() as $w) {
                $klucz = (string) $w['ioc'] . '|' . (string) $w['nasz_prefiks'];
                if (isset($trafienia[$klucz])) {
                    continue;
                }
                $doZdjecia[] = (int) $w['id'];
                $zdjete++;
                $histZdjete[] = $this->wierszHistoria($teraz, 'zdjete', (string) $w['ioc'], (string) $w['nasz_prefiks'], 'IOC zniknął z odpowiedzi ThreatFox');
            }
            foreach (array_chunk($doZdjecia, 500) as $pac) {
                $this->db->table('mcs_threatfox_trafienia')->whereIn('id', $pac)->update(['aktywne' => false, 'zdjete_at' => $czas]);
            }

            $zIp          = count(array_filter($rekordy, static fn (array $r): bool => is_string($r['ip'] ?? null) && $r['ip'] !== ''));
            $histZdjete[] = $this->wierszHistoria($teraz, 'pobrano', null, null, sprintf(
                'pobrano OK: ioc=%d (z ip=%d), trafienia=%d, nowe=%d, zdjęte=%d, ioc zdjęte=%d, sha256=%s',
                count($rekordy),
                $zIp,
                count($trafienia),
                $nowe,
                $zdjete,
                $iocZdjete,
                substr($sha, 0, 16),
            ));
            foreach (array_chunk($histZdjete, 500) as $h) {
                $this->db->table('mcs_threatfox_historia')->insertBatch($h);
            }

            $this->zapiszStan([
                'ostatnie_ok_at'    => $czas,
                'nastepne_po'       => self::fmt(Harmonogram::poSukcesie($teraz, $this->interwalGodzin, ($this->losowaMinuta)())),
                'bledy_z_rzedu'     => 0,
                'wynik'             => $trafienia === [] ? 'czysto' : 'trafienie',
                'wynik_at'          => $czas,
                'aktywne_trafienia' => count($trafienia),
                'rekordy'           => count($rekordy),
            ]);

            $this->db->transComplete();
            if ($this->db->transStatus() === false) {
                throw new RuntimeException('Transakcja zapisu wyniku ThreatFox nieudana (zdjęcia/stan)');
            }
        } catch (\Throwable $e) {
            if ($this->db->transStatus() !== false) {
                $this->db->transRollback();
            }
            $this->db->resetTransStatus();
            throw $e;
        }

        return ['nowe' => $nowe, 'zdjete' => $zdjete];
    }

    /**
     * Wiersz mcs_threatfox_ioc dla upsert (pierwszy_import_at tylko przy insercie).
     *
     * @param array<string, mixed> $r
     *
     * @return array<string, mixed>
     */
    private function wierszIoc(array $r, string $czas): array
    {
        return [
            'ioc'                => (string) $r['ioc'],
            'ioc_type'           => (string) $r['ioc_type'],
            'ip'                 => $r['ip'] ?? null,
            'port'               => $r['port'] ?? null,
            'threat_type'        => (string) $r['threat_type'],
            'threat_type_desc'   => mb_substr((string) $r['threat_type_desc'], 0, 255),
            'malware'            => mb_substr((string) $r['malware'], 0, 255),
            'malware_printable'  => mb_substr((string) $r['malware_printable'], 0, 255),
            'confidence_level'   => $r['confidence_level'] ?? null,
            'first_seen'         => (string) $r['first_seen'],
            'last_seen'          => (string) $r['last_seen'],
            'reporter'           => mb_substr((string) $r['reporter'], 0, 255),
            'reference'          => (string) $r['reference'],
            'tags'               => mb_substr(implode(',', $r['tags'] ?? []), 0, 255),
            'threatfox_id'       => (string) $r['id'],
            'aktywne'            => true,
            'zdjete_at'          => null,
            'pierwszy_import_at' => $czas,
            'ostatni_import_at'  => $czas,
        ];
    }

    /**
     * Wiersz mcs_threatfox_trafienia dla upsert (pierwsze_at tylko przy insercie).
     *
     * @param array<string, mixed> $t
     *
     * @return array<string, mixed>
     */
    private function wierszTrafienia(array $t, string $czas): array
    {
        return [
            'ioc'          => (string) $t['ioc'],
            'nasz_prefiks' => (string) $t['nasz_prefiks'],
            'ip'           => (string) $t['ip'],
            'port'         => $t['port'] ?? null,
            'threat_type'  => (string) $t['threat_type'],
            'kategoria'    => self::kategoriaDla((string) $t['threat_type']),
            'aktywne'      => true,
            'pierwsze_at'  => $czas,
            'ostatnie_at'  => $czas,
            'zdjete_at'    => null,
            'payload_json' => json_encode($t, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * Wiersz mcs_threatfox_historia (do insertBatch).
     *
     * @return array<string, mixed>
     */
    private function wierszHistoria(int $teraz, string $zdarzenie, ?string $ioc, ?string $prefiks, string $opis): array
    {
        return [
            'at'           => self::fmt($teraz),
            'zdarzenie'    => $zdarzenie,
            'ioc'          => $ioc,
            'nasz_prefiks' => $prefiks,
            'opis'         => mb_substr($opis, 0, 2000),
        ];
    }

    /**
     * Incydent na każde aktywne trafienie (dedup: zrodlo + klucz_dedupu w IncydentZapis).
     * Zbiorczo (issue #442): jedno zapiszWiele + jeden UPDATE z CASE per paczka na trafienia.incydent_id.
     *
     * @return int liczba zapisanych incydentów
     */
    private function zapiszIncydenty(int $teraz, ?int $importId): int
    {
        $aktywne = $this->db->table('mcs_threatfox_trafienia')->where('aktywne', true)->get()->getResultArray();

        $dane = [];
        foreach ($aktywne as $t) {
            $rek  = is_string($t['payload_json'] ?? null) ? (json_decode($t['payload_json'], true) ?: []) : [];
            $kat  = self::kategoriaDla((string) $t['threat_type']);
            $conf = $rek['confidence_level'] ?? null;

            $sygnaly  = [];
            $etykieta = self::etykietaUfnosci(is_numeric($conf) ? (int) $conf : null);
            if ($etykieta !== null) {
                $sygnaly['severity'] = $etykieta;
            }
            $waga = $this->waga->wylicz($kat, $sygnaly);

            $dane[] = [
                'zrodlo'           => self::ZRODLO,
                'klucz_dedupu'     => 'threatfox|' . $t['ioc'] . '|' . $t['nasz_prefiks'],
                'podmiot_typ'      => 'ip',
                'podmiot'          => (string) $t['ip'],
                'zaobserwowano_at' => self::fmt($teraz),
                'waga'             => $waga['waga'],
                'wersja_wagi'      => $waga['wersja'],
                'kategoria'        => $kat,
                'tytul'            => mb_substr($this->opisTrafienia((string) $t['ioc'], (string) ($rek['threat_type_desc'] ?? ''), (string) $t['threat_type'], (string) $t['nasz_prefiks']), 0, 255),
                'port'             => $t['port'] !== null && $t['port'] !== '' ? (int) $t['port'] : null,
                'protokol'         => null,
                'import_id'        => $importId,
                'id_w_zrodle'      => (string) $t['id'],
                'payload'          => [
                    'ioc'               => $t['ioc'],
                    'ioc_type'          => $rek['ioc_type'] ?? null,
                    'ip'                => $t['ip'],
                    'port'              => $t['port'],
                    'nasz_prefiks'      => $t['nasz_prefiks'],
                    'threat_type'       => $t['threat_type'],
                    'threat_type_desc'  => $rek['threat_type_desc'] ?? null,
                    'malware'           => $rek['malware'] ?? null,
                    'malware_printable' => $rek['malware_printable'] ?? null,
                    'confidence_level'  => $conf,
                    'first_seen'        => $rek['first_seen'] ?? null,
                    'last_seen'         => $rek['last_seen'] ?? null,
                    'reporter'          => $rek['reporter'] ?? null,
                    'reference'         => $rek['reference'] ?? null,
                    'tags'              => $rek['tags'] ?? [],
                    'kategoria'         => $kat,
                    'waga_meta'         => $waga,
                ],
            ];
        }

        $wyniki = $this->zapis->zapiszWiele($dane);

        // trafienia.incydent_id — zbiorczo, jeden UPDATE z CASE na paczkę
        $mapa = [];
        foreach ($aktywne as $i => $t) {
            $mapa[(int) $t['id']] = $wyniki[$i]['incydent_id'];
        }
        foreach (array_chunk($mapa, 500, true) as $paczka) {
            \App\Libraries\ZapisBatch::updateCaseWhereIn($this->db, 'mcs_threatfox_trafienia', 'incydent_id', 'id', $paczka);
        }

        return count($aktywne);
    }

    private function opisTrafienia(string $ioc, string $threatTypeDesc, string $threatType, string $naszPrefiks): string
    {
        $co = $threatTypeDesc !== '' ? $threatTypeDesc : ($threatType !== '' ? $threatType : 'IOC');

        return 'ThreatFox: ' . $ioc . ' (' . $co . ') w naszym prefiksie ' . $naszPrefiks;
    }

    /**
     * @return array<string, mixed>
     */
    public function stan(): array
    {
        $w = $this->db->table('mcs_threatfox_stan')->where('id', 1)->get()->getRowArray();
        if (! is_array($w)) {
            $this->db->table('mcs_threatfox_stan')->insert(['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0, 'aktywne_trafienia' => 0, 'rekordy' => 0]);

            return ['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0];
        }

        return $w;
    }

    /**
     * @param array<string, mixed> $dane
     */
    private function zapiszStan(array $dane): void
    {
        $this->stan();
        $this->db->table('mcs_threatfox_stan')->where('id', 1)->update($dane);
    }

    private function historia(int $teraz, string $zdarzenie, ?string $ioc, ?string $prefiks, string $opis): void
    {
        $this->db->table('mcs_threatfox_historia')->insert([
            'at'           => self::fmt($teraz),
            'zdarzenie'    => $zdarzenie,
            'ioc'          => $ioc,
            'nasz_prefiks' => $prefiks,
            'opis'         => mb_substr($opis, 0, 2000),
        ]);
    }

    public static function fmt(int $ts): string
    {
        return gmdate('Y-m-d H:i:sP', $ts);
    }

    public static function ts(mixed $v): ?int
    {
        if ($v === null || $v === '') {
            return null;
        }
        $t = strtotime((string) $v);

        return $t === false ? null : $t;
    }
}
