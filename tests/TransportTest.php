<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Client;
use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Exception\RateLimitException;
use Astro96\Api\Http\CurlTransport;
use Astro96\Api\Http\GuzzleTransport;
use Astro96\Api\Http\Psr18Transport;
use Astro96\Api\Http\Transport;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Psr7\HttpFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Every shipped transport against a real local HTTP server (`php -S` with tests/fixtures/echo-server.php). */
final class TransportTest extends TestCase
{
    /** @var resource|null */
    private static $server = null;
    private static string $base = '';

    public static function setUpBeforeClass(): void
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
        fclose($sock);
        $cmd = [PHP_BINARY, '-n', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/echo-server.php'];
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        self::$server = proc_open($cmd, [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']], $pipes);
        self::$base = "http://127.0.0.1:{$port}";
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($c) {
                fclose($c);
                return;
            }
            usleep(50_000);
        }
        self::fail('php -S did not start');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** @return iterable<string, array{callable(): Transport}> */
    public static function transports(): iterable
    {
        yield 'curl' => [fn () => new CurlTransport()];
        yield 'guzzle' => [fn () => new GuzzleTransport()];
        yield 'psr-18 (guzzle as a PSR-18 client)' => [fn () => new Psr18Transport(new GuzzleClient(['timeout' => 2]), new HttpFactory(), new HttpFactory())];
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testPostRoundTrip(callable $make): void
    {
        $astro = new Client('sk_test_t', ['transport' => $make(), 'base_url' => self::$base]);
        $res = $astro->vedicCharts->planetPositions(['datetime' => '1990-10-18T14:30:00', 'latitude' => 28.6, 'longitude' => 77.2, 'name' => 'राम']);
        $this->assertSame('POST', $res->data['method']);
        $this->assertSame('/v1/vedic-charts/planet-positions', $res->data['path']);
        $this->assertSame('Bearer sk_test_t', $res->data['headers']['authorization']);
        $this->assertSame($res->idempotencyKey, $res->data['headers']['idempotency-key']);
        $this->assertSame('application/json', $res->data['headers']['content-type']);
        $this->assertSame('राम', json_decode($res->data['body'], true)['name']);
        $this->assertSame('req_echo', $res->requestId);
        $this->assertSame(41, $res->rateLimit?->remaining);
    }

    /** @param callable(): Transport $make */
    #[DataProvider('transports')]
    public function testErrorStatusIsReturnedNotThrownByTheTransport(callable $make): void
    {
        $sleeps = [];
        $astro = new Client('k', ['transport' => $make(), 'base_url' => self::$base, 'max_retries' => 1, 'sleeper' => function (float $s) use (&$sleeps): void {
            $sleeps[] = $s;
        }]);
        try {
            $astro->request('GET', '/status/429');
            $this->fail('no exception');
        } catch (RateLimitException $e) {
            $this->assertSame(7.0, $e->retryAfter);
            $this->assertSame('rate_limited', $e->errorCode);
        }
        $this->assertSame([7.0], $sleeps);
    }

    public function testCurlAndGuzzleTimeoutsAreConnectionErrors(): void
    {
        foreach ([new CurlTransport(), new GuzzleTransport()] as $transport) {
            $astro = new Client('k', ['transport' => $transport, 'base_url' => self::$base, 'timeout' => 0.5, 'max_retries' => 0]);
            try {
                $astro->request('GET', '/slow');
                $this->fail('no exception');
            } catch (ApiConnectionException $e) {
                $this->assertTrue($e->timedOut, $transport::class . ': ' . $e->getMessage());
            }
        }
    }

    public function testRefusedConnectionIsAConnectionError(): void
    {
        $astro = new Client('k', ['transport' => new CurlTransport(), 'base_url' => 'http://127.0.0.1:1', 'max_retries' => 0, 'timeout' => 2]);
        $this->expectException(ApiConnectionException::class);
        $astro->request('GET', '/x');
    }

    public function testDefaultTransportIsGuzzleWhenInstalled(): void
    {
        $this->assertInstanceOf(GuzzleTransport::class, (new Client('k'))->transport());
    }
}
