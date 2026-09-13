<?php

/**
 * Handler LMS+ — badge MCS na karcie hosta (nodeinfo).
 */
class McsBadgeHandler
{
    public function smartyInit($hook_data)
    {
        if (!is_object($hook_data) || !method_exists($hook_data, 'getTemplateDir')) {
            return $hook_data;
        }

        $dirs = $hook_data->getTemplateDir();
        $pluginTemplates = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates';
        if (is_dir($pluginTemplates)) {
            array_unshift($dirs, $pluginTemplates);
            $hook_data->setTemplateDir($dirs);
        }

        return $hook_data;
    }

    /**
     * @param array<string, mixed> $hook_data
     * @return array<string, mixed>
     */
    public function nodeInfoBeforeDisplay(array $hook_data = [])
    {
        try {
            $ip = $this->resolveNodeIp($hook_data);
            if ($ip === null) {
                return $hook_data;
            }

            $badge = $this->fetchBadgeSafe($ip);
            if (!isset($hook_data['nodeinfo']) || !is_array($hook_data['nodeinfo'])) {
                $hook_data['nodeinfo'] = [];
            }
            $hook_data['nodeinfo']['mcs_badge'] = $badge;
            $hook_data['nodeinfo']['mcs_badge_ip'] = $ip;
        } catch (Throwable $e) {
            error_log('[LMSMcsBadgePlugin] nodeInfoBeforeDisplay: ' . $e->getMessage());
        }

        return $hook_data;
    }

    /**
     * @param array<string, mixed> $hook_data
     */
    private function resolveNodeIp(array $hook_data): ?string
    {
        $nodeid = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
        if (!$nodeid && isset($_GET['id'])) {
            $nodeid = filter_var($_GET['id'], FILTER_VALIDATE_INT);
        }
        if ($nodeid) {
            try {
                $DB = LMSDB::getInstance();
                $node = $DB->GetRow('SELECT ipaddr, ipaddr_pub FROM nodes WHERE id = ?', [$nodeid]);
                if (is_array($node)) {
                    if (!empty($node['ipaddr']) && (int) $node['ipaddr'] !== 0) {
                        $ipFn = function_exists('long_ip') ? 'long_ip' : (function_exists('long2ip') ? 'long2ip' : null);
                        if ($ipFn !== null) {
                            return $ipFn((int) $node['ipaddr']);
                        }
                    }
                    if (!empty($node['ipaddr_pub']) && (int) $node['ipaddr_pub'] !== 0) {
                        $ipFn = function_exists('long_ip') ? 'long_ip' : (function_exists('long2ip') ? 'long2ip' : null);
                        if ($ipFn !== null) {
                            return $ipFn((int) $node['ipaddr_pub']);
                        }
                    }
                }
            } catch (Throwable $e) {
                // fallback poniżej
            }
        }

        if (isset($hook_data['nodeinfo']) && is_array($hook_data['nodeinfo'])) {
            $n = $hook_data['nodeinfo'];
            foreach (['ipaddr', 'ip', 'address'] as $k) {
                if (!empty($n[$k]) && is_string($n[$k]) && filter_var($n[$k], FILTER_VALIDATE_IP)) {
                    return $n[$k];
                }
            }
            if (!empty($n['ipaddr']) && is_numeric($n['ipaddr']) && (int) $n['ipaddr'] !== 0) {
                return long_ip((int) $n['ipaddr']);
            }
        }

        return null;
    }

