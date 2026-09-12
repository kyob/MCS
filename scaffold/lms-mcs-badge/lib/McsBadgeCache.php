<?php

/**
 * Krótki cache odpowiedzi badge (lista hostów nie powinna walić w MCS przy każdym odświeżeniu).
 * Domyślnie plik w sys_get_temp_dir(); bez sekretów w kluczu (tylko hash IP + base URL).
 */
class McsBadgeCache
{
    private string $dir;
    private int $ttlSec;
    private string $ns;

    public function __construct(int $ttlSec = 120, ?string $dir = null, string $namespace = 'mcs-badge')
    {
        $this->ttlSec = max(0, $ttlSec);
        $this->ns     = preg_replace('/[^a-zA-Z0-9_-]/', '', $namespace) ?: 'mcs-badge';
        $this->dir    = $dir !== null && $dir !== ''
            ? rtrim($dir, '/')
            : rtrim(sys_get_temp_dir(), '/') . '/' . $this->ns;

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0700, true);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    public function get(string $ip): ?array
    {
        if ($this->ttlSec === 0) {
            return null;
        }
        $path = $this->pathFor($ip);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['exp'], $decoded['data']) || !is_array($decoded['data'])) {
            return null;
        }
        if ((int) $decoded['exp'] < time()) {
            @unlink($path);
            return null;
        }

        return $decoded['data'];
    }

    /**
     * @param array<string,mixed> $data
     */
    public function set(string $ip, array $data): void
    {
        if ($this->ttlSec === 0) {
            return;
        }
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true)) {
            return;
        }
        $payload = json_encode([
            'exp'  => time() + $this->ttlSec,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);
        if ($payload === false) {
            return;
        }
        @file_put_contents($this->pathFor($ip), $payload, LOCK_EX);
    }

    private function pathFor(string $ip): string
    {
        return $this->dir . '/' . hash('sha256', $this->ns . '|' . $ip) . '.json';
    }
}
