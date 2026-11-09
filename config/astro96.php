<?php

// Laravel config for 96astro/api. Publish with:
//   php artisan vendor:publish --tag=astro96-config

return [
    // sk_test_... (sandbox, not charged) or sk_live_...
    'api_key' => env('ASTRO_API_KEY'),

    'base_url' => env('ASTRO_API_BASE_URL', 'https://api.96astroapi.com'),

    // "bearer" (Authorization: Bearer ...) or "x-api-key"
    'auth' => env('ASTRO_API_AUTH', 'bearer'),

    // Seconds per attempt; PDF and AI calls can take tens of seconds.
    'timeout' => (float) env('ASTRO_API_TIMEOUT', 60),

    // Retries after the first attempt (429 / 503 always; other retryable errors only with an idempotency key).
    'max_retries' => (int) env('ASTRO_API_MAX_RETRIES', 2),

    // A Retry-After longer than this is not waited for (the error is thrown instead).
    'max_retry_delay' => (float) env('ASTRO_API_MAX_RETRY_DELAY', 30),

    // Signing secret(s) of your webhook endpoint, comma-separated while you rotate.
    'webhook_secret' => env('ASTRO_WEBHOOK_SECRET'),

    // Largest accepted clock difference for webhook timestamps, in seconds.
    'webhook_tolerance' => (int) env('ASTRO_WEBHOOK_TOLERANCE', 300),
];
