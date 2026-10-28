<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

use Astro96\Api\RateLimitInfo;

/**
 * The API answered with an error envelope (`{"error": {...}}`) or an unparseable non-2xx status.
 *
 * Program against `$errorCode`, which is stable; the message is for people. Catch a subclass
 * (RateLimitException, QuotaExceededException, InvalidRequestException, ...) or this base class.
 * `getCode()` returns the HTTP status.
 */
class ApiException extends Astro96Exception
{
    /** Codes that answer 429 but will not succeed on retry. */
    public const NOT_RETRIED = ['quota_exhausted', 'spend_cap_reached', 'project_budget_exceeded'];

    /**
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        /** HTTP status, e.g. 422. */
        public readonly int $status,
        /** Stable error code, e.g. `invalid_request`, `rate_limited`, `quota_exhausted`. */
        public readonly string $errorCode,
        /** The message from the API, for people. */
        public readonly string $apiMessage,
        /** The input field at fault, if any. */
        public readonly ?string $field = null,
        /** True when the same request may succeed if retried (with the same Idempotency-Key). */
        public readonly bool $retryable = false,
        /** Quote this to support. */
        public readonly ?string $requestId = null,
        /** Seconds the server asked us to wait (`Retry-After`), if sent. */
        public readonly ?float $retryAfter = null,
        public readonly ?RateLimitInfo $rateLimit = null,
        public readonly array $headers = [],
        /** The parsed response body, or the raw text when it was not JSON. */
        public readonly mixed $body = null,
    ) {
        $suffix = $requestId !== null ? " (request {$requestId})" : '';
        parent::__construct("{$status} {$errorCode}: {$apiMessage}{$suffix}", $status);
    }

    /**
     * The exception class for a status and code, mirroring the API's error vocabulary
     * (https://96astroapi.com/docs/errors).
     *
     * @return class-string<ApiException>
     */
    public static function classFor(int $status, string $code): string
    {
        if ($status === 429) {
            return in_array($code, self::NOT_RETRIED, true) ? QuotaExceededException::class : RateLimitException::class;
        }
        return match (true) {
            $status === 400, $status === 422 => InvalidRequestException::class,
            $status === 401 => AuthenticationException::class,
            $status === 402 => PaymentRequiredException::class,
            $status === 403 => PermissionDeniedException::class,
            $status === 404 => NotFoundException::class,
            $status === 409 => IdempotencyConflictException::class,
            $status === 410 => GoneException::class,
            $status === 413 => PayloadTooLargeException::class,
            $status === 501 => NotYetAvailableException::class,
            $status >= 500 => ServerException::class,
            default => self::class,
        };
    }
}
