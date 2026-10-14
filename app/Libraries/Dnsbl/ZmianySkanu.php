<?php

namespace App\Libraries\Dnsbl;

use App\Libraries\IncydentZapis;
use App\Libraries\WagaV1;
use CodeIgniter\Database\BaseConnection;

/**
 * Skaner DNSBL, E3: zdarzenia nowy/zdjęty, incydenty dla nowych listingów, ochrona przed falą, retencja.
 *
 * Incydenty: zrodlo='dnsbl', klucz_dedupu='{ip}|{kod}' — ten sam klucz co stary mechanizm
 * (DnsblSerwis, plugin dnsbl). Para ip+strefa ma więc jeden incydent; drugi mechanizm dopisuje obserwację.
 */
class ZmianySkanu
{
    /** Strefy o wysokiej wadze — nowy listing tworzy incydent. Pozostałe tylko informacyjnie. */
    public const STREFY_INCYDENT = ['spamcop', 'barracuda', 'psbl', 'dronebl', 'spamrats'];

    /** Więcej nowych listingów w jednym skanie = możliwy błąd skanu (np. resolver). */
    public const PROG_FALI = 50;

    public const ZRODLO = 'dnsbl';

    private BaseConnection $db;
    private ?IncydentZapis $zapis;
    private ?WagaV1 $waga;

    public function __construct(BaseConnection $db, ?IncydentZapis $zapis = null, ?WagaV1 $waga = null)
    {
        $this->db    = $db;
        $this->zapis = $zapis;
        $this->waga  = $waga;
    }

    public static function kluczDedupu(string $ip, string $kod): string
    {
        return $ip . '|' . $kod;
    }

    /**
     * Które nowe listingi dostają incydent.
     *
     * @param list<array{kod: string, ip: string, kody: string, txt: string}> $nowe
     *
     * @return array{fala: bool, do_incydentu: list<array{kod: string, ip: string, kody: string, txt: string}>}
     */
    public static function wybierzDoIncydentu(array $nowe, int $prog = self::PROG_FALI): array
    {
        if (count($nowe) > $prog) {
            return ['fala' => true, 'do_incydentu' => []];
        }
        $out = [];
        foreach ($nowe as $n) {
            if (! in_array($n['kod'], self::STREFY_INCYDENT, true) || str_contains($n['ip'], '/')) {
                continue;
            }
            $out[] = $n;
        }

        return ['fala' => false, 'do_incydentu' => $out];
    }

    /**
     * Zapis zdarzeń + incydenty. Wywołać po zapisie stanu listingów.
     *
     * @param list<array{kod: string, ip: string, kody: string, txt: string}> $nowe
     * @param list<array{kod: string, ip: string}>                           $zdjete
     *
     * @return array{nowe: int, zdjete: int, incydenty: int, fala: bool}
     */
    public function zapisz(int $skanId, array $nowe, array $zdjete, ?string $teraz = null): array
    {
        $teraz = $teraz ?? gmdate('Y-m-d H:i:sP');
        $wyb   = self::wybierzDoIncydentu($nowe);
        $inc   = [];
        $utw   = 0;

        foreach ($wyb['do_incydentu'] as $n) {
            $w = $this->zapis()->zapisz([
                'zrodlo'           => self::ZRODLO,
                'klucz_dedupu'     => self::kluczDedupu($n['ip'], $n['kod']),
                'podmiot_typ'      => 'ip',
                'podmiot'          => $n['ip'],
                'zaobserwowano_at' => $teraz,
                'waga'             => $this->wagaMeta()['waga'],
                'wersja_wagi'      => $this->wagaMeta()['wersja'],
                'kategoria'        => 'reputation',
                'tytul'            => 'DNSBL ' . $n['kod'] . ': ' . $n['ip'],
                'id_w_zrodle'      => 'skan#' . $skanId,
                'payload'          => [
                    'lista'   => $n['kod'],
                    'kody'    => $n['kody'] !== '' ? explode(',', $n['kody']) : [],
                    'txt'     => $n['txt'],
                    'skan_id' => $skanId,
                    'zrodlo'  => 'skaner',
                ],
            ]);
            $inc[self::kluczDedupu($n['ip'], $n['kod'])] = (int) $w['incydent_id'];
            if ($w['utworzono_incydent']) {
                $utw++;
            }
        }

        $uwagaNowy = $wyb['fala'] ? 'fala: incydent pominięty (możliwy błąd skanu)' : '';
        $wiersze   = [];
        foreach ($nowe as $n) {
            $k         = self::kluczDedupu($n['ip'], $n['kod']);
            $wiersze[] = [
                'at' => $teraz, 'ip' => $n['ip'], 'strefa_kod' => $n['kod'], 'typ' => 'nowy', 'skan_id' => $skanId,
                'kody' => mb_substr($n['kody'], 0, 255), 'incydent_id' => $inc[$k] ?? null,
                'uwagi' => isset($inc[$k]) ? 'incydent' : ($uwagaNowy !== '' ? $uwagaNowy : 'bez incydentu (strefa informacyjna/niska waga)'),
            ];
        }
        foreach ($zdjete as $z) {
            $id        = $this->incydentId($z['ip'], $z['kod']);
            $wiersze[] = [
                'at' => $teraz, 'ip' => $z['ip'], 'strefa_kod' => $z['kod'], 'typ' => 'zdjety', 'skan_id' => $skanId,
                'kody' => '', 'incydent_id' => $id,
                'uwagi' => $id !== null ? 'listing zdjęty; incydent bez statusu zamknięcia (model)' : 'listing zdjęty',
            ];
        }
        foreach (array_chunk($wiersze, 500) as $partia) {
            $this->db->table('mcs_dnsbl_zdarzenia')->insertBatch($partia);
        }

        return ['nowe' => count($nowe), 'zdjete' => count($zdjete), 'incydenty' => $utw, 'fala' => $wyb['fala']];
    }

    /**
     * Usuwa wyniki (mcs_dnsbl_wyniki) skanów starszych niż $dni. Wiersze skanów zostają (trend).
     */
    public function czyscRetencje(int $dni, ?int $teraz = null): int
    {
        if ($dni <= 0) {
            return 0;
        }
        $granica = gmdate('Y-m-d H:i:sP', ($teraz ?? time()) - $dni * 86400);
        $ids     = array_map('intval', array_column(
            $this->db->table('mcs_dnsbl_skany')->select('id')->where('utworzono_at <', $granica)->get()->getResultArray(),
            'id',
        ));
        $usunieto = 0;
        foreach (array_chunk($ids, 500) as $partia) {
            $this->db->table('mcs_dnsbl_wyniki')->whereIn('skan_id', $partia)->delete();
            $usunieto += $this->db->affectedRows();
        }

        return $usunieto;
    }

    private function incydentId(string $ip, string $kod): ?int
    {
        $r = $this->db->table('mcs_incydenty')->select('id')
            ->where('zrodlo', self::ZRODLO)->where('klucz_dedupu', self::kluczDedupu($ip, $kod))
            ->get()->getRowArray();

        return is_array($r) ? (int) $r['id'] : null;
    }

    private function zapis(): IncydentZapis
    {
        return $this->zapis ??= new IncydentZapis($this->db);
    }

    /**
     * @return array{waga: string, wersja: string}
     */
    private function wagaMeta(): array
    {
        $m = ($this->waga ??= new WagaV1())->wylicz('reputation');

        return ['waga' => (string) $m['waga'], 'wersja' => (string) $m['wersja']];
    }
}
