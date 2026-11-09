<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

/** What a Transport returns. Header names are lower-cased; repeated headers are joined with ", ". */
final class HttpResponse
{
    /** @var array<string, string> */
    public readonly array $headers;

    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        array $headers,
        public readonly string $body,
        public readonly string $reason = '',
    ) {
        $norm = [];
        foreach ($headers as $name => $value) {
            $k = strtolower((string) $name);
            $v = is_array($value) ? implode(', ', $value) : (string) $value;
            $norm[$k] = isset($norm[$k]) ? $norm[$k] . ', ' . $v : $v;
        }
        $this->headers = $norm;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function isSuccess(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
