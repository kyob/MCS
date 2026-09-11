<?php

namespace App\Libraries\Imap;

/**
 * Parametry konta IMAP (per plugin — z .env, nigdy z gita).
 */
final class ImapUstawienia
{
    public function __construct(
        public readonly string $host,
        public readonly int $port,
        public readonly string $user,
        public readonly string $pass,
        public readonly string $mailbox = 'INBOX',
        public readonly string $encryption = 'ssl',
    ) {
    }

    /**
     * Czyta klucze `mcs.{prefiks}.imap*` z env (np. prefiks `shadowserver`).
     *
     * Oczekiwane klucze:
     * - mcs.{prefiks}.imapHost
     * - mcs.{prefiks}.imapPort
     * - mcs.{prefiks}.imapUser
     * - mcs.{prefiks}.imapPass
     * - mcs.{prefiks}.imapMailbox (opcjonalnie, domyślnie INBOX)
     * - mcs.{prefiks}.imapEncryption (opcjonalnie: ssl|tls|none)
     */
    public static function zEnv(string $prefiksZrodla): self
    {
        $baza = 'mcs.' . $prefiksZrodla . '.';

        return new self(
            host: trim((string) env($baza . 'imapHost', '')),
            port: (int) env($baza . 'imapPort', 993),
            user: trim((string) env($baza . 'imapUser', '')),
            pass: (string) env($baza . 'imapPass', ''),
            mailbox: trim((string) env($baza . 'imapMailbox', 'INBOX')) ?: 'INBOX',
            encryption: strtolower(trim((string) env($baza . 'imapEncryption', 'ssl'))) ?: 'ssl',
        );
    }

    public function jestSkonfigurowane(): bool
    {
        return $this->host !== '' && $this->user !== '' && $this->pass !== '' && $this->port > 0;
    }
}
