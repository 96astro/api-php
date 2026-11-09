<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Exception\WebhookSignatureException;
use Astro96\Api\Webhook;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The scheme of docs/24-webhooks.md: v1=hex(HMAC-SHA256(whole secret, "{id}.{timestamp}.{raw body}")). */
final class WebhookTest extends TestCase
{
    private const SECRET = 'whsec_test_secret';
    private const NOW = 1_760_425_200;

    private static function body(): string
    {
        return (string) json_encode(['id' => 'evt_1', 'type' => 'job.succeeded', 'created' => self::NOW, 'environment' => 'test', 'data' => ['id' => 'job_1']]);
    }

    private static function sign(string $secret, ?string $body = null, string $id = 'evt_1', int $ts = self::NOW): string
    {
        return hash_hmac('sha256', "{$id}.{$ts}." . ($body ?? self::body()), $secret);
    }

    /** @return array<string, string> */
    private static function headers(string $sig, int $ts = self::NOW): array
    {
        return ['astro-webhook-id' => 'evt_1', 'Astro-Webhook-Timestamp' => (string) $ts, 'ASTRO-SIGNATURE' => $sig];
    }

    public function testValidSignatureReturnsTheEvent(): void
    {
        $event = Webhook::verify(self::body(), self::headers('v1=' . self::sign(self::SECRET)), self::SECRET, 300, self::NOW + 10);
        $this->assertSame('job.succeeded', $event['type']);
        $this->assertSame('job_1', $event['data']['id']);
    }

    public function testKnownVectorMatchesTheServerScheme(): void
    {
        // Independent of sign(): the literal message the server signs, and its digest.
        $body = '{"id":"evt_1"}';
        $sig = hash_hmac('sha256', 'evt_1.1760425200.{"id":"evt_1"}', 'whsec_test_secret');
        $this->assertSame('evt_1', Webhook::verify($body, self::headers("v1={$sig}"), 'whsec_test_secret', 300, self::NOW)['id']);
        $this->assertSame(64, strlen($sig));
    }

    public function testEitherSignatureMatchesDuringRotationAndHexCaseIsIgnored(): void
    {
        $sig = 'v1=' . str_repeat('0', 64) . ', v1=' . strtoupper(self::sign('whsec_new'));
        $this->assertSame('evt_1', Webhook::verify(self::body(), self::headers($sig), [self::SECRET, 'whsec_new'], 300, self::NOW)['id']);
    }

    public function testServerStyleAndListValuedHeadersAreAccepted(): void
    {
        $server = ['HTTP_ASTRO_WEBHOOK_ID' => 'evt_1', 'HTTP_ASTRO_WEBHOOK_TIMESTAMP' => (string) self::NOW, 'HTTP_ASTRO_SIGNATURE' => 'v1=' . self::sign(self::SECRET)];
        $this->assertSame('evt_1', Webhook::verify(self::body(), $server, self::SECRET, 300, self::NOW)['id']);

        $symfony = ['astro-webhook-id' => ['evt_1'], 'astro-webhook-timestamp' => [(string) self::NOW], 'astro-signature' => ['v1=' . self::sign(self::SECRET)]];
        $this->assertSame('evt_1', Webhook::verify(self::body(), $symfony, self::SECRET, 300, self::NOW)['id']);
    }

    /** @return iterable<string, array{string, array<string, string>, string, int, string}> */
    public static function badRequests(): iterable
    {
        $good = self::headers('v1=' . self::sign(self::SECRET));
        yield 'wrong secret' => [self::body(), $good, 'whsec_other', self::NOW, 'matched'];
        yield 'tampered body' => [self::body() . ' ', $good, self::SECRET, self::NOW, 'matched'];
        yield 'expired (too old)' => [self::body(), $good, self::SECRET, self::NOW + 301, 'tolerance'];
        yield 'from the future' => [self::body(), $good, self::SECRET, self::NOW - 301, 'tolerance'];
        yield 'missing headers' => [self::body(), [], self::SECRET, self::NOW, 'missing'];
        yield 'non-numeric timestamp' => [self::body(), ['Astro-Webhook-Timestamp' => 'abc'] + $good, self::SECRET, self::NOW, 'missing'];
        yield 'no v1 scheme' => [self::body(), ['Astro-Signature' => 'v0=' . self::sign(self::SECRET)] + $good, self::SECRET, self::NOW, 'matched'];
        yield 'signed for another id' => [self::body(), ['astro-webhook-id' => 'evt_2'] + $good, self::SECRET, self::NOW, 'matched'];
        yield 'signature replayed at another timestamp' => [self::body(), self::headers('v1=' . self::sign(self::SECRET), self::NOW + 60), self::SECRET, self::NOW + 60, 'matched'];
    }

    /** @param array<string, string> $headers */
    #[DataProvider('badRequests')]
    public function testBadRequestsAreRefused(string $body, array $headers, string $secret, int $now, string $match): void
    {
        $this->expectException(WebhookSignatureException::class);
        $this->expectExceptionMessage($match);
        Webhook::verify($body, $headers, $secret, 300, $now);
    }

    public function testToleranceIsConfigurable(): void
    {
        $h = self::headers('v1=' . self::sign(self::SECRET));
        $this->assertSame('evt_1', Webhook::verify(self::body(), $h, self::SECRET, 3600, self::NOW + 1000)['id']);
        $this->expectException(WebhookSignatureException::class);
        Webhook::verify(self::body(), $h, self::SECRET, 10, self::NOW + 11);
    }
}