    /**
     * @return array{
     *   api_niedostepne: bool,
     *   liczba: int,
     *   max_waga: ?string,
     *   url: string,
     *   okno_dni: int,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   kategorie: list<string>,
     *   przyklady: list<string>
     * }
     */
    private function fetchBadgeSafe(string $ip): array
    {
        $fallback = $this->pustyBadge(true);

        $cfg = $this->readMcsConfig();
        if ($cfg['base_url'] === '' || $cfg['badge_token'] === '') {
            return $fallback;
        }

        try {
            $cache = new McsBadgeCache($cfg['cache_ttl']);
            $cached = $cache->get($ip);
            if (is_array($cached) && isset($cached['liczba'])) {
                return $this->normalizujBadge($cached, !empty($cached['api_niedostepne']));
            }

            $client = new McsBadgeClient($cfg['base_url'], $cfg['badge_token'], $cfg['timeout']);
            $res = $client->badgeDlaIp($ip);
            if (!$res['ok'] || !is_array($res['data'])) {
                return $fallback;
            }

            $out = $this->normalizujBadge($res['data'], false);
            $cache->set($ip, $out);

            return $out;
        } catch (Throwable $e) {
            return $fallback;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array{
     *   api_niedostepne: bool,
     *   liczba: int,
     *   max_waga: ?string,
     *   url: string,
     *   okno_dni: int,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   zrodla_etykiety: list<string>,
     *   kategorie: list<string>,
     *   kategorie_chip: list<string>,
     *   przyklady: list<string>
     * }
     */
    private function normalizujBadge(array $data, bool $apiNiedostepne): array
    {
        $maxWaga = isset($data['max_waga']) && $data['max_waga'] !== '' && $data['max_waga'] !== null
            ? (string) $data['max_waga']
            : null;

        $zrodla = $this->listaStringow($data['zrodla'] ?? []);
        $kategorie = $this->listaStringow($data['kategorie'] ?? []);

        return [
            'api_niedostepne'  => $apiNiedostepne,
            'liczba'           => (int) ($data['liczba'] ?? 0),
            'max_waga'         => $maxWaga,
            'url'              => (string) ($data['url'] ?? ''),
            'okno_dni'         => (int) ($data['okno_dni'] ?? 7),
            'liczba_zrodel'    => (int) ($data['liczba_zrodel'] ?? 0),
            'zrodla'           => $zrodla,
            'zrodla_etykiety'  => $this->etykietyZrodel($zrodla),
            'kategorie'        => $kategorie,
            'kategorie_chip'   => $this->kategorieChip($kategorie, 2),
            'przyklady'        => $this->listaStringow($data['przyklady'] ?? []),
        ];
    }

    /**
     * @return array{
     *   api_niedostepne: bool,
     *   liczba: int,
     *   max_waga: ?string,
     *   url: string,
     *   okno_dni: int,
     *   liczba_zrodel: int,
     *   zrodla: list<string>,
     *   zrodla_etykiety: list<string>,
     *   kategorie: list<string>,
     *   kategorie_chip: list<string>,
     *   przyklady: list<string>
     * }
     */
    private function pustyBadge(bool $apiNiedostepne): array
    {
        return [
            'api_niedostepne'  => $apiNiedostepne,
            'liczba'           => 0,
            'max_waga'         => null,
            'url'              => '',
            'okno_dni'         => 7,
            'liczba_zrodel'    => 0,
            'zrodla'           => [],
            'zrodla_etykiety'  => [],
            'kategorie'        => [],
            'kategorie_chip'   => [],
            'przyklady'        => [],
        ];
    }

    /**
     * @param list<string> $zrodla
     * @return list<string>
     */
    private function etykietyZrodel(array $zrodla): array
    {
        $mapa = [
            'cert_n6'      => 'N6',
            'shadowserver' => 'Shadowserver',
            'play'         => 'Play',
            'snds'         => 'SNDS',
            'abuseipdb'    => 'AbuseIPDB',
            'mojecert'     => 'MojeCERT',
            'wanguard'     => 'Wanguard',
        ];
        $out = [];
        foreach ($zrodla as $kod) {
            $out[] = $mapa[$kod] ?? $kod;
        }

        return $out;
    }

    /**
     * @param list<string> $kategorie
     * @return list<string>
     */
    private function kategorieChip(array $kategorie, int $limit): array
    {
        $out = [];
        foreach ($kategorie as $kat) {
            if (strcasecmp($kat, 'other') === 0) {
                continue;
            }
            $out[] = $kat;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param mixed $wartosc
     * @return list<string>
     */
    private function listaStringow($wartosc): array
    {
        if (!is_array($wartosc)) {
            return [];
        }
        $out = [];
        foreach ($wartosc as $el) {
            if (!is_string($el) && !is_int($el) && !is_float($el)) {
                continue;
            }
            $s = trim((string) $el);
            if ($s === '') {
                continue;
            }
            $out[] = $s;
        }

        return $out;
    }

    /**
     * @return array{base_url:string,badge_token:string,cache_ttl:int,timeout:int}
     */
    private function readMcsConfig(): array
    {
        $base = '';
        $token = '';
        $ttl = 120;
        $timeout = 5;

        if (class_exists('ConfigHelper')) {
            $base = (string) ConfigHelper::getConfig('mcs.base_url', ConfigHelper::getConfig('mcs.baseURL', ''));
            $token = (string) ConfigHelper::getConfig('mcs.badge_token', ConfigHelper::getConfig('mcs.badgeToken', ''));
            $ttl = (int) ConfigHelper::getConfig('mcs.cache_ttl', 120);
            $timeout = (int) ConfigHelper::getConfig('mcs.timeout', 5);
        }

        return [
            'base_url'    => trim($base),
            'badge_token' => trim($token),
            'cache_ttl'   => max(0, $ttl),
            'timeout'     => max(1, $timeout),
        ];
    }
}
