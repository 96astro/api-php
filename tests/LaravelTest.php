<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Client;
use Astro96\Api\Laravel\Astro96ServiceProvider;
use Astro96\Api\Laravel\Facades\Astro96;
use Astro96\Api\Laravel\VerifyWebhookSignature;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;

/** The optional Laravel layer on a bare container (no framework boot needed). */
final class LaravelTest extends TestCase
{
    private Container $app;

    protected function setUp(): void
    {
        $this->app = new class () extends Container {
            public function runningInConsole(): bool
            {
                return false;
            }
        };
        Container::setInstance($this->app);
        $this->app->instance('config', new Repository([]));
        Facade::setFacadeApplication($this->app);
        Facade::clearResolvedInstances();
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }

    private function register(array $config): void
    {
        (new Astro96ServiceProvider($this->app))->register();
        foreach ($config as $k => $v) {
            $this->app['config']->set("astro96.{$k}", $v);
        }
    }

    public function testProviderBuildsAConfiguredSingletonAndTheFacadeReachesIt(): void
    {
        $this->register(['api_key' => 'sk_test_laravel', 'base_url' => 'http://localhost:9999/', 'auth' => 'x-api-key', 'max_retries' => 5]);
        $this->assertSame('https://api.96astroapi.com', (new Repository(require dirname(__DIR__) . '/config/astro96.php'))->get('base_url'));

        $client = $this->app->make(Client::class);
        $this->assertSame($client, $this->app->make('astro96'));
        $this->assertSame('http://localhost:9999', $client->baseUrl);
        $this->assertSame($client, Astro96::client());
        $this->assertTrue(method_exists(Astro96::client()->vedicCharts, 'planetPositions'));
    }

    public function testMiddlewareVerifiesAndAttachesTheEvent(): void
    {
        $this->register(['api_key' => 'k', 'webhook_secret' => 'whsec_old, whsec_new', 'webhook_tolerance' => 300]);
        $body = '{"id":"evt_9","type":"webhook.test","created":1,"environment":"test","data":{}}';
        $ts = (string) time();
        $sig = hash_hmac('sha256', "evt_9.{$ts}.{$body}", 'whsec_new');
        $request = Request::create('/webhooks/96astro', 'POST', [], [], [], [
            'HTTP_ASTRO_WEBHOOK_ID' => 'evt_9', 'HTTP_ASTRO_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_ASTRO_SIGNATURE' => "v1={$sig}",
            'CONTENT_TYPE' => 'application/json',
        ], $body);

        $res = (new VerifyWebhookSignature($this->app['config']))->handle($request, fn (Request $r) => new Response($r->attributes->get('astro96_event')['id']));
        $this->assertSame('evt_9', $res->getContent());
    }

    public function testMiddlewareRefusesABadSignatureWith400(): void
    {
        $this->register(['api_key' => 'k', 'webhook_secret' => 'whsec_x']);
        $request = Request::create('/webhooks/96astro', 'POST', [], [], [], [
            'HTTP_ASTRO_WEBHOOK_ID' => 'evt_9', 'HTTP_ASTRO_WEBHOOK_TIMESTAMP' => (string) time(), 'HTTP_ASTRO_SIGNATURE' => 'v1=' . str_repeat('a', 64),
        ], '{}');
        $res = (new VerifyWebhookSignature($this->app['config']))->handle($request, fn () => new Response('reached'));
        $this->assertSame(400, $res->getStatusCode());
        $this->assertSame('no webhook signature matched', $res->getContent());
    }
}
