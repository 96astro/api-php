<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 409 `idempotency_conflict`: a different body under the same key, the key still in progress (`retryable` true), or the key is too old. */
class IdempotencyConflictException extends ApiException
{
}
