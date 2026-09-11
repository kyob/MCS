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
        $ustawienia = ImapUstawienia::zEnv($prefiksZrodla);
        if (! $ustawienia->jestSkonfigurowane()) {
            throw new RuntimeException(
                'IMAP dla „' . $prefiksZrodla . '”: uzupełnij klucze mcs.' . $prefiksZrodla
                . '.imapHost / imapPort / imapUser / imapPass w .env (patrz .env.example).',
            );
        }

        return new ImapKlientPhpExt($ustawienia);
    }

    public function rozszerzenieDostepne(): bool
    {
        return extension_loaded('imap');
    }
}
