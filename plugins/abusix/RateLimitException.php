<?php

namespace McsPluginy\abusix;

use RuntimeException;

class RateLimitException extends RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Abusix: HTTP 429, Retry-After ' . $retryAfter . ' s');
    }
}
