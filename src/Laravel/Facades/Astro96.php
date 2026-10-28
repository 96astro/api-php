<?php

declare(strict_types=1);

namespace Astro96\Api\Laravel\Facades;

use Astro96\Api\Client;
use Illuminate\Support\Facades\Facade;

/**
 * Facade for Astro96\Api\Client. Method calls are forwarded to the client; the capability families
 * are properties, so reach them through `Astro96::client()`:
 *
 * ```php
 * use Astro96\Api\Laravel\Facades\Astro96;
 * $res = Astro96::client()->vedicCharts->planetPositions($birth);
 * $job = Astro96::createPdfJob('report-career', $birth);
 * ```
 *
 * @method static \Astro96\Api\ApiResponse request(string $method, string $path, mixed $body = null, array $options = [], bool $auth = true, string $expect = 'json')
 * @method static \Astro96\Api\ApiResponse createPdfJob(string $capability, array $input, ?array $branding = null, ?string $language = null, array $options = [])
 * @method static \Astro96\Api\ApiResponse getJob(string $jobId, array $options = [])
 * @method static array waitForJob(string|array $job, array $options = [])
 * @method static string downloadFile(string|array $source, array $options = [])
 * @method static string renderPdf(string $capability, array $input, ?array $branding = null, ?string $idempotencyKey = null, float $timeout = 300.0)
 *
 * @see Client
 */
class Astro96 extends Facade
{
    /** The underlying client, for the family properties (`->vedicCharts`, `->panchang`, ...). */
    public static function client(): Client
    {
        return static::getFacadeRoot();
    }

    protected static function getFacadeAccessor(): string
    {
        return Client::class;
    }
}
