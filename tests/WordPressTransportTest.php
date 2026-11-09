<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Client;
use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Exception\QuotaExceededException;
use Astro96\Api\Http\WordPressTransport;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** WordPress's HTTP API is stubbed (tests/fixtures/wp-stubs.php) in a separate process, so no other test sees it. */
final class WordPressTransportTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testInsideWordPressTheWpHttpApiIsUsed(): void
    {
        require __DIR__ . '/fixtures/wp-stubs.php';
        $GLOBALS['wp_replies'] = [[
            'response' => ['code' => 200, 'message' => 'OK'],
            'headers' => new \Astro96WpHeaders(['x-request-id' => 'req_wp', 'ratelimit-remaining' => '3']),
            'body' => json_encode(['data' => ['ok' => true], 'meta' => []]),
        ]];
        $astro = new Client('sk_test_wp');
        $this->assertInstanceOf(WordPressTransport::class, $astro->transport());

        $res = $astro->panchang->dailyPanchang(['date' => '2026-10-15'], ['timeout' => 9]);
        $this->assertTrue($res->data['ok']);
        $this->assertSame('req_wp', $res->requestId);
        $this->assertSame(3, $res->rateLimit?->remaining);
        [$url, $args] = $GLOBALS['wp_calls'][0];
        $this->assertSame('https://api.96astroapi.com/v1/panchang/daily-panchang', $url);
        $this->assertSame('POST', $args['method']);
        $this->assertSame(9.0, $args['timeout']);
        $this->assertSame(0, $args['redirection']);
        $this->assertSame('Bearer sk_test_wp', $args['headers']['Authorization']);
        $this->assertSame('{"date":"2026-10-15"}', $args['body']);
    }

    #[RunInSeparateProcess]
    public function testWpErrorsAndStatusesAreMapped(): void
    {
        require __DIR__ . '/fixtures/wp-stubs.php';
        $GLOBALS['wp_replies'] = [
            new \WP_Error('http_request_failed', 'cURL error 28: Operation timed out after 1000 milliseconds'),
            ['response' => ['code' => 429, 'message' => 'Too Many Requests'], 'headers' => [], 'body' => json_encode(['error' => ['code' => 'quota_exhausted', 'message' => 'no credits', 'field' => null, 'request_id' => 'r', 'retryable' => false]])],
        ];
        $astro = new Client('k', ['max_retries' => 0]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ApiConnectionException $e) {
            $this->assertTrue($e->timedOut);
        }
        $this->expectException(QuotaExceededException::class);
        $astro->tarot->tarotShuffle();
    }
}
