<?php

/**
 * Standalone JSON API dla MCS — mapowanie IP → node/customer.
 *
 * Config: [mcs] map_token w /etc/lms/lms.ini (osobny sekret od badge).
 * Auth: wyłącznie Authorization: Bearer — bez query token i bez fallbacku.
 *
 * Wywołanie z MCS: mcs.lms.baseUrl + mcs.lms.apiPath
 *
 * Akcje:
 *   health
 *   lookup&ip= (tylko IPv4)
 *   sync&limit=&offset= (paginacja; domyślnie limit=2000, max=5000)
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

$CONFIG_FILE = DIRECTORY_SEPARATOR . 'etc' . DIRECTORY_SEPARATOR . 'lms' . DIRECTORY_SEPARATOR . 'lms.ini';
if (! is_readable($CONFIG_FILE)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'blad' => 'Brak lms.ini'], JSON_UNESCAPED_UNICODE);
    exit;
}

define('CONFIG_FILE', $CONFIG_FILE);
$CONFIG = (array) parse_ini_file($CONFIG_FILE, true);

$CONFIG['directories']['sys_dir'] = $CONFIG['directories']['sys_dir'] ?? dirname(__DIR__, 2);
$CONFIG['directories']['lib_dir'] = $CONFIG['directories']['lib_dir']
    ?? $CONFIG['directories']['sys_dir'] . DIRECTORY_SEPARATOR . 'lib';

define('SYS_DIR', $CONFIG['directories']['sys_dir']);
define('LIB_DIR', $CONFIG['directories']['lib_dir']);

$composer = SYS_DIR . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (! is_file($composer)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'blad' => 'Brak Composer autoload LMS'], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once $composer;

try {
    $DB = LMSDB::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'blad' => 'DB LMS niedostępna'], JSON_UNESCAPED_UNICODE);
    exit;
}

require_once LIB_DIR . DIRECTORY_SEPARATOR . 'common.php';

$mapToken = '';
if (class_exists('ConfigHelper')) {
    $mapToken = (string) ConfigHelper::getConfig('mcs.map_token', '');
}
if ($mapToken === '' && isset($CONFIG['mcs']['map_token'])) {
    $mapToken = (string) $CONFIG['mcs']['map_token'];
}

$provided = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (is_string($auth) && preg_match('/^\s*Bearer\s+(\S+)/i', $auth, $m)) {
    $provided = $m[1];
}

if ($mapToken === '' || $provided === '' || ! hash_equals($mapToken, $provided)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'blad' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$action = isset($_GET['action']) ? (string) $_GET['action'] : 'health';

try {
    if ($action === 'health') {
        $cnt = (int) $DB->GetOne(
            'SELECT COUNT(*) FROM nodes
             WHERE ipaddr <> 0
                OR (ipaddr_pub IS NOT NULL AND ipaddr_pub <> 0)'
        );
        echo json_encode([
            'ok'            => true,
            'action'        => 'health',
            'nodes_with_ip' => $cnt,
            'ipv4_only'     => true,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'lookup') {
        $ip = isset($_GET['ip']) ? trim((string) $_GET['ip']) : '';
        if ($ip === '' || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'blad' => 'Wymagany IPv4'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $long = ip2long($ip);
        if ($long === false) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'blad' => 'Nieprawidłowy IP'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        // vnodes = widok nodes (+ ewentualnie wiele MAC); DISTINCT ON unika duplikatów.
        // ipaddr_pub: publiczne IP bywa osobno (jak w pluginach CertN6 / AbuseIPDB).
        $longStr = sprintf('%u', $long);
        $rows = $DB->GetAll(
            'SELECT DISTINCT ON (n.id)
                    n.id AS node_id, n.name AS komputer, n.login AS node_login,
                    host((\'0.0.0.0\'::inet + CASE
                        WHEN n.ipaddr = ? THEN n.ipaddr
                        ELSE n.ipaddr_pub
                    END)) AS ip,
                    n.ownerid AS customer_id, c.lastname AS customer_lastname, c.name AS customer_name,
                    trim(both FROM coalesce(c.lastname, \'\') || \' \' || coalesce(c.name, \'\')) AS abonent,
                    n.netid, n.access,
                    CASE WHEN n.ipaddr = ? THEN 1 ELSE 0 END AS trafienie_ipaddr
             FROM vnodes n
             LEFT JOIN customers c ON c.id = n.ownerid
             WHERE n.ipaddr = ? OR (n.ipaddr_pub IS NOT NULL AND n.ipaddr_pub <> 0 AND n.ipaddr_pub = ?)
             ORDER BY n.id ASC, trafienie_ipaddr DESC, n.access DESC',
            [$longStr, $longStr, $longStr, $longStr]
        );
        if (! is_array($rows)) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = normalize_row($row);
            }
        }
        echo json_encode([
            'ok'     => true,
            'action' => 'lookup',
            'ip'     => $ip,
            'items'  => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'sync') {
        $limit  = isset($_GET['limit']) ? (int) $_GET['limit'] : 2000;
        $offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
        if ($limit < 1) {
            $limit = 2000;
        }
        if ($limit > 5000) {
            $limit = 5000;
        }
        if ($offset < 0) {
            $offset = 0;
        }

        $total = (int) $DB->GetOne(
            'SELECT COUNT(*) FROM (
                SELECT DISTINCT ON (n.id) n.id
                FROM vnodes n
                WHERE n.ipaddr <> 0
                   OR (n.ipaddr_pub IS NOT NULL AND n.ipaddr_pub <> 0)
             ) t'
        );

        $rows = $DB->GetAll(
            'SELECT DISTINCT ON (n.id)
                    n.id AS node_id, n.name AS komputer, n.login AS node_login,
                    host((\'0.0.0.0\'::inet + CASE
                        WHEN n.ipaddr <> 0 THEN n.ipaddr
                        ELSE n.ipaddr_pub
                    END)) AS ip,
                    n.ownerid AS customer_id, c.lastname AS customer_lastname, c.name AS customer_name,
                    trim(both FROM coalesce(c.lastname, \'\') || \' \' || coalesce(c.name, \'\')) AS abonent,
                    n.netid, n.access
             FROM vnodes n
             LEFT JOIN customers c ON c.id = n.ownerid
             WHERE n.ipaddr <> 0
                OR (n.ipaddr_pub IS NOT NULL AND n.ipaddr_pub <> 0)
             ORDER BY n.id ASC
             LIMIT ? OFFSET ?',
            [$limit, $offset]
        );
        if (! is_array($rows)) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = normalize_row($row);
            }
        }
        $hasMore = ($offset + count($items)) < $total;
        echo json_encode([
            'ok'       => true,
            'action'   => 'sync',
            'count'    => count($items),
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'has_more' => $hasMore,
            'items'    => $items,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['ok' => false, 'blad' => 'Nieznane action'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[LMSMcsMapowaniePlugin] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'blad' => 'Błąd wewnętrzny LMS'], JSON_UNESCAPED_UNICODE);
}

/**
 * @param array<string, mixed> $row
 *
 * @return array<string, mixed>
 */
function normalize_row(array $row): array
{
    $login = isset($row['node_login']) ? trim((string) $row['node_login']) : '';
    $abonent = isset($row['abonent']) ? trim((string) $row['abonent']) : '';

    return [
        'ip'                => (string) ($row['ip'] ?? ''),
        'node_id'           => isset($row['node_id']) ? (int) $row['node_id'] : null,
        'komputer'          => isset($row['komputer']) ? (string) $row['komputer'] : null,
        'node_login'        => $login !== '' ? $login : null,
        'customer_id'       => isset($row['customer_id']) && $row['customer_id'] !== null && $row['customer_id'] !== ''
            ? (int) $row['customer_id'] : null,
        'abonent'           => $abonent !== '' ? $abonent : null,
        'customer_lastname' => isset($row['customer_lastname']) ? (string) $row['customer_lastname'] : null,
        'customer_name'     => isset($row['customer_name']) ? (string) $row['customer_name'] : null,
        'netid'             => isset($row['netid']) ? (int) $row['netid'] : null,
        'access'            => isset($row['access']) ? (int) $row['access'] : null,
    ];
}
