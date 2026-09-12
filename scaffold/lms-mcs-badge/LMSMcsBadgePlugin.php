<?php

/**
 * Cienki plugin LMS — badge MCS (Faza 5).
 *
 * Instalacja: skopiuj katalog do {LMS}/plugins/LMSMcsBadgePlugin
 * (użyj bin/install-to-lms.sh). Nie instalować automatycznie na prod bez akceptacji.
 *
 * Config (lms.ini), bez sekretów w gicie:
 *   [mcs]
 *   base_url    = https://mcs.example/
 *   badge_token = TEN_SAM_CO_mcs.badgeToken
 *   cache_ttl   = 120
 *   timeout     = 5
 *
 * Zależności LMS (ConfigHelper / Smarty / hooki) są luźne — dostosować do wersji LMS+.
 */

require_once __DIR__ . '/lib/McsBadgeClient.php';
require_once __DIR__ . '/lib/McsBadgeCache.php';

class LMSMcsBadgePlugin extends LMSPlugin
{
    public const PLUGIN_DIRECTORY = 'LMSMcsBadgePlugin';
    public const PLUGIN_DB_VERSION = 1;
    public const PLUGIN_NAME = 'MCS Badge';

    /**
     * @return array<string, mixed>
     */
    public static function getInfo(): array
    {
        return [
            'alias'       => self::PLUGIN_DIRECTORY,
            'name'        => self::PLUGIN_NAME,
            'description' => 'Jeden badge 7 dni + deep link do MCS (bez lokalnego indeksu).',
            'authors'     => [['name' => 'MCS', 'email' => '']],
            'version'     => '0.2.0-scaffold',
            'revision'    => self::PLUGIN_DB_VERSION,
            'minver'      => '1.0',
        ];
    }

    public function __construct()
    {
        parent::__construct();
        // Typowy hook LMS+ (jak LMSSNDSPlugin / LMSAbuseIPDBPlugin):
        if (method_exists($this, 'registerHook')) {
            $this->registerHook('nodeinfo_before_display', 'nodeInfoBeforeDisplay');
        }
    }

    /**
     * Hook nodeinfo — wstrzykuje badge do widoku hosta.
     * Kontrakt LMS różni się między wersjami: $smarty / $nodeinfo przekazywane przez ref.
     *
     * @param array<string,mixed> $hook_data
     * @return array<string,mixed>
     */
    public function nodeInfoBeforeDisplay(array $hook_data)
    {
        $ip = $this->extractNodeIp($hook_data);
        if ($ip === null) {
            return $hook_data;
        }

        $badge = $this->fetchBadgeSafe($ip);
        $html  = $this->renderBadgeHtml($ip, $badge);

        if ($html === '') {
            return $hook_data;
        }

        // Najczęstsze miejsca w LMS+: dołącz do panelu / boxów nodeinfo.
        if (isset($hook_data['smarty']) && is_object($hook_data['smarty']) && method_exists($hook_data['smarty'], 'assign')) {
            $hook_data['smarty']->assign('mcs_badge_html', $html);
            $prev = '';
            if (method_exists($hook_data['smarty'], 'getTemplateVars')) {
                $prev = (string) $hook_data['smarty']->getTemplateVars('nodeinfo_panels');
            }
            $hook_data['smarty']->assign('nodeinfo_panels', $prev . $html);
        }

        if (isset($hook_data['nodeinfo']) && is_array($hook_data['nodeinfo'])) {
            $hook_data['nodeinfo']['mcs_badge_html'] = $html;
        }

        return $hook_data;
    }

