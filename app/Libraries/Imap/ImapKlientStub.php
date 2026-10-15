<?php

namespace App\Libraries\Imap;

use RuntimeException;

/**
 * Stub IMAP na testy i lokalny rozwój bez prawdziwej skrzynki.
 */
final class ImapKlientStub implements ImapKlientInterface
{
    /** @var list<array{uid: int, message_id: ?string, subject: string, date: ?string, from: string, tresc: string, zalaczniki: list<array{filename: string, mime: string, tresc: string}>, przeczytane?: bool}> */
    private array $wiadomosci;

    /**
     * @param list<array{uid: int, message_id?: ?string, subject?: string, date?: ?string, from?: string, tresc?: string, zalaczniki?: list<array{filename: string, mime: string, tresc: string}>, przeczytane?: bool}> $wiadomosci
     */
    public function __construct(array $wiadomosci = [])
    {
        $this->wiadomosci = [];
        foreach ($wiadomosci as $w) {
            $this->wiadomosci[] = [
                'uid'         => (int) $w['uid'],
                'message_id'  => $w['message_id'] ?? null,
                'subject'     => $w['subject'] ?? '',
                'date'        => $w['date'] ?? null,
                'from'        => $w['from'] ?? '',
                'tresc'       => $w['tresc'] ?? '',
                'zalaczniki'  => $w['zalaczniki'] ?? [],
                'przeczytane' => (bool) ($w['przeczytane'] ?? false),
            ];
        }
    }

    public function listujUid(bool $tylkoNieprzeczytane = true): array
    {
        $wynik = [];
        foreach ($this->wiadomosci as $w) {
            if ($tylkoNieprzeczytane && ($w['przeczytane'] ?? false)) {
                continue;
            }
            $wynik[] = $w['uid'];
        }

        return $wynik;
    }

    public function pobierzWiadomosc(int $uid): array
    {
        foreach ($this->wiadomosci as $w) {
            if ($w['uid'] === $uid) {
                return [
                    'uid'        => $w['uid'],
                    'message_id' => $w['message_id'],
                    'subject'    => $w['subject'],
                    'date'       => $w['date'],
                    'from'       => $w['from'],
                    'tresc'      => $w['tresc'],
                    'zalaczniki' => $w['zalaczniki'],
                ];
            }
        }

        throw new RuntimeException('Stub IMAP: brak UID=' . $uid);
    }

    public function oznaczPrzeczytane(int $uid): void
    {
        foreach ($this->wiadomosci as $i => $w) {
            if ($w['uid'] === $uid) {
                $this->wiadomosci[$i]['przeczytane'] = true;

                return;
            }
        }

        throw new RuntimeException('Stub IMAP: brak UID=' . $uid);
    }

    public function zamknij(): void
    {
        // nic
    }
}
