<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Cache lookup multi-DNSBL — panel /ip i dashboard.
 */
class EnrichmentDnsblModel extends Model
{
    protected $table            = 'mcs_enrichment_dnsbl';
    protected $primaryKey       = 'ip';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'ip',
        'listed_any',
        'listy_json',
        'raw_json',
        'zrodlo_danych',
        'zakres_skanu',
        'checked_at',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoIp(string $ip): ?array
    {
        $wiersz = $this->find($ip);

        return is_array($wiersz) ? $wiersz : null;
    }

    /**
     * @param array<string, mixed> $dane
     */
    public function upsertDlaIp(string $ip, array $dane): void
    {
        $teraz   = gmdate('Y-m-d H:i:sP');
        $istniej = $this->znajdzPoIp($ip);

        $payload = array_merge($dane, [
            'ip'         => $ip,
            'updated_at' => $teraz,
            'checked_at' => $dane['checked_at'] ?? $teraz,
        ]);

        if ($istniej === null) {
            $payload['created_at'] = $teraz;
            $this->insert($payload);

            return;
        }

        $this->update($ip, $payload);
    }

    public function cacheWazny(?array $wiersz, int $ttlSek): bool
    {
        if ($wiersz === null || $ttlSek <= 0) {
            return false;
        }
        $checked = $wiersz['checked_at'] ?? null;
        if (! is_string($checked) || $checked === '') {
            return false;
        }
        $ts = strtotime($checked);
        if ($ts === false) {
            return false;
        }

        return (time() - $ts) < $ttlSek;
    }

    /**
     * Aktualnie listed IP (do dashboardu).
     *
     * @return list<array<string, mixed>>
     */
    public function listedAktualne(?callable $akceptujIp = null, ?string $listaFiltr = null, int $limit = 500): array
    {
        $builder = $this->builder();
        $builder->where('listed_any', true);
        $builder->orderBy('checked_at', 'DESC');
        $builder->limit(max(1, min(2000, $limit)));
        $rows = $builder->get()->getResultArray();

        $out = [];
        foreach ($rows as $row) {
            $ip = (string) ($row['ip'] ?? '');
            if ($ip === '') {
                continue;
            }
            if ($akceptujIp !== null && ! $akceptujIp($ip)) {
                continue;
            }
            if ($listaFiltr !== null && $listaFiltr !== '') {
                $listy = $row['listy_json'] ?? [];
                if (is_string($listy)) {
                    $decoded = json_decode($listy, true);
                    $listy   = is_array($decoded) ? $decoded : [];
                }
                if (! is_array($listy) || ! isset($listy[$listaFiltr]['listed']) || ! $listy[$listaFiltr]['listed']) {
                    continue;
                }
            }
            $out[] = $row;
        }

        return $out;
    }
}
