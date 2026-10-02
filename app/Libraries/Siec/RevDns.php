<?php

namespace App\Libraries\Siec;

use App\Models\UstawieniaModel;
use CodeIgniter\Cache\CacheInterface;

/**
 * Reverse DNS (PTR) na żądanie + krótki cache.
 * Włączane kluczem mcs_ustawienia: pokazuj_rev_dns (domyślnie wyłączone).
 */
final class RevDns
{
    public const KLUCZ_WLACZONE = 'pokazuj_rev_dns';

    /** Sentinel w cache: brak PTR / NXDOMAIN. */
    private const BRAK = '__brak__';

    private const CACHE_PREFIX = 'mcs_revdns_';

    private ?UstawieniaModel $ustawienia;

    /** @var callable(string):(?string)|null */
    private $resolver;

    private ?CacheInterface $cacheCi;

    /** @var array{get?:callable,set?:callable}|null */
    private ?array $cacheArr;

    private int $ttl;

    private ?bool $cacheWlaczone = null;

    /**
     * @param callable(string):(?string)|null          $resolver  Lookup PTR; null = gethostbyaddr
     * @param CacheInterface|array{get:callable,set:callable}|null $cache
     */
    public function __construct(
        ?UstawieniaModel $ustawienia = null,
        ?callable $resolver = null,
        CacheInterface|array|null $cache = null,
        ?int $ttl = null,
    ) {
        $this->ustawienia = $ustawienia;
        $this->resolver   = $resolver;
        $this->ttl        = max(60, $ttl ?? \App\Libraries\OpcjeMcs::int('mcs.revDns.cacheTtl'));

        if (is_array($cache)) {
            $this->cacheArr = $cache;
            $this->cacheCi  = null;
        } else {
            $this->cacheArr = null;
            $this->cacheCi  = $cache;
        }
    }

    public function jestWlaczone(): bool
    {
        if ($this->cacheWlaczone !== null) {
            return $this->cacheWlaczone;
        }

        $model = $this->ustawienia ?? $this->sprobujModel();
        if ($model !== null) {
            try {
                $raw = $model->pobierz(self::KLUCZ_WLACZONE);
                if ($raw !== null) {
                    return $this->cacheWlaczone = \App\Models\PluginModel::jakoBool($raw);
                }
            } catch (\Throwable) {
                // brak tabeli — domyślnie wyłączone
            }
        }

        return $this->cacheWlaczone = false;
    }

    /**
     * Hostname PTR albo null (wyłączone / brak rekordu / błąd).
     *
     * @param bool $tylkoCache Gdy true — nie wykonuj lookupu (np. ranking IP na dashboardzie).
     */
    public function dlaIp(string $ip, bool $tylkoCache = false): ?string
    {
        if (! $this->jestWlaczone()) {
            return null;
        }

        $ip = trim($ip);
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        $klucz = self::CACHE_PREFIX . md5($ip);
        $hit   = $this->cacheGet($klucz);
        if ($hit !== null) {
            return $hit === self::BRAK ? null : (string) $hit;
        }

        if ($tylkoCache) {
            return null;
        }

        $host = $this->wykonajLookup($ip);
        $this->cacheSet($klucz, $host === null ? self::BRAK : $host);

        return $host;
    }

    /**
     * @param list<string> $ips
     *
     * @return array<string, string> ip → hostname (tylko trafienia z cache / bez cold lookup)
     */
    public function mapaZCache(array $ips): array
    {
        if (! $this->jestWlaczone()) {
            return [];
        }

        $out = [];
        foreach ($ips as $ip) {
            $host = $this->dlaIp((string) $ip, true);
            if ($host !== null && $host !== '') {
                $out[(string) $ip] = $host;
            }
        }

        return $out;
    }

    public function wyczyscCacheWlaczone(): void
    {
        $this->cacheWlaczone = null;
    }

    private function wykonajLookup(string $ip): ?string
    {
        if ($this->resolver !== null) {
            $host = ($this->resolver)($ip);

            return $this->normalizujHost(is_string($host) ? $host : null, $ip);
        }

        $host = @gethostbyaddr($ip);
        if ($host === false) {
            return null;
        }

        return $this->normalizujHost((string) $host, $ip);
    }

    private function normalizujHost(?string $host, string $ip): ?string
    {
        if ($host === null) {
            return null;
        }

        $host = strtolower(rtrim(trim($host), '.'));
        if ($host === '' || strcasecmp($host, $ip) === 0) {
            return null;
        }

        return $host;
    }

    private function cacheGet(string $klucz): mixed
    {
        if ($this->cacheArr !== null && isset($this->cacheArr['get'])) {
            return ($this->cacheArr['get'])($klucz);
        }

        try {
            $c = $this->cacheCi ?? cache();

            return $c->get($klucz);
        } catch (\Throwable) {
            return null;
        }
    }

    private function cacheSet(string $klucz, string $wartosc): void
    {
        if ($this->cacheArr !== null && isset($this->cacheArr['set'])) {
            ($this->cacheArr['set'])($klucz, $wartosc, $this->ttl);

            return;
        }

        try {
            $c = $this->cacheCi ?? cache();
            $c->save($klucz, $wartosc, $this->ttl);
        } catch (\Throwable) {
            // brak cache — lookup i tak zwróci wynik w tym requestcie
        }
    }

    private function sprobujModel(): ?UstawieniaModel
    {
        try {
            return model(UstawieniaModel::class);
        } catch (\Throwable) {
            return null;
        }
    }
}
