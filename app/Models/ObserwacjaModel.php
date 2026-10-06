<?php

namespace App\Models;

use CodeIgniter\Model;

class ObserwacjaModel extends Model
{
    protected $table            = 'mcs_obserwacje';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'incydent_id',
        'import_id',
        'zaobserwowano_at',
        'payload_json',
        'id_w_zrodle',
    ];
    protected $useTimestamps = false;

    /**
     * Timeline obserwacji dla jednego incydentu (najnowsze pierwsze).
     *
     * @return list<array<string, mixed>>
     */
    public function dlaIncydentu(int $incydentId): array
    {
        if ($incydentId <= 0) {
            return [];
        }

        $wiersze = $this->where('incydent_id', $incydentId)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->findAll();

        if (! is_array($wiersze)) {
            return [];
        }

        $lista = [];
        foreach ($wiersze as $wiersz) {
            $payload = $wiersz['payload_json'] ?? null;
            if (is_string($payload) && $payload !== '') {
                $decoded = json_decode($payload, true);
                $wiersz['payload'] = is_array($decoded) ? $decoded : null;
            } else {
                $wiersz['payload'] = null;
            }
            $lista[] = $wiersz;
        }

        return $lista;
    }

    /**
     * Trendy dzienne: unikalne widoczne incydenty aktywne w dniu (po obserwacjach).
     *
     * „Nowe” = dzień pierwszego widzenia; „powtarzające” = aktywne, znane wcześniej.
     *
     * @param (callable(string): bool)|null $akceptujIp
     *
     * @return array{
     *   okno_dni: int,
     *   dni: list<array{
     *     dzien: string,
     *     incydenty: int,
     *     nowe: int,
     *     powtarzajace: int,
     *     zrodla: array<string, int>
     *   }>,
     *   zrodla_suma: array<string, int>,
     *   max_incydenty: int,
     *   suma_incydentow: int
     * }
     */
    public function trendyDzienne(
        int $oknoDni = 7,
        ?string $zrodlo = null,
        ?string $ip = null,
        ?string $kategoria = null,
        ?int $port = null,
        ?callable $akceptujIp = null,
    ): array {
        $oknoDni = max(1, $oknoDni);
        $od      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');

        $builder = $this->db->table($this->table . ' AS o')
            ->select('o.zaobserwowano_at, o.incydent_id, i.pierwsze_widziane, i.zrodlo, i.podmiot')
            ->join('mcs_incydenty AS i', 'i.id = o.incydent_id', 'inner')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.ukryty_w_ui', false)
            ->where('o.zaobserwowano_at >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }
        if ($ip !== null && $ip !== '') {
            $builder->where('i.podmiot', $ip);
        }
        if ($kategoria !== null && $kategoria !== '') {
            $builder->where('i.kategoria', $kategoria);
        }
        if ($port !== null && $port > 0) {
            $builder->where('i.port', $port);
        }

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze)) {
            $wiersze = [];
        }

        /** @var array<string, array{ids: array<int, bool>, noweIds: array<int, bool>, zrodlaIds: array<string, array<int, bool>>}> $poDniu */
        $poDniu = [];
        /** @var array<int, string> $incydentDoZrodla */
        $incydentDoZrodla = [];
        /** @var array<int, bool> $incydentyWOknie */
        $incydentyWOknie = [];

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($podmiot)) {
                continue;
            }

            $incydentId = (int) ($wiersz['incydent_id'] ?? 0);
            if ($incydentId <= 0) {
                continue;
            }

            $dzienObs = $this->dataUtc((string) ($wiersz['zaobserwowano_at'] ?? ''));
            if ($dzienObs === null) {
                continue;
            }

            $dzienPierwsze = $this->dataUtc((string) ($wiersz['pierwsze_widziane'] ?? ''));
            $z             = trim((string) ($wiersz['zrodlo'] ?? ''));
            $nowe          = $dzienPierwsze !== null && $dzienPierwsze === $dzienObs;

            if (! isset($poDniu[$dzienObs])) {
                $poDniu[$dzienObs] = [
                    'ids'       => [],
                    'noweIds'   => [],
                    'zrodlaIds' => [],
                ];
            }
            $poDniu[$dzienObs]['ids'][$incydentId] = true;
            $incydentyWOknie[$incydentId]          = true;
            if ($nowe) {
                $poDniu[$dzienObs]['noweIds'][$incydentId] = true;
            }
            if ($z !== '') {
                $poDniu[$dzienObs]['zrodlaIds'][$z][$incydentId] = true;
                $incydentDoZrodla[$incydentId]                   = $z;
            }
        }

        $zrodlaSuma = [];
        foreach ($incydentDoZrodla as $z) {
            $zrodlaSuma[$z] = ($zrodlaSuma[$z] ?? 0) + 1;
        }

        // Wypełnij ciągłe okno (UTC), także dni bez obserwacji — bez pustego „vanity chart”.
        $dzisiaj = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $dni     = [];
        $maxInc  = 0;
        for ($i = $oknoDni - 1; $i >= 0; $i--) {
            $dzien = $dzisiaj->modify("-{$i} days")->format('Y-m-d');
            $raw   = $poDniu[$dzien] ?? ['ids' => [], 'noweIds' => [], 'zrodlaIds' => []];
            $ids   = $raw['ids'];
            $noweN = count($raw['noweIds']);
            $incN  = count($ids);
            $powtN = max(0, $incN - $noweN);
            $zrodlaDnia = [];
            foreach ($raw['zrodlaIds'] as $kod => $idSet) {
                $zrodlaDnia[$kod] = count($idSet);
            }
            ksort($zrodlaDnia);
            $maxInc = max($maxInc, $incN);
            $dni[]  = [
                'dzien'        => $dzien,
                'incydenty'    => $incN,
                'nowe'         => $noweN,
                'powtarzajace' => $powtN,
                'zrodla'       => $zrodlaDnia,
            ];
        }

        arsort($zrodlaSuma, SORT_NUMERIC);

        return [
            'okno_dni'        => $oknoDni,
            'dni'             => $dni,
            'zrodla_suma'     => $zrodlaSuma,
            'max_incydenty'   => $maxInc,
            'suma_incydentow' => count($incydentyWOknie),
        ];
    }

    private function dataUtc(string $timestamp): ?string
    {
        $timestamp = trim($timestamp);
        if ($timestamp === '') {
            return null;
        }

        try {
            $dt = new \DateTimeImmutable($timestamp);

            return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }
}
