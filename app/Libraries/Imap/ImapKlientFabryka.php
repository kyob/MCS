<?php

namespace App\Libraries\Imap;

use RuntimeException;

/**
 * Tworzy klienta IMAP dla źródła (prefiks env, np. shadowserver / play).
 */
final class ImapKlientFabryka
{
    /**
     * Prawdziwy klient (php-imap). Rzuca, gdy brak configu lub rozszerzenia.
     */
    public function dlaZrodla(string $prefiksZrodla): ImapKlientInterface
    {
        if (! $this->rozszerzenieDostepne()) {
            throw new RuntimeException(
                'Brak rozszerzenia PHP imap. Na Debian Trixie: apt install php8.4-imap'
                . ' (Sury — brak w stable) i systemctl restart php8.4-fpm.'
                . ' Patrz docs/09-imap-konfiguracja-env.md',
            );
        }

        $ustawienia = ImapUstawienia::zEnv($prefiksZrodla);
        if (! $ustawienia->jestSkonfigurowane()) {
            throw new RuntimeException(
                'IMAP dla „' . $prefiksZrodla . '”: uzupełnij klucze mcs.' . $prefiksZrodla
                . '.imapHost / imapPort / imapUser / imapPass w .env'
                . ' (patrz .env.example i docs/09-imap-konfiguracja-env.md).',
            );
        }

        return new ImapKlientPhpExt($ustawienia);
    }

    public function rozszerzenieDostepne(): bool
    {
        return extension_loaded('imap');
    }
}
