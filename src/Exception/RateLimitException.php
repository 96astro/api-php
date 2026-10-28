<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 429 `rate_limited`: wait `retryAfter` seconds and retry. The client already retries these. */
class RateLimitException extends ApiException
{
}
