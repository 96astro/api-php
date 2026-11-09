<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 401: `invalid_key`, `expired_key`. Check or rotate the key; do not retry in a loop. */
class AuthenticationException extends ApiException
{
}
