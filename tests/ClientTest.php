<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Client;
use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Exception\ApiException;
use Astro96\Api\Exception\Astro96Exception;
use Astro96\Api\Exception\AuthenticationException;
use Astro96\Api\Exception\GoneException;
use Astro96\Api\Exception\IdempotencyConflictException;
use Astro96\Api\Exception\InvalidRequestException;
use Astro96\Api\Exception\JobException;
use Astro96\Api\Exception\NotFoundException;
use Astro96\Api\Exception\NotYetAvailableException;
use Astro96\Api\Exception\PayloadTooLargeException;
use Astro96\Api\Exception\PaymentRequiredException;
use Astro96\Api\Exception\PermissionDeniedException;
use Astro96\Api\Exception\QuotaExceededException;
use Astro96\Api\Exception\RateLimitException;
use Astro96\Api\Exception\ServerException;
use Astro96\Api\Generated\Catalog;
use Astro96\Api\Http\HttpResponse;
use Astro96\Api\Idempotency;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    private const BIRTH = ['datetime' => '1990-10-18T14:30:00', 'timezone' => 'Asia/Kolkata', 'latitude' => 28.6139, 'longitude' => 77.209];
    private const JOB_ID = '2c863278-518b-407e-8234-0ba0f824404c';
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    /** @var list<float> */
    private array $sleeps = [];

    /** @return array{0: Client, 1: FakeTransport} */
    private function client(array $replies, array $options = []): array
    {
        $t = new FakeTransport($replies);
        $c = new Client('sk_test_abc', $options + ['transport' => $t, 'sleeper' => function (float $s): void {
            $this->sleeps[] = $s;
        }]);
        return [$c, $t];
    }

    private static function job(array $over = []): array
    {
        return $over + [
            'id' => self::JOB_ID, 'object' => 'job', 'kind' => 'pdf', 'capability' => 'report-career', 'status' => 'queued',
            'attempts' => 0, 'credits' => 130, 'created_at' => '2026-10-14T06:58:59Z', 'download_url' => null,
        ];
    }

    public function testCapabilityCallSendsAuthBodyAndIdempotencyKeyAndReturnsEnvelope(): void
    {
        [$astro, $t] = $this->client([FakeTransport::ok(
            ['ascendant' => ['sign' => 'Capricorn']],
            [],
            ['RateLimit-Limit' => '10', 'RateLimit-Remaining' => '9', 'RateLimit-Reset' => '1', 'X-Request-Id' => 'req_hdr'],
        )]);
        $res = $astro->vedicCharts->planetPositions(self::BIRTH);

        $req = $t->calls[0];
        $this->assertSame('POST', $req->method);
        $this->assertSame('https://api.96astroapi.com/v1/vedic-charts/planet-positions', $req->url);
        $this->assertSame('Bearer sk_test_abc', $req->headers['Authorization']);
        $this->assertArrayNotHasKey('X-API-Key', $req->headers);
        $this->assertSame('application/json', $req->headers['Content-Type']);
        $this->assertStringStartsWith('96astro-php/' . Client::VERSION, $req->headers['User-Agent']);
        $this->assertMatchesRegularExpression(self::UUID, $req->headers['Idempotency-Key']);
        $this->assertSame(self::BIRTH, $t->body(0));
        $this->assertStringContainsString('"latitude":28.6139', (string) $req->body);

        $this->assertSame('Capricorn', $res->data['ascendant']['sign']);
        $this->assertSame(0, $res->creditsUsed());
        $this->assertSame('req_hdr', $res->requestId);
        $this->assertSame(200, $res->status);
        $this->assertFalse($res->replayed);
        $this->assertSame($req->headers['Idempotency-Key'], $res->idempotencyKey);
        $this->assertSame(10, $res->rateLimit?->limit);
        $this->assertSame(9, $res->rateLimit?->remaining);
    }

    public function testXApiKeyAuthBaseUrlExplicitKeyAndReplay(): void
    {
        [$astro, $t] = $this->client(
            [FakeTransport::ok([], ['request_id' => 'req_m', 'replayed' => true], ['Idempotent-Replayed' => 'true'])],
            ['auth' => 'x-api-key', 'base_url' => 'http://localhost:8300/', 'headers' => ['X-Trace' => 't1']],
        );
        $res = $astro->panchang->dailyPanchang(['date' => '2026-10-15'], ['idempotency_key' => 'my-key', 'headers' => ['X-Call' => 'c1']]);
        $req = $t->calls[0];
        $this->assertSame('http://localhost:8300/v1/panchang/daily-panchang', $req->url);
        $this->assertSame('sk_test_abc', $req->headers['X-API-Key']);
        $this->assertArrayNotHasKey('Authorization', $req->headers);
        $this->assertSame('my-key', $req->headers['Idempotency-Key']);
        $this->assertSame('t1', $req->headers['X-Trace']);
        $this->assertSame('c1', $req->headers['X-Call']);
        $this->assertTrue($res->replayed);
        $this->assertSame('my-key', $res->idempotencyKey);
        $this->assertNull($res->rateLimit);
    }

    public function testAutoIdempotencyOffSendsNoKeyAndOptionalInputIsAnEmptyObject(): void
    {
        [$astro, $t] = $this->client([FakeTransport::ok([])], ['auto_idempotency' => false]);
        $res = $astro->tarot->tarotShuffle();
        $this->assertArrayNotHasKey('Idempotency-Key', $t->calls[0]->headers);
        $this->assertSame('{}', $t->calls[0]->body);
        $this->assertNull($res->idempotencyKey);
    }

    public function testEveryCapabilityHasAMethodOnItsFamily(): void
    {
        [$astro] = $this->client([]);
        $this->assertGreaterThan(300, count(Catalog::CAPABILITY_FAMILIES));
        foreach (Catalog::CAPABILITY_FAMILIES as $capability => $family) {
            $prop = Catalog::FAMILIES[$family];
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $capability))));
            $this->assertTrue(method_exists($astro->{$prop}, $method), "{$family}/{$capability} -> \$client->{$prop}->{$method}()");
        }
    }

    public function testEveryGeneratedMethodPostsToItsOwnPath(): void
    {
        $replies = array_fill(0, count(Catalog::CAPABILITY_FAMILIES), FakeTransport::ok([]));
        [$astro, $t] = $this->client($replies);
        $i = 0;
        foreach (Catalog::CAPABILITY_FAMILIES as $capability => $family) {
            $method = lcfirst(str_replace(' ', '', ucwords(str_replace('-', ' ', $capability))));
            $astro->{Catalog::FAMILIES[$family]}->{$method}([]);
            $this->assertSame("https://api.96astroapi.com/v1/{$family}/{$capability}", $t->calls[$i++]->url);
        }
    }

    public function testKeyFromEnvironmentAndMissingKey(): void
    {
        $old = getenv('ASTRO_API_KEY');
        $oldEnv = $_ENV['ASTRO_API_KEY'] ?? null;
        unset($_ENV['ASTRO_API_KEY'], $_SERVER['ASTRO_API_KEY']);
        try {
            putenv('ASTRO_API_KEY=sk_test_env');
            $t = new FakeTransport([FakeTransport::ok([])]);
            (new Client(null, ['transport' => $t]))->tarot->tarotShuffle();
            $this->assertSame('Bearer sk_test_env', $t->calls[0]->headers['Authorization']);

            putenv('ASTRO_API_KEY');
            $this->expectException(Astro96Exception::class);
            $this->expectExceptionMessage('ASTRO_API_KEY');
            new Client(null, ['transport' => $t]);
        } finally {
            $old === false ? putenv('ASTRO_API_KEY') : putenv("ASTRO_API_KEY={$old}");
            if ($oldEnv !== null) {
                $_ENV['ASTRO_API_KEY'] = $oldEnv;
            }
        }
    }

    public function testInvalidAuthOptionIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Client('k', ['auth' => 'basic', 'transport' => new FakeTransport([])]);
    }

    public function testErrorEnvelopeBecomesTypedApiException(): void
    {
        [$astro] = $this->client([FakeTransport::err(422, 'invalid_request', ['X-Request-Id' => 'x'], ['field' => 'latitude', 'message' => 'too big'])]);
        try {
            $astro->vedicCharts->planetPositions(self::BIRTH);
            $this->fail('no exception');
        } catch (InvalidRequestException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame(422, $e->getCode());
            $this->assertSame('invalid_request', $e->errorCode);
            $this->assertSame('latitude', $e->field);
            $this->assertSame('too big', $e->apiMessage);
            $this->assertSame('req_err', $e->requestId);
            $this->assertFalse($e->retryable);
            $this->assertSame('422 invalid_request: too big (request req_err)', $e->getMessage());
            $this->assertIsArray($e->body);
        }
        $this->assertSame([], $this->sleeps, '422 is never retried');
    }

    /** @return iterable<string, array{int, string, class-string}> */
    public static function errorVocabulary(): iterable
    {
        yield 'bad json' => [400, 'invalid_request', InvalidRequestException::class];
        yield 'local time' => [422, 'ambiguous_local_time', InvalidRequestException::class];
        yield 'language' => [422, 'unsupported_language', InvalidRequestException::class];
        yield 'invalid key' => [401, 'invalid_key', AuthenticationException::class];
        yield 'expired key' => [401, 'expired_key', AuthenticationException::class];
        yield 'payment' => [402, 'payment_required', PaymentRequiredException::class];
        yield 'suspended' => [403, 'account_suspended', PermissionDeniedException::class];
        yield 'upgrade' => [403, 'plan_upgrade_required', PermissionDeniedException::class];
        yield 'not found' => [404, 'not_found', NotFoundException::class];
        yield 'conflict' => [409, 'idempotency_conflict', IdempotencyConflictException::class];
        yield 'link' => [410, 'link_expired', GoneException::class];
        yield 'file' => [410, 'file_expired', GoneException::class];
        yield 'too large' => [413, 'payload_too_large', PayloadTooLargeException::class];
        yield 'rate' => [429, 'rate_limited', RateLimitException::class];
        yield 'quota' => [429, 'quota_exhausted', QuotaExceededException::class];
        yield 'spend cap' => [429, 'spend_cap_reached', QuotaExceededException::class];
        yield 'budget' => [429, 'project_budget_exceeded', QuotaExceededException::class];
        yield 'computation' => [500, 'computation_failed', ServerException::class];
        yield 'not yet' => [501, 'not_yet_available', NotYetAvailableException::class];
        yield 'bad gateway' => [502, 'unavailable', ServerException::class];
        yield 'unavailable' => [503, 'unavailable', ServerException::class];
        yield 'deadline' => [504, 'deadline_exceeded', ServerException::class];
        yield 'teapot' => [418, 'teapot', ApiException::class];
    }

    /** @param class-string $class */
    #[DataProvider('errorVocabulary')]
    public function testErrorVocabularyMapsToExceptionClasses(int $status, string $code, string $class): void
    {
        [$astro] = $this->client([FakeTransport::err($status, $code)], ['max_retries' => 0]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ApiException $e) {
            $this->assertSame($class, $e::class);
            $this->assertSame($code, $e->errorCode);
            $this->assertSame($status, $e->status);
        }
    }

    public function testRateLimitedIsRetriedAfterRetryAfterWithTheSameKey(): void
    {
        [$astro, $t] = $this->client([FakeTransport::err(429, 'rate_limited', ['Retry-After' => '1'], ['retryable' => true]), FakeTransport::ok(['ok' => true])]);
        $res = $astro->vedicCharts->planetPositions(self::BIRTH);
        $this->assertTrue($res->data['ok']);
        $this->assertCount(2, $t->calls);
        $this->assertSame([1.0], $this->sleeps);
        $this->assertSame($t->calls[0]->headers['Idempotency-Key'], $t->calls[1]->headers['Idempotency-Key']);
        $this->assertSame($t->calls[0]->body, $t->calls[1]->body);
    }

    public function testRetryAfterHttpDateIsHonoured(): void
    {
        $date = gmdate('D, d M Y H:i:s', time() + 5) . ' GMT';
        [$astro] = $this->client([FakeTransport::err(503, 'unavailable', ['Retry-After' => $date]), FakeTransport::ok([])]);
        $astro->tarot->tarotShuffle();
        $this->assertCount(1, $this->sleeps);
        $this->assertGreaterThan(3.0, $this->sleeps[0]);
        $this->assertLessThanOrEqual(5.0, $this->sleeps[0]);
    }

    public function testRetryAfterLongerThanMaxDelayIsNotWaitedFor(): void
    {
        [$astro, $t] = $this->client([FakeTransport::err(429, 'rate_limited', ['Retry-After' => '120'])], ['max_retry_delay' => 30]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (RateLimitException $e) {
            $this->assertSame(120.0, $e->retryAfter);
        }
        $this->assertCount(1, $t->calls);
        $this->assertSame([], $this->sleeps);
    }

    public function testQuotaExhaustedIsNeverRetried(): void
    {
        [$astro, $t] = $this->client([FakeTransport::err(429, 'quota_exhausted', ['Retry-After' => '1'])]);
        $this->expectException(QuotaExceededException::class);
        try {
            $astro->tarot->tarotShuffle();
        } finally {
            $this->assertCount(1, $t->calls);
        }
    }

    public function test503IsRetriedUpToMaxRetriesWithBackoff(): void
    {
        [$astro, $t] = $this->client(array_fill(0, 4, FakeTransport::err(503, 'unavailable')), ['max_retries' => 3]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ServerException $e) {
            $this->assertSame(503, $e->status);
        }
        $this->assertCount(4, $t->calls);
        $this->assertCount(3, $this->sleeps);
        foreach ([0.5, 1.0, 2.0] as $i => $cap) { // jittered exponential: [cap/2, cap]
            $this->assertGreaterThanOrEqual($cap / 2, $this->sleeps[$i]);
            $this->assertLessThanOrEqual($cap, $this->sleeps[$i]);
        }
    }

    public function testRetryableServerErrorIsRetriedOnlyWithAnIdempotencyKey(): void
    {
        [$astro, $t] = $this->client([FakeTransport::err(500, 'computation_failed', [], ['retryable' => true]), FakeTransport::ok([])]);
        $astro->tarot->tarotShuffle();
        $this->assertCount(2, $t->calls);

        [$astro, $t] = $this->client([FakeTransport::err(500, 'computation_failed', [], ['retryable' => true])], ['auto_idempotency' => false]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ServerException $e) {
            $this->assertTrue($e->retryable);
        }
        $this->assertCount(1, $t->calls);
    }

    public function testNetworkFailureIsRetriedWithAKeyAndRaisedAsConnectionError(): void
    {
        [$astro, $t] = $this->client([FakeTransport::down(), FakeTransport::ok(['x' => 1])]);
        $this->assertSame(1, $astro->tarot->tarotShuffle()->data['x']);
        $this->assertCount(2, $t->calls);

        [$astro, $t] = $this->client([FakeTransport::down()], ['auto_idempotency' => false]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ApiConnectionException $e) {
            $this->assertFalse($e->timedOut);
        }
        $this->assertCount(1, $t->calls, 'a POST without a key is never repeated blindly');
    }

    public function testTimeoutIsReportedAndPerCallTimeoutIsPassed(): void
    {
        [$astro, $t] = $this->client([FakeTransport::down(true)], ['max_retries' => 0]);
        try {
            $astro->tarot->tarotShuffle([], ['timeout' => 7]);
            $this->fail('no exception');
        } catch (ApiConnectionException $e) {
            $this->assertTrue($e->timedOut);
        }
        $this->assertSame(7.0, $t->calls[0]->timeout);
    }

    public function testNonJsonErrorBodyStillBecomesApiException(): void
    {
        [$astro] = $this->client([new HttpResponse(502, ['Content-Type' => 'text/html'], '<html>bad gateway</html>', 'Bad Gateway')], ['max_retries' => 0]);
        try {
            $astro->tarot->tarotShuffle();
            $this->fail('no exception');
        } catch (ServerException $e) {
            $this->assertSame('unavailable', $e->errorCode);
            $this->assertSame('Bad Gateway', $e->apiMessage);
            $this->assertTrue($e->retryable);
            $this->assertSame('<html>bad gateway</html>', $e->body);
        }
    }

    public function testParseRetryAfterAndIdempotencyKey(): void
    {
        $this->assertSame(3.0, Client::parseRetryAfter('3'));
        $this->assertSame(0.0, Client::parseRetryAfter('-4'));
        $this->assertNull(Client::parseRetryAfter(null));
        $this->assertNull(Client::parseRetryAfter('soon'));
        $this->assertSame(30.0, Client::parseRetryAfter('Wed, 14 Oct 2026 07:00:30 GMT', (float) strtotime('2026-10-14T07:00:00Z')));
        $this->assertMatchesRegularExpression(self::UUID, Idempotency::key());
        $this->assertNotSame(Idempotency::key(), Idempotency::key());
    }

    public function testCreatePdfJobPostsTheRequest(): void
    {
        [$astro, $t] = $this->client([FakeTransport::ok(self::job(), [], [], 202)]);
        $res = $astro->createPdfJob('report-career', self::BIRTH, ['brand_name' => 'Shree'], null, ['idempotency_key' => 'k1']);
        $this->assertSame(202, $res->status);
        $this->assertSame('https://api.96astroapi.com/v1/jobs/pdf', $t->calls[0]->url);
        $this->assertSame(['capability' => 'report-career', 'input' => self::BIRTH, 'branding' => ['brand_name' => 'Shree']], $t->body(0));
        $this->assertSame('k1', $t->calls[0]->headers['Idempotency-Key']);
        $this->assertContains('report-career', Catalog::PDF_CAPABILITIES);
    }

    public function testWaitForJobThenDownloadWithoutTheKey(): void
    {
        $url = 'https://api.96astroapi.com/v1/files/tok123';
        $polls = [];
        [$astro, $t] = $this->client([
            FakeTransport::ok(self::job(['status' => 'running'])),
            FakeTransport::ok(self::job(['status' => 'succeeded', 'download_url' => $url])),
            new HttpResponse(200, ['Content-Type' => 'application/pdf'], '%PDF-1.7 bytes'),
        ]);
        $job = $astro->waitForJob(self::JOB_ID, ['interval' => 0.01, 'on_poll' => function (array $j) use (&$polls): void {
            $polls[] = $j['status'];
        }]);
        $this->assertSame(['running', 'succeeded'], $polls);
        $this->assertSame('GET', $t->calls[0]->method);
        $this->assertSame('https://api.96astroapi.com/v1/jobs/' . self::JOB_ID, $t->calls[0]->url);
        $this->assertArrayNotHasKey('Idempotency-Key', $t->calls[0]->headers);
        $this->assertSame([0.01], $this->sleeps);

        $pdf = $astro->downloadFile($job);
        $this->assertSame('%PDF-1.7 bytes', $pdf);
        $this->assertSame($url, $t->calls[2]->url);
        $this->assertArrayNotHasKey('Authorization', $t->calls[2]->headers, 'the signed link is fetched without the API key');
        $this->assertStringContainsString('application/pdf', $t->calls[2]->headers['Accept']);
    }

    public function testFailedJobRaisesJobException(): void
    {
        [$astro] = $this->client([FakeTransport::ok(self::job(['status' => 'failed', 'error' => ['code' => 'computation_failed', 'message' => 'boom']]))]);
        try {
            $astro->waitForJob(self::job());
            $this->fail('no exception');
        } catch (JobException $e) {
            $this->assertSame('failed', $e->reason);
            $this->assertSame('job ' . self::JOB_ID . ' failed: computation_failed (boom)', $e->getMessage());
        }
    }

    public function testWaitForJobTimesOut(): void
    {
        [$astro] = $this->client([FakeTransport::ok(self::job(['status' => 'queued']))]);
        try {
            $astro->waitForJob(self::JOB_ID, ['timeout' => 0, 'interval' => 1]);
            $this->fail('no exception');
        } catch (JobException $e) {
            $this->assertSame('timeout', $e->reason);
            $this->assertStringContainsString('is still queued', $e->getMessage());
        }
    }

    public function testExpiredLinkIsRefreshedOnce(): void
    {
        $old = 'https://api.96astroapi.com/v1/files/old';
        $new = 'https://api.96astroapi.com/v1/files/new';
        [$astro, $t] = $this->client([
            FakeTransport::err(410, 'link_expired'),
            FakeTransport::ok(self::job(['status' => 'succeeded', 'download_url' => $new])),
            new HttpResponse(200, [], 'PDF'),
        ]);
        $this->assertSame('PDF', $astro->downloadFile(self::job(['status' => 'succeeded', 'download_url' => $old])));
        $this->assertSame([$old, 'https://api.96astroapi.com/v1/jobs/' . self::JOB_ID, $new], array_map(fn ($r) => $r->url, $t->calls));
    }

    public function testDownloadWithoutUrlIsRefused(): void
    {
        [$astro] = $this->client([]);
        $this->expectException(Astro96Exception::class);
        $this->expectExceptionMessage('has no download_url yet');
        $astro->downloadFile(self::job());
    }

    public function testRenderPdfRunsTheWholeFlow(): void
    {
        [$astro, $t] = $this->client([
            FakeTransport::ok(self::job(), [], [], 202),
            FakeTransport::ok(self::job(['status' => 'succeeded', 'download_url' => 'https://api.96astroapi.com/v1/files/t'])),
            new HttpResponse(200, [], 'PDFBYTES'),
        ]);
        $this->assertSame('PDFBYTES', $astro->renderPdf('report-career', self::BIRTH, null, 'idem-1'));
        $this->assertSame('idem-1', $t->calls[0]->headers['Idempotency-Key']);
        $this->assertCount(3, $t->calls);
    }
}
