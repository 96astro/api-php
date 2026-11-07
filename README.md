# 96astro/api

The official PHP client for the [96Astro API](https://96astroapi.com): Vedic and Western astrology, panchang, matching, reports and AI readings over one JSON API.

- PHP 8.1+, PSR-4 (`Astro96\Api\`), no required runtime dependency beyond the PSR interfaces
- A typed method for every capability, generated from the OpenAPI document (PHPStan/Psalm array shapes for request bodies)
- Bring any PSR-18 client, or let it pick: the WordPress HTTP API inside WordPress, Guzzle when installed (Laravel ships it), else ext-curl
- Retries with backoff on `429` / `503` (honouring `Retry-After`), automatic idempotency keys so a retry is never charged twice
- Typed exceptions mirroring the API's error codes, with `errorCode`, `field`, `retryable` and `requestId`; rate-limit headers on every response
- PDF job helpers (`createPdfJob`, `waitForJob`, `downloadFile`, `renderPdf`) and webhook signature verification
- Optional Laravel integration: auto-discovered service provider, `Astro96` facade, publishable config, webhook middleware

> **Not on Packagist yet.** Publishing is in progress; until then `composer require 96astro/api` will not find the package. Contact support for a pre-release, or install it from this repository with a Composer `path` repository (see Development).

## Install

```bash
composer require 96astro/api
# optional, recommended outside WordPress:
composer require guzzlehttp/guzzle
```

## Quickstart

```php
use Astro96\Api\Client;

$astro = new Client(); // reads ASTRO_API_KEY (sk_test_... or sk_live_...)

$res = $astro->vedicCharts->planetPositions([
    'datetime' => '1990-10-18T14:30:00', 'timezone' => 'Asia/Kolkata',
    'latitude' => 28.6139, 'longitude' => 77.209,
]);

echo $res->data['ascendant']['sign'], ' ', $res->creditsUsed(), ' ', $res->rateLimit?->remaining;
```

`POST /v1/{family}/{capability}` is `$client->family->capability()` in camelCase (`/v1/panchang/daily-panchang` is `$astro->panchang->dailyPanchang([...])`). Any path also works through `$astro->call('/v1/panchang/daily-panchang', $input)`.

Each call returns an `ApiResponse` with `data`, `meta`, `status`, `requestId`, `rateLimit`, `replayed`, `idempotencyKey` and `headers`. Response `data` is a plain array; the field list in each method's docblock comes from the endpoint's published schema: a hand-written contract, a schema measured from several real answers, or (for a few) the one example.

### Errors

```php
use Astro96\Api\Exception\{ApiException, ApiConnectionException, InvalidRequestException, QuotaExceededException, RateLimitException};

try {
    $astro->vedicCharts->planetPositions($birth);
} catch (InvalidRequestException $e) {       // 400 / 422
    echo $e->errorCode, ' on ', $e->field;
} catch (QuotaExceededException $e) {         // 429 quota_exhausted / spend_cap_reached: retrying will not help
} catch (RateLimitException $e) {             // 429 rate_limited, after the SDK's own retries
} catch (ApiException $e) {                   // every other API error
    echo $e->status, ' ', $e->errorCode, ' ', $e->requestId;
} catch (ApiConnectionException $e) {         // DNS, TLS, timeout
}
```

Other subclasses: `AuthenticationException` (401), `PaymentRequiredException` (402), `PermissionDeniedException` (403), `NotFoundException` (404), `IdempotencyConflictException` (409), `GoneException` (410), `PayloadTooLargeException` (413), `NotYetAvailableException` (501), `ServerException` (5xx). All extend `Astro96Exception`.

### Retries and idempotency

`429 rate_limited` and `503` are retried after `Retry-After` (up to `max_retry_delay`, default 30 s), with exponential backoff and jitter when no header is sent. Other `retryable` errors and network failures are retried only when the call has an idempotency key, which every POST gets automatically (`auto_idempotency => true`) and keeps across retries. `quota_exhausted` is never retried. Pass your own key to retry safely across processes or queue jobs:

```php
use Astro96\Api\Idempotency;

$key = $order->astro_key ??= Idempotency::key();
$astro->reports->reportCareer($birth, ['idempotency_key' => $key]);
```

Client options (second constructor argument): `base_url`, `auth` (`'bearer'` or `'x-api-key'`), `timeout` (60 s per attempt), `max_retries` (2), `max_retry_delay` (30 s), `auto_idempotency` (true), `headers`, and the HTTP stack: `transport`, or `http_client` + `request_factory` + `stream_factory` (any PSR-18 client with PSR-17 factories). Per call: `idempotency_key`, `timeout`, `max_retries`, `headers`.

```php
// Any PSR-18 client, e.g. Symfony HttpClient:
$psr = new \Symfony\Component\HttpClient\Psr18Client();
$astro = new Client(getenv('ASTRO_API_KEY'), ['http_client' => $psr, 'request_factory' => $psr, 'stream_factory' => $psr]);
```

### PDF reports

```php
$pdf = $astro->renderPdf('report-career', $birth, ['brand_name' => 'Shree Jyotish Kendra']);
file_put_contents('career.pdf', $pdf);

// or step by step (e.g. across queue jobs):
$job = $astro->createPdfJob('report-career', $birth)->data;   // 202, status "queued"
$done = $astro->waitForJob($job, ['timeout' => 300]);          // throws JobException on failed/expired/timeout
$bytes = $astro->downloadFile($done);                          // refreshes an expired link once
```

`Astro96\Api\Generated\Catalog::PDF_CAPABILITIES` lists the capabilities that render as PDF.

### Webhooks

```php
use Astro96\Api\Webhook;
use Astro96\Api\Exception\WebhookSignatureException;

try {
    $event = Webhook::verifyGlobals(getenv('ASTRO_WEBHOOK_SECRET')); // plain PHP: php://input + $_SERVER
    // or Webhook::verify($rawBody, $headers, $secret)
} catch (WebhookSignatureException $e) {
    http_response_code(400);
    exit;
}
// deduplicate on $event['id'], then handle $event['type'] (e.g. "job.succeeded")
```

It checks `Astro-Signature` (`v1=<hex HMAC-SHA256(secret, "{id}.{timestamp}.{raw body}")>`) against `Astro-Webhook-Id` and `Astro-Webhook-Timestamp`, rejects timestamps more than 5 minutes off, compares in constant time and accepts several secrets (an array) while you rotate. Always pass the raw body, never re-encoded JSON.

## Laravel

The service provider and the `Astro96` facade are auto-discovered. Add to `.env`:

```dotenv
ASTRO_API_KEY=sk_test_...
ASTRO_WEBHOOK_SECRET=whsec_...
```

Optionally publish the config (`config/astro96.php`: base URL, auth style, timeout, retries, webhook tolerance):

```bash
php artisan vendor:publish --tag=astro96-config
```

```php
use Astro96\Api\Client;
use Astro96\Api\Laravel\Facades\Astro96;

$chart = Astro96::client()->vedicCharts->planetPositions($birth)->data;   // facade (families are properties)
$job = Astro96::createPdfJob('report-career', $birth)->data;               // client methods forward directly

public function show(Client $astro) { /* or inject the singleton */ }
```

Webhook route (the middleware answers 400 to an unsigned or stale request and puts the verified event on the request):

```php
use Astro96\Api\Laravel\VerifyWebhookSignature;

Route::post('/webhooks/96astro', function (Illuminate\Http\Request $request) {
    $event = $request->attributes->get('astro96_event');
    // ...
    return response()->noContent();
})->middleware(VerifyWebhookSignature::class)
  ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
```

## WordPress

Inside WordPress the client sends through the WordPress HTTP API (`wp_remote_request`), so the site's proxy and CA settings apply and no Guzzle is needed. Install with Composer in your plugin and load `vendor/autoload.php`; keep the key out of the code (an option or `wp-config.php` constant).

```php
require __DIR__ . '/vendor/autoload.php';

add_shortcode('daily_panchang', function () {
    $astro = new \Astro96\Api\Client(ASTRO_API_KEY); // define('ASTRO_API_KEY', 'sk_live_...') in wp-config.php
    $day = get_transient('astro96_panchang_' . wp_date('Y-m-d'));
    if ($day === false) {
        $day = $astro->panchang->dailyPanchang([
            'date' => wp_date('Y-m-d'), 'timezone' => 'Asia/Kolkata', 'latitude' => 28.6139, 'longitude' => 77.209,
        ])->data;
        set_transient('astro96_panchang_' . wp_date('Y-m-d'), $day, DAY_IN_SECONDS);
    }
    return esc_html($day['vara'] . ', ' . ($day['limbs']['tithi']['name'] ?? ''));
});

// Webhook endpoint
add_action('rest_api_init', function () {
    register_rest_route('astro96/v1', '/webhook', [
        'methods' => 'POST',
        'permission_callback' => '__return_true', // the signature is the authentication
        'callback' => function (WP_REST_Request $request) {
            try {
                $event = \Astro96\Api\Webhook::verify($request->get_body(), $request->get_headers(), ASTRO_WEBHOOK_SECRET);
            } catch (\Astro96\Api\Exception\WebhookSignatureException $e) {
                return new WP_Error('bad_signature', 'invalid signature', ['status' => 400]);
            }
            // handle $event
            return new WP_REST_Response(null, 204);
        },
    ]);
});
```

Cache what you can (a panchang for a day and place does not change): every live call costs credits.

Full guide: https://96astroapi.com/docs/sdks

> **Not wrapped yet:** AI streaming (`stream: true` / `Accept: text/event-stream`), the `/v1/conversations` routes and `.ics` calendar export. This client always asks for JSON; send those routes over plain HTTP (or `$astro->call()` for the non-streaming `POST` ones) (see https://96astroapi.com/docs/ai-streaming-and-chat). Everything else, including the image options (`image_format`, `image_theme`, `image_delivery`), works through the typed inputs.

## Development

The typed methods in `src/Generated/` are written by `tools/sdk/generate_php.py` from `packages/registry/openapi/v1.openapi.json`; never edit them by hand. Everything else in `src/` is hand-written.

```bash
composer install
composer generate          # python ../../tools/sdk/generate_php.py, after the OpenAPI document changes
composer check-generated   # exit 1 if src/Generated is stale (CI)
composer test              # PHPUnit: fake transport, real curl/Guzzle against a local echo server, Laravel, WordPress, drift
ASTRO96_LIVE_SMOKE=1 ASTRO_API_KEY=sk_test_... composer test-live   # calls the real API (sandbox: not charged)
```

To try it from another project before release, add a path repository: `"repositories": [{"type": "path", "url": "../96astro_api_website/packages/sdk-php"}]` then `composer require 96astro/api:@dev`.

Publishing: see `docs/31-publishing-sdks.md` (Packagist reads a split mirror repository; tags `v<version>`).

## Audio reports

Any report can come back as a spoken MP3 in 26 languages, as a background job. Poll it as you would a PDF job. See [Audio reports](https://96astroapi.com/docs/audio-reports).

```php
$job = $client->createAudioJob(
    'report-career',
    ['datetime' => '1990-10-18T14:30:00', 'timezone' => 'Asia/Kolkata', 'latitude' => 28.6139, 'longitude' => 77.209],
    ['language' => 'hi', 'minutes' => 5],
);
```

Palm and face readings are ordinary calls that take a photo as base64: see [Palm and face readings](https://96astroapi.com/docs/photo-readings).
