<?php

namespace App\Libraries\Enrichment;

use PDO;
use PDOException;
use Throwable;

/**
 * Opcjonalny odczyt mapowania bezpośrednio z bazy LMS (PostgreSQL, read-only).
 * Preferuj HTTP API (LmsMapowanieClient) — DB tylko gdy sieć/firewall na to pozwala.
 */
class LmsMapowanieDbClient
{
    public function __construct(
        private readonly string $dsn = '',
        private readonly string $user = '',
        private readonly string $password = '',
        private readonly ?PDO $pdo = null,
    ) {
    }

    public static function fromEnv(): self
    {
        $host = trim((string) env('mcs.lms.dbHostname', ''));
        $port = (int) env('mcs.lms.dbPort', 5432);
        $db   = trim((string) env('mcs.lms.dbDatabase', ''));
        $user = trim((string) env('mcs.lms.dbUsername', ''));
        $pass = (string) env('mcs.lms.dbPassword', '');
        $ssl  = trim((string) env('mcs.lms.dbSslmode', 'prefer'));

        $dsn = '';
        if ($host !== '' && $db !== '') {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $host,
                $port > 0 ? $port : 5432,
                $db,
                $ssl !== '' ? $ssl : 'prefer',
            );
        }

        return new self($dsn, $user, $pass);
    }

    public function hasConfig(): bool
    {
        return $this->pdo !== null || ($this->dsn !== '' && $this->user !== '');
    }

    /**
     * Host:port/baza bez hasła.
     */
    public function opisHosta(): string
    {
        $host = $this->dsnPole('host');
        $port = $this->dsnPole('port');
        $db   = $this->dsnPole('dbname');
        if ($host === '') {
            return '';
        }

        $adres = $host;
        if ($port !== '') {
            $adres .= ':' . $port;
        }
        if ($db !== '') {
            $adres .= '/' . $db;
        }
        if ($this->user !== '') {
            $adres .= ' (użytkownik ' . $this->user . ')';
        }

        return $adres;
    }

    private function dsnPole(string $klucz): string
    {
        if (preg_match('/(?:^|[;:])' . preg_quote($klucz, '/') . '=([^;]+)/', $this->dsn, $m) === 1) {
            return trim($m[1]);
        }

        return '';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function lookup(string $ip): array
    {
        $ip = trim($ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return [];
        }

        $long = ip2long($ip);
        if ($long === false) {
            return [];
        }
        // ip2long na 32-bit signed; LMS trzyma bigint unsigned-compatible
        $long = sprintf('%u', $long);

        $sql = $this->sqlSelect()
            . ' WHERE n.ipaddr = ? OR (n.ipaddr_pub IS NOT NULL AND n.ipaddr_pub <> 0 AND n.ipaddr_pub = ?)'
            . ' ORDER BY n.id ASC, CASE WHEN n.ipaddr = ? THEN 1 ELSE 0 END DESC, n.access DESC';

        return $this->fetchAll($sql, [$long, $long, $long]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function syncAll(): array
    {
        $sql = $this->sqlSelect()
            . ' WHERE n.ipaddr <> 0'
            . '    OR (n.ipaddr_pub IS NOT NULL AND n.ipaddr_pub <> 0)'
            . ' ORDER BY n.id ASC';

        return $this->fetchAll($sql, []);
    }

    public function ping(): bool
    {
        try {
            $pdo = $this->pdo();
            $pdo->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function sqlSelect(): string
    {
        return <<<'SQL'
SELECT DISTINCT ON (n.id)
    n.id AS node_id,
    n.name AS komputer,
    n.login AS node_login,
    host(('0.0.0.0'::inet + CASE
        WHEN n.ipaddr <> 0 THEN n.ipaddr
        ELSE n.ipaddr_pub
    END)) AS ip,
    n.ownerid AS customer_id,
    c.lastname AS customer_lastname,
    c.name AS customer_name,
    trim(both FROM coalesce(c.lastname, '') || ' ' || coalesce(c.name, '')) AS abonent,
    n.netid,
    n.access
FROM vnodes n
LEFT JOIN customers c ON c.id = n.ownerid
SQL;
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    private function fetchAll(string $sql, array $params): array
    {
        $pdo  = $this->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (! is_array($rows)) {
            return [];
        }

        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $out[] = [
                'ip'                => (string) ($row['ip'] ?? ''),
                'node_id'           => isset($row['node_id']) ? (int) $row['node_id'] : null,
                'komputer'          => isset($row['komputer']) ? (string) $row['komputer'] : null,
                'node_login'        => isset($row['node_login']) && $row['node_login'] !== ''
                    ? (string) $row['node_login'] : null,
                'customer_id'       => isset($row['customer_id']) && $row['customer_id'] !== null && $row['customer_id'] !== ''
                    ? (int) $row['customer_id'] : null,
                'abonent'           => isset($row['abonent']) ? trim((string) $row['abonent']) : null,
                'customer_lastname' => isset($row['customer_lastname']) ? (string) $row['customer_lastname'] : null,
                'customer_name'     => isset($row['customer_name']) ? (string) $row['customer_name'] : null,
                'netid'             => isset($row['netid']) ? (int) $row['netid'] : null,
                'access'            => isset($row['access']) ? (int) $row['access'] : null,
            ];
        }

        return $out;
    }

    private function pdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->dsn === '') {
            throw new PDOException('Brak mcs.lms.dbHostname / dbDatabase');
        }

        $pdo = new PDO($this->dsn, $this->user, $this->password, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT            => 10,
        ]);

        return $pdo;
    }
}
