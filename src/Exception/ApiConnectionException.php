<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** The request never got an HTTP response: DNS, TLS, a dropped connection or the client timeout. */
class ApiConnectionException extends Astro96Exception
{
    public function __construct(string $message, public readonly bool $timedOut = false, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
