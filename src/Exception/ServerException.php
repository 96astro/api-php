<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 5xx: `computation_failed` (500), `unavailable` (502/503), `deadline_exceeded` (504). Retry with the same idempotency key. */
class ServerException extends ApiException
{
}
