<?php

declare(strict_types=1);

namespace Astro96\Api;

/** Parsed `RateLimit-*` headers. `reset` is seconds until the window resets. */
final class RateLimitInfo
{
    public function __construct(
        public readonly ?int $limit,
        public readonly ?int $remaining,
        public readonly ?int $reset,
    ) {
    }

    /** @param array<string, string> $headers lower-cased header names */
    public static function fromHeaders(array $headers): ?self
    {
        $num = static function (string $name) use ($headers): ?int {
            $v = $headers[strtolower($name)] ?? null;
            return $v !== null && $v !== '' && is_numeric($v) ? (int) (float) $v : null;
        };
        $info = new self($num('RateLimit-Limit'), $num('RateLimit-Remaining'), $num('RateLimit-Reset'));
        return $info->limit === null && $info->remaining === null && $info->reset === null ? null : $info;
    }
}
