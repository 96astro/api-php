<?php

declare(strict_types=1);

namespace Astro96\Api\Laravel;

use Astro96\Api\Client;
use Astro96\Api\Http\GuzzleTransport;
use Illuminate\Support\ServiceProvider;

/**
 * Registers Astro96\Api\Client as a singleton configured from config/astro96.php (auto-discovered).
 * Only loaded inside Laravel; the SDK itself does not depend on Laravel.
 */
class Astro96ServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/astro96.php', 'astro96');

        $this->app->singleton(Client::class, static function ($app): Client {
            $config = $app->make('config')->get('astro96', []);
            $options = array_filter([
                'base_url' => $config['base_url'] ?? null,
                'auth' => $config['auth'] ?? null,
                'timeout' => $config['timeout'] ?? null,
                'max_retries' => $config['max_retries'] ?? null,
                'max_retry_delay' => $config['max_retry_delay'] ?? null,
            ], static fn ($v) => $v !== null && $v !== '');
            if (GuzzleTransport::available()) {
                $options['transport'] = new GuzzleTransport();
            }
            $key = $config['api_key'] ?? null;
            return new Client(is_string($key) && $key !== '' ? $key : null, $options);
        });
        $this->app->alias(Client::class, 'astro96');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../../config/astro96.php' => $this->app->configPath('astro96.php')], 'astro96-config');
        }
    }

    /** @return list<string> */
    public function provides(): array
    {
        return [Client::class, 'astro96'];
    }
}
