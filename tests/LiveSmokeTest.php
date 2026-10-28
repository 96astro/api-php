<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Client;
use Astro96\Api\Exception\AuthenticationException;
use Astro96\Api\Exception\InvalidRequestException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Calls the real API. Excluded from the default run; enable with
 *
 *     ASTRO96_LIVE_SMOKE=1 ASTRO_API_KEY=sk_test_... composer test-live
 *
 * (ASTRO_API_BASE_URL overrides https://api.96astroapi.com, e.g. http://127.0.0.1:8100 for a local
 * commercial service). Use a sandbox key: sandbox calls are not charged.
 */
#[Group('live')]
final class LiveSmokeTest extends TestCase
{
    private Client $astro;

    protected function setUp(): void
    {
        if (getenv('ASTRO96_LIVE_SMOKE') !== '1' || !getenv('ASTRO_API_KEY')) {
            $this->markTestSkipped('set ASTRO96_LIVE_SMOKE=1 and ASTRO_API_KEY=sk_test_... to call the real API');
        }
        $options = getenv('ASTRO_API_BASE_URL') ? ['base_url' => (string) getenv('ASTRO_API_BASE_URL')] : [];
        $this->astro = new Client(null, $options);
    }

    public function testPlanetPositions(): void
    {
        $res = $this->astro->vedicCharts->planetPositions([
            'datetime' => '1990-10-18T14:30:00', 'timezone' => 'Asia/Kolkata', 'latitude' => 28.6139, 'longitude' => 77.209,
        ]);
        $this->assertSame(200, $res->status);
        $this->assertIsArray($res->data);
        $this->assertNotEmpty($res->data);
        $this->assertNotNull($res->requestId);
        $this->assertNotNull($res->idempotencyKey);
    }

    public function testValidationErrorIsTyped(): void
    {
        try {
            $this->astro->vedicCharts->planetPositions(['datetime' => '1990-10-18T14:30:00', 'latitude' => 123, 'longitude' => 77.2]);
            $this->fail('no exception');
        } catch (InvalidRequestException $e) {
            $this->assertSame(422, $e->status);
            $this->assertSame('latitude', $e->field);
        }
    }

    public function testBadKeyIsAuthenticationError(): void
    {
        $bad = new Client('sk_test_not_a_real_key', getenv('ASTRO_API_BASE_URL') ? ['base_url' => (string) getenv('ASTRO_API_BASE_URL')] : []);
        $this->expectException(AuthenticationException::class);
        $bad->tarot->tarotShuffle();
    }
}
