<?php

namespace McsPluginy\spamhaus;

use App\Libraries\ImportKoperta;
use App\Libraries\IncydentZapis;
use App\Libraries\WagaV1;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Przebieg pluginu spamhaus: harmonogram → pobranie 3 plików → dopasowanie → stan/historia → incydenty.
 *
 * Zasada: wynik „czysto” zapisujemy tylko po kompletnym pobraniu i sparsowaniu wszystkich trzech plików.
 * Błąd pobrania/parsowania nie zmienia wyniku ani trafień — tylko licznik błędów i backoff.
 */
class Serwis
{
    public const ZRODLO = 'spamhaus';

    public const KATEGORIA_DROP = 'hijack';
    public const KATEGORIA_ASN  = 'spam';

    private BaseConnection $db;
    private DropKlient $klient;
    private DropParser $parser;
    private IncydentZapis $zapis;
    private ImportKoperta $koperta;
    private WagaV1 $waga;

    /** @var callable(): int */
    private $zegar;

    /** @var callable(): list<string> */
    private $naszeCidry;

    /** @var callable(): int */
    private $naszAsn;

    /** @var callable(): int */
    private $losowaMinuta;

    private int $interwalGodzin;

    /**
     * @param (callable(): list<string>)|null $naszeCidry
     */
    public function __construct(
        ?BaseConnection $db = null,
        ?DropKlient $klient = null,
        ?IncydentZapis $zapis = null,
        ?ImportKoperta $koperta = null,
        ?callable $zegar = null,
        ?callable $naszeCidry = null,
        ?callable $naszAsn = null,
        ?int $interwalGodzin = null,
        ?callable $losowaMinuta = null,
    ) {
        $this->db             = $db ?? Database::connect();
        $this->klient         = $klient ?? DropKlient::fromOpcje();
        $this->parser         = new DropParser();
        $this->zapis          = $zapis ?? new IncydentZapis($this->db);
        $this->koperta        = $koperta ?? new ImportKoperta(null, $this->db);
        $this->waga           = new WagaV1();
        $this->zegar          = $zegar ?? static fn (): int => time();
        $this->naszeCidry     = $naszeCidry ?? static fn (): array => (new \App\Libraries\Siec\As198050Matcher())->cidry();
        $this->naszAsn        = $naszAsn ?? static fn (): int => \App\Libraries\Siec\As198050Matcher::asn();
        $this->interwalGodzin = $interwalGodzin ?? \App\Libraries\OpcjeMcs::int('mcs.spamhaus.interwalGodzin');
        $this->losowaMinuta   = $losowaMinuta ?? static fn (): int => random_int(0, 59);
    }

