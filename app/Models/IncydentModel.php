<?php

namespace App\Models;

use CodeIgniter\Model;

class IncydentModel extends Model
{
    protected $table            = 'mcs_incydenty';
    protected $primaryKey       = 'id';
    protected $useAutoIncrement = true;
    protected $returnType       = 'array';
    protected $useSoftDeletes   = false;
    protected $allowedFields    = [
        'zrodlo',
        'klucz_dedupu',
        'podmiot_typ',
        'podmiot',
        'pierwsze_widziane',
        'ostatnio_widziane',
        'waga',
        'kategoria',
        'tytul',
        'port',
        'protokol',
        'ukryty_w_ui',
    ];
    protected $useTimestamps = false;

    /**
     * Lista skrótów incydentów dla podmiotu IP (strona /ip/{ip}).
     *
     * Pomija źródła z mcs_pluginy.ukryty_w_ui oraz własne ukryty_w_ui incydentu.
     * Sort: waga (critical→low), potem ostatnio_widziane malejąco.
     *
     * @return list<array<string, mixed>>
     */
    public function dlaPodmiotuIp(string $ip, ?string $zrodlo = null): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.*')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        if ($zrodlo !== null && $zrodlo !== '') {
            $builder->where('i.zrodlo', $zrodlo);
        }

        $builder->orderBy(
            "CASE i.waga
                WHEN 'critical' THEN 4
                WHEN 'high' THEN 3
                WHEN 'medium' THEN 2
                WHEN 'low' THEN 1
                ELSE 0
            END",
            'DESC',
            false,
        );
        $builder->orderBy('i.ostatnio_widziane', 'DESC');

        $wiersze = $builder->get()->getResultArray();

        return is_array($wiersze) ? $wiersze : [];
    }

    /**
     * Distinct źródła widoczne dla IP (bez filtra zrodlo) — do listy filtrów UI.
     *
     * @return list<string>
     */
    public function zrodlaDlaPodmiotuIp(string $ip): array
    {
        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.zrodlo')
            ->distinct()
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd()
            ->orderBy('i.zrodlo', 'ASC');

        $wiersze = $builder->get()->getResultArray();
        $lista   = [];

        foreach ($wiersze as $wiersz) {
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo'])) {
                $lista[] = $wiersz['zrodlo'];
            }
        }

        return $lista;
    }

    /**
     * Agregat pod API badge: okno ostatnio_widziane, bez źródeł/incydentów ukrytych w UI.
     *
     * @return array{okno_dni: int, liczba: int, max_waga: ?string, liczba_zrodel: int}
     */
    public function agregatBadgeDlaIp(string $ip, int $oknoDni = 7): array
    {
        $oknoDni = max(1, $oknoDni);
        $od      = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$oknoDni} days")
            ->format('Y-m-d H:i:s');

        $builder = $this->db->table($this->table . ' AS i')
            ->select('i.waga, i.zrodlo')
            ->join('mcs_pluginy AS p', 'p.zrodlo = i.zrodlo', 'left')
            ->where('i.podmiot_typ', 'ip')
            ->where('i.podmiot', $ip)
            ->where('i.ukryty_w_ui', false)
            ->where('i.ostatnio_widziane >=', $od)
            ->groupStart()
                ->where('p.ukryty_w_ui', false)
                ->orWhere('p.zrodlo', null)
            ->groupEnd();

        $wiersze = $builder->get()->getResultArray();
        if (! is_array($wiersze) || $wiersze === []) {
            return [
                'okno_dni'      => $oknoDni,
                'liczba'        => 0,
                'max_waga'      => null,
                'liczba_zrodel' => 0,
            ];
        }

        $ranga = [
            'low'      => 1,
            'medium'   => 2,
            'high'     => 3,
            'critical' => 4,
        ];
        $maxRanga = 0;
        $maxWaga  = null;
        $zrodla   = [];

        foreach ($wiersze as $wiersz) {
            $waga = isset($wiersz['waga']) && is_string($wiersz['waga']) ? $wiersz['waga'] : '';
            $nr   = $ranga[$waga] ?? 0;
            if ($nr > $maxRanga) {
                $maxRanga = $nr;
                $maxWaga  = $waga;
            }
            if (isset($wiersz['zrodlo']) && is_string($wiersz['zrodlo'])) {
                $zrodla[$wiersz['zrodlo']] = true;
            }
        }

        return [
            'okno_dni'      => $oknoDni,
            'liczba'        => count($wiersze),
            'max_waga'      => $maxWaga,
            'liczba_zrodel' => count($zrodla),
        ];
    }
}