    /**
     * @param array<string,mixed> $hook_data
     */
    private function extractNodeIp(array $hook_data): ?string
    {
        $candidates = [];

        if (isset($hook_data['nodeinfo']) && is_array($hook_data['nodeinfo'])) {
            $n = $hook_data['nodeinfo'];
            foreach (['ipaddr', 'ip', 'address'] as $k) {
                if (!empty($n[$k]) && is_string($n[$k])) {
                    $candidates[] = $n[$k];
                }
            }
            // LMS czasem trzyma long IP
            if (!empty($n['ipaddr']) && is_int($n['ipaddr'])) {
                $candidates[] = long2ip($n['ipaddr']);
            }
        }

        foreach (['ip', 'ipaddr'] as $k) {
            if (!empty($hook_data[$k]) && is_string($hook_data[$k])) {
                $candidates[] = $hook_data[$k];
            }
        }

        foreach ($candidates as $raw) {
            $ip = trim((string) $raw);
            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
            // czasem "IP/mask"
            if (str_contains($ip, '/')) {
                $ip = explode('/', $ip, 2)[0];
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }

        return null;
    }

    /**
     * @return array{api_niedostepne:bool,liczba:int,max_waga:?string,url:string,okno_dni:int,liczba_zrodel:int}
     */
    private function fetchBadgeSafe(string $ip): array
    {
        $fallback = [
            'api_niedostepne' => true,
            'liczba'          => 0,
            'max_waga'        => null,
            'url'             => '',
            'okno_dni'        => 7,
            'liczba_zrodel'   => 0,
        ];

        try {
            $cfg = $this->readMcsConfig();
            if ($cfg['base_url'] === '' || $cfg['badge_token'] === '') {
                return $fallback;
            }

            $cache = new McsBadgeCache($cfg['cache_ttl']);
            $cached = $cache->get($ip);
            if (is_array($cached) && isset($cached['liczba'])) {
                return [
                    'api_niedostepne' => false,
                    'liczba'          => (int) $cached['liczba'],
                    'max_waga'        => isset($cached['max_waga']) ? (string) $cached['max_waga'] : null,
                    'url'             => (string) ($cached['url'] ?? ''),
                    'okno_dni'        => (int) ($cached['okno_dni'] ?? 7),
                    'liczba_zrodel'   => (int) ($cached['liczba_zrodel'] ?? 0),
                ];
            }

            $client = new McsBadgeClient($cfg['base_url'], $cfg['badge_token'], $cfg['timeout']);
            $res    = $client->badgeDlaIp($ip);
            if (!$res['ok'] || !is_array($res['data'])) {
                return $fallback;
            }

            $data = $res['data'];
            $out  = [
                'api_niedostepne' => false,
                'liczba'          => (int) ($data['liczba'] ?? 0),
                'max_waga'        => isset($data['max_waga']) && $data['max_waga'] !== ''
                    ? (string) $data['max_waga']
                    : null,
                'url'             => (string) ($data['url'] ?? ''),
                'okno_dni'        => (int) ($data['okno_dni'] ?? 7),
                'liczba_zrodel'   => (int) ($data['liczba_zrodel'] ?? 0),
            ];
            $cache->set($ip, $out);

            return $out;
        } catch (Throwable $e) {
            // Bez stack trace w UI — tylko stan „API niedostępne”.
            return $fallback;
        }
    }

    /**
     * @param array{api_niedostepne:bool,liczba:int,max_waga:?string,url:string,okno_dni:int,liczba_zrodel:int} $badge
     */
    private function renderBadgeHtml(string $ip, array $badge): string
    {
        $tpl = __DIR__ . '/templates/nodeinfo-badge.html';
        if (!is_readable($tpl)) {
            if ($badge['api_niedostepne']) {
                return '<div class="mcs-badge mcs-badge--offline" data-ip="' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8')
                    . '"><span title="MCS API niedostępne">MCS: —</span></div>';
            }
            $label = 'MCS: ' . (int) $badge['liczba'];
            if (!$badge['api_niedostepne'] && $badge['max_waga'] && (int) $badge['liczba'] > 0) {
                $label .= ' (' . htmlspecialchars((string) $badge['max_waga'], ENT_QUOTES, 'UTF-8') . ')';
            }
            $url = htmlspecialchars((string) $badge['url'], ENT_QUOTES, 'UTF-8');

            return '<div class="mcs-badge" data-ip="' . htmlspecialchars($ip, ENT_QUOTES, 'UTF-8')
                . '"><a href="' . $url . '" title="MCS">' . $label . '</a></div>';
        }

        $raw = (string) file_get_contents($tpl);
        // Minimalny renderer {{ var }} / {% if %} — bez zależności od Smarty w scaffoldzie.
        $vars = [
            'ip'              => $ip,
            'api_niedostepne' => !empty($badge['api_niedostepne']),
            'liczba'          => (int) $badge['liczba'],
            'max_waga'        => (string) ($badge['max_waga'] ?? ''),
            'url'             => (string) ($badge['url'] ?? ''),
            'okno_dni'        => (int) ($badge['okno_dni'] ?? 7),
            'liczba_zrodel'   => (int) ($badge['liczba_zrodel'] ?? 0),
        ];

        return $this->renderMiniTpl($raw, $vars);
    }

    /**
     * @param array<string,mixed> $vars
     */
    private function renderMiniTpl(string $raw, array $vars): string
    {
        // {% if api_niedostepne %} … {% else %} … {% endif %}
        $raw = preg_replace_callback(
            '/\{%\s*if\s+api_niedostepne\s*%\}(.*?)\{%\s*else\s*%\}(.*?)\{%\s*endif\s*%\}/s',
            static function (array $m) use ($vars): string {
                return !empty($vars['api_niedostepne']) ? $m[1] : $m[2];
            },
            $raw
        ) ?? $raw;

        // {% if max_waga %} / {% if max_waga and liczba > 0 %}
        $raw = preg_replace_callback(
            '/\{%\s*if\s+max_waga(?:\s+and\s+liczba\s*>\s*0)?\s*%\}(.*?)\{%\s*endif\s*%\}/s',
            static function (array $m) use ($vars): string {
                $ok = ($vars['max_waga'] ?? '') !== '';
                if (str_contains($m[0], 'liczba')) {
                    $ok = $ok && (int) ($vars['liczba'] ?? 0) > 0;
                }
                return $ok ? $m[1] : '';
            },
            $raw
        ) ?? $raw;

        return preg_replace_callback(
            '/\{\{\s*([a-z_]+)(?:\|default\([^)]*\))?\s*\}\}/',
            static function (array $m) use ($vars): string {
                $key = $m[1];
                $val = $vars[$key] ?? '';
                if (is_bool($val)) {
                    return $val ? '1' : '';
                }
                return htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
            },
            $raw
        ) ?? $raw;
    }

    /**
     * @return array{base_url:string,badge_token:string,cache_ttl:int,timeout:int}
     */
    private function readMcsConfig(): array
    {
        $base  = '';
        $token = '';
        $ttl   = 120;
        $timeout = 5;

        if (class_exists('ConfigHelper', false) || class_exists('ConfigHelper')) {
            // Nazwy jak w planie Fazy 5 — dopasować przy instalacji jeśli LMS używa innych kluczy.
            $base  = (string) ConfigHelper::getConfig('mcs.base_url', ConfigHelper::getConfig('mcs.baseURL', ''));
            $token = (string) ConfigHelper::getConfig('mcs.badge_token', ConfigHelper::getConfig('mcs.badgeToken', ''));
            $ttl   = (int) ConfigHelper::getConfig('mcs.cache_ttl', 120);
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
