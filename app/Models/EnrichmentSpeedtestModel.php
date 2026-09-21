<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Historia testów Speedtest — nie łączy się z mcs_incydenty.
 */
class EnrichmentSpeedtestModel extends Model
{
    protected $table            = 'mcs_enrichment_speedtest';
    protected $primaryKey       = 'test_id';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'test_id',
        'ip',
        'zaobserwowano_at',
        'download_kbps',
        'upload_kbps',
        'latency_ms',
        'isp',
        'platform',
        'server_name',
        'raw_json',
        'zrodlo_danych',
        'created_at',
    ];

    /**
     * @return array<string, mixed>|null
     */
    public function znajdzPoTestId(string $testId): ?array
    {
        $wiersz = $this->find($testId);

        return is_array($wiersz) ? $wiersz : null;
    }

    /**
     * @param array<string, mixed> $dane
     */
    public function upsertPoTestId(string $testId, array $dane): void
    {
        $istniej = $this->znajdzPoTestId($testId);
        $payload = array_merge($dane, ['test_id' => $testId]);

        if ($istniej === null) {
            $payload['created_at'] = $dane['created_at'] ?? gmdate('Y-m-d H:i:sP');
            $this->insert($payload);

            return;
        }

        unset($payload['created_at']);
        $this->update($testId, $payload);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dlaIp(string $ip, int $limit = 20): array
    {
        $limit = max(1, $limit);
        $lista = $this->where('ip', $ip)
            ->orderBy('zaobserwowano_at', 'DESC')
            ->findAll($limit);

        return is_array($lista) ? $lista : [];
    }

    /**
     * Prefiks IPv4 /24 (np. „91.231.80.”) — dopasowanie LIKE.
     *
     * @return list<array<string, mixed>>
     */
    public function dlaPrefiksu24(string $prefiks24, int $limit = 50): array
    {
        $prefiks24 = trim($prefiks24);
        if ($prefiks24 === '' || ! str_ends_with($prefiks24, '.')) {
            return [];
        }

        $limit = max(1, $limit);
        $lista = $this->like('ip', $prefiks24, 'after')
            ->orderBy('zaobserwowano_at', 'DESC')
            ->findAll($limit);

        return is_array($lista) ? $lista : [];
    }

    /**
     * Wszystkie wiersze (opcjonalnie od daty ISO), sortowane malejąco po czasie.
     *
     * @return list<array<string, mixed>>
     */
    public function wszystkieOd(?string $odIso = null, int $limit = 10000): array
    {
        $limit = max(1, $limit);
        $builder = $this->builder();
        if ($odIso !== null && $odIso !== '') {
            $builder->where('zaobserwowano_at >=', $odIso);
        }
        $lista = $builder->orderBy('zaobserwowano_at', 'DESC')->get($limit)->getResultArray();

        return is_array($lista) ? $lista : [];
    }
}
