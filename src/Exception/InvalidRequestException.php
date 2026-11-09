<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 400 or 422: `invalid_request`, `ambiguous_local_time`, `unsupported_option`, `unsupported_language`. Fix the input; `field` names the problem. */
class InvalidRequestException extends ApiException
{
}
