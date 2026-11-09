<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

/** One HTTP attempt, as handed to a Transport. */
final class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public readonly string $method,
        public readonly string $url,
        public readonly array $headers,
        public readonly ?string $body,
        /** Per-attempt timeout in seconds. */
        public readonly float $timeout,
    ) {
    }
}
