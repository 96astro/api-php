<?php

declare(strict_types=1);

namespace Astro96\Api;

/**
 * A successful response: the envelope's `data` and `meta`, plus transport details.
 * For a PDF download, `data` is the raw bytes (a string) and `meta` is empty.
 *
 * @template T
 */
final class ApiResponse
{
    /**
     * @param T $data
     * @param array<string, mixed> $meta
     * @param array<string, string> $headers lower-cased header names
     */
    public function __construct(
        public readonly mixed $data,
        public readonly array $meta,
        public readonly int $status,
        public readonly ?string $requestId,
        public readonly ?RateLimitInfo $rateLimit,
        /** True when the server replayed a stored response for a repeated Idempotency-Key. */
        public readonly bool $replayed,
        /** The idempotency key that was sent, if any. */
        public readonly ?string $idempotencyKey,
        public readonly array $headers = [],
    ) {
    }

    /** Credits this call used (`meta.credits_used`), when the API reported it. */
    public function creditsUsed(): ?int
    {
        $v = $this->meta['credits_used'] ?? null;
        return is_int($v) ? $v : (is_numeric($v) ? (int) $v : null);
    }
}
