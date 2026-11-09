<?php

declare(strict_types=1);

namespace Astro96\Api;

use Astro96\Api\Exception\WebhookSignatureException;

/**
 * Webhook signature verification (docs: /docs/jobs-and-webhooks#verify-the-signature).
 *
 * `Astro-Signature` is `v1=<hex HMAC-SHA256(secret, "{id}.{timestamp}.{raw body}")>`, comma-separated while
 * a secret rotation is in progress; `{id}` is `Astro-Webhook-Id` and `{timestamp}` is
 * `Astro-Webhook-Timestamp` (Unix seconds). The HMAC key is the whole secret string, `whsec_` included.
 */
final class Webhook
{
    public const DEFAULT_TOLERANCE = 300;

    /**
     * Verify a webhook request and return the parsed event
     * (`id`, `type`, `created`, `environment`, `data`). Deduplicate on the returned `id`.
     *
     * Pass the RAW request body (`file_get_contents('php://input')`, Laravel `$request->getContent()`,
     * WordPress `$request->get_body()`), never re-encoded JSON.
     *
     * @param array<string, string|list<string>> $headers names matched case-insensitively; PHP's
     *        `$_SERVER` style (`HTTP_ASTRO_SIGNATURE`) works too
     * @param string|list<string> $secret your endpoint's signing secret, or several while you rotate
     * @return array<string, mixed>
     * @throws WebhookSignatureException when headers are missing, the timestamp is more than
     *         `$tolerance` seconds from now, or no signature matches any secret
     */
    public static function verify(string $rawBody, array $headers, string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE, ?int $now = null): array
    {
        $id = self::header($headers, 'Astro-Webhook-Id');
        $timestamp = self::header($headers, 'Astro-Webhook-Timestamp') ?? '';
        $signature = self::header($headers, 'Astro-Signature');
        if ($id === null || $id === '' || !ctype_digit($timestamp) || $signature === null || $signature === '') {
            throw new WebhookSignatureException('missing webhook signature headers');
        }
        if (abs(($now ?? time()) - (int) $timestamp) > $tolerance) {
            throw new WebhookSignatureException('webhook timestamp outside the tolerance');
        }
        $signed = "{$id}.{$timestamp}." . $rawBody;
        $received = [];
        foreach (explode(',', $signature) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 'v1=')) {
                $received[] = strtolower(substr($part, 3));
            }
        }
        foreach ((array) $secret as $s) {
            $expected = hash_hmac('sha256', $signed, (string) $s);
            foreach ($received as $r) {
                if (hash_equals($expected, $r)) {
                    try {
                        $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\JsonException $e) {
                        throw new WebhookSignatureException('webhook body is not JSON', 0, $e);
                    }
                    return is_array($event) ? $event : throw new WebhookSignatureException('webhook body is not an object');
                }
            }
        }
        throw new WebhookSignatureException('no webhook signature matched');
    }

    /**
     * Verify the current PHP request (php://input and $_SERVER), for plain PHP endpoints.
     *
     * @param string|list<string> $secret
     * @return array<string, mixed>
     * @throws WebhookSignatureException
     */
    public static function verifyGlobals(string|array $secret, int $tolerance = self::DEFAULT_TOLERANCE): array
    {
        return self::verify((string) file_get_contents('php://input'), $_SERVER, $secret, $tolerance);
    }

    /** @param array<string, mixed> $headers */
    private static function header(array $headers, string $name): ?string
    {
        $want = strtolower($name);
        $server = 'http_' . str_replace('-', '_', $want);
        foreach ($headers as $k => $v) {
            $k = strtolower((string) $k);
            if ($k === $want || $k === $server) {
                return is_array($v) ? implode(',', array_map('strval', $v)) : (string) $v;
            }
        }
        return null;
    }
}
