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
     * Trendy dzienne z mcs_obserwacje (widoczne incydenty): wolumen, nowe vs powtarzające, per źródło.
     *
     * „Nowe” = obserwacja w dniu pierwszego widzenia incydentu; „powtarzające” = pozostałe.
     *
     * @param (callable(string): bool)|null $akceptujIp
     *
     * @return array{
     *   okno_dni: int,
     *   dni: list<array{
     *     dzien: string,
     *     obserwacje: int,
     *     nowe: int,
     *     powtarzajace: int,
     *     zrodla: array<string, int>
     *   }>,
     *   zrodla_suma: array<string, int>,
     *   max_obserwacje: int,
     *   suma_obserwacji: int
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
            ->select('o.zaobserwowano_at, i.pierwsze_widziane, i.zrodlo, i.podmiot')
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

        /** @var array<string, array{obserwacje: int, nowe: int, powtarzajace: int, zrodla: array<string, int>}> $poDniu */
        $poDniu     = [];
        $zrodlaSuma = [];
        $suma       = 0;

        foreach ($wiersze as $wiersz) {
            $podmiot = (string) ($wiersz['podmiot'] ?? '');
            if ($podmiot === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($podmiot)) {
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
                    'obserwacje'    => 0,
                    'nowe'          => 0,
                    'powtarzajace'  => 0,
                    'zrodla'        => [],
                ];
            }
            $poDniu[$dzienObs]['obserwacje']++;
            if ($nowe) {
                $poDniu[$dzienObs]['nowe']++;
            } else {
                $poDniu[$dzienObs]['powtarzajace']++;
            }
            if ($z !== '') {
                $poDniu[$dzienObs]['zrodla'][$z] = ($poDniu[$dzienObs]['zrodla'][$z] ?? 0) + 1;
                $zrodlaSuma[$z]                  = ($zrodlaSuma[$z] ?? 0) + 1;
            }
            $suma++;
        }

        // Wypełnij ciągłe okno (UTC), także dni bez obserwacji — bez pustego „vanity chart”.
        $dzisiaj = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        $dni     = [];
        $maxObs  = 0;
        for ($i = $oknoDni - 1; $i >= 0; $i--) {
            $dzien = $dzisiaj->modify("-{$i} days")->format('Y-m-d');
            $dane  = $poDniu[$dzien] ?? [
                'obserwacje'   => 0,
                'nowe'         => 0,
                'powtarzajace' => 0,
                'zrodla'       => [],
            ];
            $maxObs = max($maxObs, $dane['obserwacje']);
            ksort($dane['zrodla']);
            $dni[] = [
                'dzien'         => $dzien,
                'obserwacje'    => $dane['obserwacje'],
                'nowe'          => $dane['nowe'],
                'powtarzajace'  => $dane['powtarzajace'],
                'zrodla'        => $dane['zrodla'],
            ];
        }

        arsort($zrodlaSuma, SORT_NUMERIC);

        return [
            'okno_dni'         => $oknoDni,
            'dni'              => $dni,
            'zrodla_suma'      => $zrodlaSuma,
            'max_obserwacje'   => $maxObs,
            'suma_obserwacji'  => $suma,
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
