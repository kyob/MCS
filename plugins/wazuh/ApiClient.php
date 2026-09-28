<?php

namespace McsPluginy\wazuh;

/**
 * Pobiera eksport z Wazuh przez SSH (forced command /usr/local/lib/wazuh-mcs-export).
 * Klucz i known_hosts poza docroot (/etc/mcs/ssh). Bez shella: proc_open z tablicą argumentów.
 */
class ApiClient
{
    /** @var callable|null fn(list<string> $cmd, int $timeout): array{ok: bool, stdout: string, error: string} */
    private $runner;

    public function __construct(
        private readonly string $host,
        private readonly string $user,
        private readonly string $keyPath,
        private readonly string $knownHosts,
        private readonly int $oknoGodzin = 48,
        private readonly int $timeout = 60,
        ?callable $runner = null,
    ) {
        $this->runner = $runner;
    }

    public static function fromEnv(?callable $runner = null): self
    {
        return new self(
            trim((string) env('mcs.wazuh.sshHost', '')),
            trim((string) env('mcs.wazuh.sshUser', 'mcs-export')),
            trim((string) env('mcs.wazuh.sshKey', '/etc/mcs/ssh/wazuh_ed25519')),
            trim((string) env('mcs.wazuh.knownHosts', '/etc/mcs/ssh/known_hosts')),
            max(1, min(168, (int) env('mcs.wazuh.oknoGodzin', 48))),
            max(5, (int) env('mcs.wazuh.timeout', 60)),
            $runner,
        );
    }

    public function gotowy(): bool
    {
        return $this->host !== '' && $this->user !== '' && is_readable($this->keyPath);
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getUser(): string
    {
        return $this->user;
    }

    public function getOknoGodzin(): int
    {
        return $this->oknoGodzin;
    }

    /**
     * @return array{ok: bool, stdout: string, error: string}
     */
    public function pobierzEksport(): array
    {
        $cmd = [
            'timeout', (string) $this->timeout,
            'ssh',
            '-o', 'BatchMode=yes',
            '-o', 'IdentitiesOnly=yes',
            '-o', 'StrictHostKeyChecking=yes',
            '-o', 'UserKnownHostsFile=' . $this->knownHosts,
            '-o', 'ConnectTimeout=10',
            '-i', $this->keyPath,
            $this->user . '@' . $this->host,
            (string) $this->oknoGodzin,
        ];

        if ($this->runner !== null) {
            return ($this->runner)($cmd, $this->timeout);
        }

        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (! is_resource($proc)) {
            return ['ok' => false, 'stdout' => '', 'error' => 'proc_open ssh nieudane'];
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $rc = proc_close($proc);

        if ($rc !== 0) {
            return [
                'ok'     => false,
                'stdout' => '',
                'error'  => 'ssh rc=' . $rc . ($rc === 124 ? ' (timeout)' : '') . ': ' . mb_substr(trim($stderr), 0, 300),
            ];
        }

        return ['ok' => true, 'stdout' => $stdout, 'error' => ''];
    }
}
