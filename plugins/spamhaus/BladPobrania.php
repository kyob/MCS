<?php

namespace McsPluginy\spamhaus;

use RuntimeException;

/**
 * Błąd HTTP/timeout przy pobieraniu pliku DROP. retryAfter z nagłówka Retry-After (s), 0 = brak.
 */
final class BladPobrania extends RuntimeException
{
    public function __construct(string $msg, public readonly int $retryAfter = 0)
    {
        parent::__construct($msg);
    }
}
