<?php

namespace App\Libraries\Imap;

use RuntimeException;

/**
 * Implementacja na rozszerzeniu PHP `imap` (PECL; na Debian Trixie: php8.4-imap z Sury).
 */
final class ImapKlientPhpExt implements ImapKlientInterface
{
    /** @var resource|\IMAP\Connection|null */
    private $polaczenie = null;

    public function __construct(private readonly ImapUstawienia $ustawienia)
    {
        if (! extension_loaded('imap')) {
            throw new RuntimeException(
                'Brak rozszerzenia PHP imap. Na Debian Trixie: apt install php8.4-imap'
                . ' (pakiet z packages.sury.org — brak w stable) i systemctl restart php8.4-fpm.'
                . ' Szczegóły: docs/09-imap-konfiguracja-env.md',
            );
        }
        if (! $ustawienia->jestSkonfigurowane()) {
            throw new RuntimeException('IMAP: brak host/user/pass w konfiguracji źródła.');
        }
    }

    public function listujUid(bool $tylkoNieprzeczytane = true): array
    {
        $mbox = $this->polacz();
        $kryterium = $tylkoNieprzeczytane ? 'UNSEEN' : 'ALL';
        $lista = imap_search($mbox, $kryterium, SE_UID);
        if ($lista === false) {
            return [];
        }

        $wynik = [];
        foreach ($lista as $uid) {
            $wynik[] = (int) $uid;
        }

        return $wynik;
    }

    public function pobierzWiadomosc(int $uid): array
    {
        $mbox = $this->polacz();
        $naglowek = imap_headerinfo($mbox, $this->msgno($uid));
        if ($naglowek === false) {
            throw new RuntimeException('IMAP: nie można odczytać nagłówka UID=' . $uid);
        }

        $messageId = null;
        if (isset($naglowek->message_id) && is_string($naglowek->message_id)) {
            $messageId = trim($naglowek->message_id);
        }

        $subject = '';
        if (isset($naglowek->subject) && is_string($naglowek->subject)) {
            $subject = $this->dekodujMime($naglowek->subject);
        }

        $date = null;
        if (isset($naglowek->date) && is_string($naglowek->date)) {
            $date = $naglowek->date;
        }

        return [
            'uid'         => $uid,
            'message_id'  => $messageId,
            'subject'     => $subject,
            'date'        => $date,
            'zalaczniki'  => $this->pobierzZalaczniki($uid),
        ];
    }

    public function oznaczPrzeczytane(int $uid): void
    {
        $mbox = $this->polacz();
        imap_setflag_full($mbox, (string) $uid, '\\Seen', ST_UID);
    }

    public function zamknij(): void
    {
        if ($this->polaczenie !== null) {
            imap_close($this->polaczenie);
            $this->polaczenie = null;
        }
    }

    public function __destruct()
    {
        $this->zamknij();
    }

    /** @return resource|\IMAP\Connection */
    private function polacz()
    {
        if ($this->polaczenie !== null) {
            return $this->polaczenie;
        }

        $u = $this->ustawienia;
        $flagi = match ($u->encryption) {
            'tls'  => '/imap/tls',
            'none' => '/imap/notls',
            default => '/imap/ssl',
        };
        $mailbox = sprintf('{%s:%d%s}%s', $u->host, $u->port, $flagi, $u->mailbox);

        $conn = @imap_open($mailbox, $u->user, $u->pass, 0, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);
        if ($conn === false) {
            $err = imap_last_error() ?: 'nieznany błąd połączenia';
            throw new RuntimeException('IMAP connect nieudany: ' . $err);
        }

        $this->polaczenie = $conn;

        return $this->polaczenie;
    }

    private function msgno(int $uid): int
    {
        $mbox = $this->polacz();
        $nr   = imap_msgno($mbox, $uid);
        if ($nr <= 0) {
            throw new RuntimeException('IMAP: brak msgno dla UID=' . $uid);
        }

        return $nr;
    }

    /**
     * @return list<array{filename: string, mime: string, tresc: string}>
     */
    private function pobierzZalaczniki(int $uid): array
    {
        $mbox  = $this->polacz();
        $struktura = imap_fetchstructure($mbox, $uid, FT_UID);
        if ($struktura === false) {
            return [];
        }

        $wynik = [];
        $this->zbierzCzesci($mbox, $uid, $struktura, '', $wynik);

        return $wynik;
    }

    /**
     * @param resource|\IMAP\Connection $mbox
     * @param list<array{filename: string, mime: string, tresc: string}> $wynik
     */
    private function zbierzCzesci($mbox, int $uid, object $part, string $prefix, array &$wynik): void
    {
        $typ = $this->mapujTyp($part->type ?? 0, $part->subtype ?? '');

        $filename = null;
        if (! empty($part->dparameters) && is_array($part->dparameters)) {
            foreach ($part->dparameters as $param) {
                if (isset($param->attribute, $param->value)
                    && strcasecmp((string) $param->attribute, 'filename') === 0
                ) {
                    $filename = $this->dekodujMime((string) $param->value);
                }
            }
        }
        if ($filename === null && ! empty($part->ifparameters) && ! empty($part->parameters)) {
            foreach ($part->parameters as $param) {
                if (isset($param->attribute, $param->value)
                    && strcasecmp((string) $param->attribute, 'name') === 0
                ) {
                    $filename = $this->dekodujMime((string) $param->value);
                }
            }
        }

        $jestZalacznik = $filename !== null
            || (isset($part->disposition) && strcasecmp((string) $part->disposition, 'attachment') === 0);

        if ($jestZalacznik && empty($part->parts)) {
            $section = $prefix === '' ? '1' : $prefix;
            $body    = imap_fetchbody($mbox, $uid, $section, FT_UID);
            if ($body === false) {
                $body = '';
            }
            $encoding = (int) ($part->encoding ?? 0);
            $tresc    = $this->dekodujCialo($body, $encoding);
            $wynik[]  = [
                'filename' => $filename ?? ('part-' . $section),
                'mime'     => $typ,
                'tresc'    => $tresc,
            ];
        }

        if (! empty($part->parts) && is_array($part->parts)) {
            foreach ($part->parts as $i => $child) {
                $nr = $prefix === '' ? (string) ($i + 1) : $prefix . '.' . ($i + 1);
                $this->zbierzCzesci($mbox, $uid, $child, $nr, $wynik);
            }
        }
    }

    private function mapujTyp(int $type, string $subtype): string
    {
        $map = [
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            7 => 'other',
        ];
        $glowny = $map[$type] ?? 'other';

        return $glowny . '/' . strtolower($subtype !== '' ? $subtype : 'octet-stream');
    }

    private function dekodujCialo(string $body, int $encoding): string
    {
        return match ($encoding) {
            3       => (string) base64_decode($body, true),
            4       => (string) quoted_printable_decode($body),
            default => $body,
        };
    }

    private function dekodujMime(string $tekst): string
    {
        $elementy = imap_mime_header_decode($tekst);
        if ($elementy === false) {
            return $tekst;
        }
        $out = '';
        foreach ($elementy as $el) {
            $out .= $el->text ?? '';
        }

        return $out !== '' ? $out : $tekst;
    }
}