    /**
     * @return array{status: string, msg: string, trafienia?: int, rekordy?: array<string, int>, nowe?: int, zdjete?: int}
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
        $importId = $this->koperta->rozpocznij(self::ZRODLO, null, null, ['opis' => 'Spamhaus DROP (drop_v4, drop_v6, asndrop)']);

        try {
            $pliki = [];
            foreach (array_keys(DropKlient::PLIKI) as $lista) {
                $res = $this->klient->pobierz($lista);
                if (! $res['ok']) {
                    throw new BladPobrania(
                        'Spamhaus ' . $lista . ': ' . ($res['error'] !== '' ? $res['error'] : 'HTTP ' . $res['http_code']),
                        (int) ($res['retry_after'] ?? 0),
                    );
                }
                $p              = $this->parser->parsuj($res['body'], $lista);
                $p['sha256']    = hash('sha256', $res['body']);
                $pliki[$lista]  = $p;
            }

            $nasze = array_values(array_filter(
                array_map(static fn ($c) => Dopasowanie::normalizuj((string) $c), ($this->naszeCidry)()),
                static fn ($c) => $c !== null,
            ));
            if ($nasze === []) {
                throw new RuntimeException('Spamhaus: brak prefiksów w mcs_ustawienia.as198050_cidry — nie można ocenić');
            }
            $asn = ($this->naszAsn)();

            $trafienia = $this->policzTrafienia($pliki, $nasze, $asn);
            $wynik     = $this->zapiszWynik($teraz, $pliki, $trafienia);
            $incydenty = $this->zapiszIncydenty($teraz, $importId);

            $rekordy = array_map(static fn (array $p): int => count($p['wpisy']), $pliki);
            $msg     = sprintf(
                'DROP %s: v4=%d v6=%d asn=%d, trafienia=%d (nowe=%d, zdjęte=%d), prefiksów=%d, AS%d',
                count($trafienia) === 0 ? 'czysto' : 'TRAFIENIE',
                $rekordy['drop_v4'],
                $rekordy['drop_v6'],
                $rekordy['asndrop'],
                count($trafienia),
                $wynik['nowe'],
                $wynik['zdjete'],
                count($nasze),
                $asn,
            );
            if ($importId !== null) {
                $this->koperta->zakonczOk($importId, [
                    'rekordy'   => $rekordy,
                    'trafienia' => count($trafienia),
                    'incydenty' => $incydenty,
                ]);
            }

            return [
                'status'    => 'ok',
                'msg'       => $msg,
                'trafienia' => count($trafienia),
                'rekordy'   => $rekordy,
                'nowe'      => $wynik['nowe'],
                'zdjete'    => $wynik['zdjete'],
            ];
        } catch (\Throwable $e) {
            $bledy = (int) ($stan['bledy_z_rzedu'] ?? 0) + 1;
            $retry = $e instanceof BladPobrania ? $e->retryAfter : 0;
            $po    = Harmonogram::poBledzie($teraz, $bledy, $retry);
            $this->zapiszStan([
                'bledy_z_rzedu'   => $bledy,
                'ostatni_blad'    => mb_substr($e->getMessage(), 0, 1000),
                'ostatni_blad_at' => self::fmt($teraz),
                'nastepne_po'     => self::fmt($po),
            ]);
            $this->historia($teraz, 'blad', null, null, null, $e->getMessage() . ' (ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC)');
            if ($importId !== null) {
                $this->koperta->zakonczBlad($importId, $e->getMessage());
            }

            return [
                'status' => 'blad',
                'msg'    => $e->getMessage() . ' — wynik bez zmian, ponowna próba po ' . gmdate('Y-m-d H:i', $po) . ' UTC',
            ];
        }
    }

    /**
     * @param array<string, array{wpisy: list<array<string, mixed>>, meta: array<string, mixed>}> $pliki
     * @param list<string>                                                                       $nasze
     *
     * @return array<string, array<string, mixed>> klucz = lista|wpis|nasz_prefiks
     */
    public function policzTrafienia(array $pliki, array $nasze, int $asn): array
    {
        $out = [];
        foreach (['drop_v4', 'drop_v6'] as $lista) {
            /** @var list<array{cidr: string, sblid?: string}> $wpisy */
            $wpisy = $pliki[$lista]['wpisy'] ?? [];
            foreach (Dopasowanie::dopasujCidry($wpisy, $nasze) as $t) {
                $t['typ']   = 'cidr';
                $t['lista'] = $lista;
                $out[$lista . '|' . $t['wpis'] . '|' . $t['nasz_prefiks']] = $t;
            }
        }

        $asnWpis = Dopasowanie::dopasujAsn($pliki['asndrop']['wpisy'] ?? [], $asn);
        if ($asnWpis !== null) {
            foreach ($nasze as $prefiks) {
                $out['asndrop|AS' . $asn . '|' . $prefiks] = [
                    'typ'          => 'asn',
                    'lista'        => 'asndrop',
                    'wpis'         => 'AS' . $asn,
                    'sblid'        => '',
                    'nasz_prefiks' => $prefiks,
                    'zakres'       => $prefiks,
                    'podmiot'      => explode('/', $prefiks, 2)[0],
                    'asndrop'      => $asnWpis,
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $pliki
     * @param array<string, array<string, mixed>> $trafienia
     *
     * @return array{nowe: int, zdjete: int}
     */
    private function zapiszWynik(int $teraz, array $pliki, array $trafienia): array
    {
        $czas  = self::fmt($teraz);
        $nowe  = 0;
        $zdjete = 0;

        $this->db->transException(true)->transStart();

        try {
            foreach ($pliki as $lista => $p) {
                $wiersz = [
                    'url'            => DropKlient::url($lista),
                    'plik_timestamp' => $p['meta']['timestamp'] !== null ? self::fmt((int) $p['meta']['timestamp']) : null,
                    'copyright'      => mb_substr((string) $p['meta']['copyright'], 0, 255),
                    'terms'          => mb_substr((string) $p['meta']['terms'], 0, 255),
                    'rekordy'        => count($p['wpisy']),
                    'sha256'         => (string) $p['sha256'],
                    'pobrano_at'     => $czas,
                ];
                $jest = $this->db->table('mcs_spamhaus_pliki')->where('lista', $lista)->countAllResults() > 0;
                if ($jest) {
                    $this->db->table('mcs_spamhaus_pliki')->where('lista', $lista)->update($wiersz);
                } else {
                    $this->db->table('mcs_spamhaus_pliki')->insert(['lista' => $lista] + $wiersz);
                }
            }

            $istniejace = [];
            foreach ($this->db->table('mcs_spamhaus_trafienia')->get()->getResultArray() as $w) {
                $istniejace[$w['lista'] . '|' . $w['wpis'] . '|' . $w['nasz_prefiks']] = $w;
            }

            foreach ($trafienia as $klucz => $t) {
                $payload = json_encode(array_diff_key($t, ['typ' => 1]), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $stary   = $istniejace[$klucz] ?? null;
                if ($stary === null) {
                    $this->db->table('mcs_spamhaus_trafienia')->insert([
                        'typ'          => $t['typ'],
                        'lista'        => $t['lista'],
                        'wpis'         => $t['wpis'],
                        'sblid'        => (string) $t['sblid'],
                        'nasz_prefiks' => $t['nasz_prefiks'],
                        'zakres'       => $t['zakres'],
                        'podmiot'      => $t['podmiot'],
                        'aktywne'      => true,
                        'pierwsze_at'  => $czas,
                        'ostatnie_at'  => $czas,
                        'payload_json' => $payload,
                    ]);
                    $nowe++;
                    $this->historia($teraz, 'nowe', $t['lista'], $t['wpis'], $t['nasz_prefiks'], $this->opisTrafienia($t));
                    continue;
                }
                $bylAktywny = \App\Models\PluginModel::jakoBool($stary['aktywne']);
                $this->db->table('mcs_spamhaus_trafienia')->where('id', $stary['id'])->update([
                    'aktywne'      => true,
                    'ostatnie_at'  => $czas,
                    'zdjete_at'    => null,
                    'sblid'        => (string) $t['sblid'],
                    'payload_json' => $payload,
                ]);
                if (! $bylAktywny) {
                    $nowe++;
                    $this->historia($teraz, 'nowe', $t['lista'], $t['wpis'], $t['nasz_prefiks'], 'ponownie: ' . $this->opisTrafienia($t));
                }
            }

            foreach ($istniejace as $klucz => $stary) {
                if (isset($trafienia[$klucz]) || ! \App\Models\PluginModel::jakoBool($stary['aktywne'])) {
                    continue;
                }
                $this->db->table('mcs_spamhaus_trafienia')->where('id', $stary['id'])->update([
                    'aktywne'   => false,
                    'zdjete_at' => $czas,
                ]);
                $zdjete++;
                $this->historia($teraz, 'zdjete', (string) $stary['lista'], (string) $stary['wpis'], (string) $stary['nasz_prefiks'], 'wpis zniknął z pliku ' . $stary['lista']);
            }

            $rekordy = implode(', ', array_map(
                static fn (string $l, array $p): string => $l . '=' . count($p['wpisy']),
                array_keys($pliki),
                $pliki,
            ));
            $this->historia($teraz, 'pobrano', null, null, null, sprintf(
                'pliki OK (%s); trafienia=%d, nowe=%d, zdjęte=%d',
                $rekordy,
                count($trafienia),
                $nowe,
                $zdjete,
            ));

            $this->zapiszStan([
                'ostatnie_ok_at'    => $czas,
                'nastepne_po'       => self::fmt(Harmonogram::poSukcesie($teraz, $this->interwalGodzin, ($this->losowaMinuta)())),
                'bledy_z_rzedu'     => 0,
                'wynik'             => $trafienia === [] ? 'czysto' : 'trafienie',
                'wynik_at'          => $czas,
                'aktywne_trafienia' => count($trafienia),
            ]);

            $this->db->transComplete();
        } catch (\Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }

        return ['nowe' => $nowe, 'zdjete' => $zdjete];
    }

    /**
     * Incydent na każde aktywne trafienie (dedup: zrodlo + klucz_dedupu w IncydentZapis).
     */
    private function zapiszIncydenty(int $teraz, ?int $importId): int
    {
        $n = 0;
        $aktywne = $this->db->table('mcs_spamhaus_trafienia')->where('aktywne', true)->get()->getResultArray();
        foreach ($aktywne as $t) {
            $asn   = $t['typ'] === 'asn';
            $kat   = $asn ? self::KATEGORIA_ASN : self::KATEGORIA_DROP;
            $waga  = $this->waga->wylicz($kat, ['waga_jawna' => 'critical']);
            $wynik = $this->zapis->zapisz([
                'zrodlo'           => self::ZRODLO,
                'klucz_dedupu'     => 'drop|' . $t['lista'] . '|' . $t['wpis'] . '|' . $t['nasz_prefiks'],
                'podmiot_typ'      => 'ip',
                'podmiot'          => (string) $t['podmiot'],
                'zaobserwowano_at' => self::fmt($teraz),
                'waga'             => $waga['waga'],
                'wersja_wagi'      => $waga['wersja'],
                'kategoria'        => $kat,
                'tytul'            => mb_substr($this->opisTrafienia($t), 0, 255),
                'port'             => null,
                'protokol'         => null,
                'import_id'        => $importId,
                'id_w_zrodle'      => (string) $t['id'],
                'payload'          => [
                    'lista'        => $t['lista'],
                    'wpis'         => $t['wpis'],
                    'sblid'        => $t['sblid'],
                    'nasz_prefiks' => $t['nasz_prefiks'],
                    'zakres'       => $t['zakres'],
                    'waga_meta'    => $waga,
                ],
            ]);
            $this->db->table('mcs_spamhaus_trafienia')->where('id', $t['id'])->update(['incydent_id' => $wynik['incydent_id']]);
            $n++;
        }

        return $n;
    }

    /**
     * @param array<string, mixed> $t
     */
    private function opisTrafienia(array $t): string
    {
        if (($t['typ'] ?? '') === 'asn') {
            return 'Spamhaus ASN-DROP: ' . $t['wpis'] . ' na liście (prefiks ' . $t['nasz_prefiks'] . ')';
        }
        $sbl = (string) ($t['sblid'] ?? '') !== '' ? ' ' . $t['sblid'] : '';

        return 'Spamhaus DROP' . $sbl . ': ' . $t['wpis'] . ' nakłada się na ' . $t['nasz_prefiks'];
    }

    /**
     * @return array<string, mixed>
     */
    public function stan(): array
    {
        $w = $this->db->table('mcs_spamhaus_stan')->where('id', 1)->get()->getRowArray();
        if (! is_array($w)) {
            $this->db->table('mcs_spamhaus_stan')->insert(['id' => 1, 'wynik' => 'brak', 'bledy_z_rzedu' => 0, 'aktywne_trafienia' => 0]);

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
        $this->db->table('mcs_spamhaus_stan')->where('id', 1)->update($dane);
    }

    private function historia(int $teraz, string $zdarzenie, ?string $lista, ?string $wpis, ?string $prefiks, string $opis): void
    {
        $this->db->table('mcs_spamhaus_historia')->insert([
            'at'           => self::fmt($teraz),
            'zdarzenie'    => $zdarzenie,
            'lista'        => $lista,
            'wpis'         => $wpis,
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
