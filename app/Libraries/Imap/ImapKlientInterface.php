<?php

namespace App\Libraries\Imap;

/**
 * Wspólny klient IMAP dla pluginów mailowych (Shadowserver, Play).
 * Każde źródło ma własne konto — osobne ustawienia, nie wspólna skrzynka w DB.
 */
interface ImapKlientInterface
{
    /**
     * Lista UID wiadomości w skrzynce (opcjonalnie tylko nieprzeczytane).
     *
     * @return list<int>
     */
    public function listujUid(bool $tylkoNieprzeczytane = true): array;

    /**
     * Pobierz nagłówki + treść / załączniki potrzebne do importu.
     *
     * @return array{
     *   uid: int,
     *   message_id: ?string,
     *   subject: string,
     *   date: ?string,
     *   from: string,
     *   tresc: string,
     *   zalaczniki: list<array{filename: string, mime: string, tresc: string}>,
     *   naglowki?: string
     * }
     *
     * `naglowki` = surowy blok nagłówków RFC 5322 (np. Authentication-Results); opcjonalny.
     */
    public function pobierzWiadomosc(int $uid): array;

    /** Oznacz wiadomość jako przeczytaną (po udanym imporcie). */
    public function oznaczPrzeczytane(int $uid): void;

    public function zamknij(): void;
}
