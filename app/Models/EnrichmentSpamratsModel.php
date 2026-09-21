<?php

namespace App\Models;

use CodeIgniter\Model;

/**
 * Cache lookup SpamRats — nie łączy się z mcs_incydenty.
 */
class EnrichmentSpamratsModel extends Model
{
    protected $table            = 'mcs_enrichment_spamrats';
    protected $primaryKey       = 'ip';
    protected $useAutoIncrement = false;
    protected $returnType       = 'array';
    protected $useTimestamps    = false;
    protected $allowedFields    = [
        'ip',
        'listed',
        'listed_dyna',
        'listed_noptr',
        'listed_spam',
        'listed_auth',
        'kody_json',
        'raw_json',
        'zrodlo_danych',
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
}
