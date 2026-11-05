<?php

declare(strict_types=1);

namespace Astro96\Api;

use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Exception\ApiException;
use Astro96\Api\Exception\Astro96Exception;
use Astro96\Api\Exception\JobException;
use Astro96\Api\Generated\Catalog;
use Astro96\Api\Generated\Families;
use Astro96\Api\Http\CurlTransport;
use Astro96\Api\Http\GuzzleTransport;
use Astro96\Api\Http\HttpRequest;
use Astro96\Api\Http\HttpResponse;
use Astro96\Api\Http\Psr18Transport;
use Astro96\Api\Http\Transport;
use Astro96\Api\Http\WordPressTransport;
use Psr\Http\Client\ClientInterface;

/**
 * The 96Astro API client.
 *
 * ```php
 * $astro = new \Astro96\Api\Client();            // reads ASTRO_API_KEY
 * $res = $astro->vedicCharts->planetPositions([
 *     'datetime' => '1990-10-18T14:30:00', 'timezone' => 'Asia/Kolkata',
 *     'latitude' => 28.6139, 'longitude' => 77.209,
 * ]);
 * echo $res->data['ascendant']['sign'];
 * ```
 *
 * `POST /v1/{family}/{capability}` is `$client->family->capability()` in camelCase. The hand-written
 * transport (auth, timeouts, retries with backoff, idempotency keys, error mapping, rate-limit
 * headers, PDF jobs) lives here; the family properties come from the generated Families trait.
 */
class Client
{
    use Families;

    public const VERSION = Version::SDK;

    public readonly string $baseUrl;
    private readonly string $apiKey;
    private readonly string $auth;
    private readonly float $timeout;
    private readonly int $maxRetries;
    private readonly float $maxRetryDelay;
    private readonly bool $autoIdempotency;
    /** @var array<string, string> */
    private readonly array $headers;
    private readonly Transport $transport;
    /** @var callable(float): void */
    private $sleeper;

    /**
     * @param array{
     *     base_url?: string,
     *     auth?: 'bearer'|'x-api-key',
     *     timeout?: int|float,
     *     max_retries?: int,
     *     max_retry_delay?: int|float,
     *     auto_idempotency?: bool,
     *     headers?: array<string, string>,
     *     transport?: Transport,
     *     http_client?: ClientInterface,
     *     request_factory?: \Psr\Http\Message\RequestFactoryInterface,
     *     stream_factory?: \Psr\Http\Message\StreamFactoryInterface,
     *     sleeper?: callable(float): void,
     * } $options
     *
     * Options: `base_url` (default https://api.96astroapi.com), `auth` ("bearer" or "x-api-key"),
     * `timeout` (seconds per attempt, 60), `max_retries` (2), `max_retry_delay` (30 s: a longer
     * Retry-After is not waited for), `auto_idempotency` (true: every POST gets a key), `headers`,
     * and the HTTP stack: `transport`, or `http_client` + `request_factory` + `stream_factory` (PSR-18/17).
     * Without either: WordPress HTTP API inside WordPress, else Guzzle when installed, else ext-curl.
     */
    public function __construct(?string $apiKey = null, array $options = [])
    {
        $key = $apiKey ?? self::env('ASTRO_API_KEY');
        if ($key === null || $key === '') {
            throw new Astro96Exception('No API key: pass one to new Client(...) or set the ASTRO_API_KEY environment variable.');
        }
        $auth = $options['auth'] ?? 'bearer';
        if (!in_array($auth, ['bearer', 'x-api-key'], true)) {
            throw new \InvalidArgumentException("auth must be 'bearer' or 'x-api-key'");
        }
        $this->apiKey = $key;
        $this->auth = $auth;
        $this->baseUrl = rtrim($options['base_url'] ?? Catalog::DEFAULT_BASE_URL, '/');
        $this->timeout = (float) ($options['timeout'] ?? 60.0);
        $this->maxRetries = max(0, (int) ($options['max_retries'] ?? 2));
        $this->maxRetryDelay = (float) ($options['max_retry_delay'] ?? 30.0);
        $this->autoIdempotency = (bool) ($options['auto_idempotency'] ?? true);
        $this->headers = $options['headers'] ?? [];
        $this->transport = $options['transport'] ?? self::defaultTransport($options);
        $this->sleeper = $options['sleeper'] ?? static function (float $seconds): void {
            if ($seconds > 0) {
                usleep((int) round($seconds * 1_000_000));
            }
        };
        $this->initFamilies();
    }

    /** @param array<string, mixed> $options */
    private static function defaultTransport(array $options): Transport
    {
        if (isset($options['http_client'])) {
            if (!isset($options['request_factory'], $options['stream_factory'])) {
                throw new \InvalidArgumentException('http_client needs request_factory and stream_factory (PSR-17) too.');
            }
            return new Psr18Transport($options['http_client'], $options['request_factory'], $options['stream_factory']);
        }
        if (WordPressTransport::available()) {
            return new WordPressTransport();
        }
        if (GuzzleTransport::available()) {
            return new GuzzleTransport();
        }
        return new CurlTransport();
    }

    private static function env(string $name): ?string
    {
        $v = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
        return is_string($v) && $v !== '' ? $v : null;
    }

    public function transport(): Transport
    {
        return $this->transport;
    }

    // ------------------------------------------------------------------ requests

    /**
     * Low-level request. Returns the parsed envelope, or the raw bytes in `data` with `expect: 'bytes'`.
     *
     * @param array{idempotency_key?: string, timeout?: int|float, max_retries?: int, headers?: array<string, string>} $options
     * @return ApiResponse<mixed>
     * @throws Astro96Exception
     */
    public function request(string $method, string $path, mixed $body = null, array $options = [], bool $auth = true, string $expect = 'json'): ApiResponse
    {
        $method = strtoupper($method);
        $url = preg_match('#^https?://#i', $path) ? $path : $this->baseUrl . '/' . ltrim($path, '/');
        $key = $options['idempotency_key'] ?? null;
        if ($key === null && $method === 'POST' && $this->autoIdempotency) {
            $key = Idempotency::key();
        }
        $headers = [
            'Accept' => $expect === 'bytes' ? 'application/pdf, application/json' : 'application/json',
            'User-Agent' => '96astro-php/' . self::VERSION . ' PHP/' . PHP_VERSION,
        ];
        $headers = array_merge($headers, $this->headers);
        if ($auth) {
            if ($this->auth === 'x-api-key') {
                $headers['X-API-Key'] = $this->apiKey;
            } else {
                $headers['Authorization'] = 'Bearer ' . $this->apiKey;
            }
        }
        $content = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $content = json_encode(
                $body === [] ? new \stdClass() : $body,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR,
            );
        }
        if ($key !== null && $key !== '') {
            $headers['Idempotency-Key'] = $key;
        }
        $headers = array_merge($headers, $options['headers'] ?? []);
        $timeout = (float) ($options['timeout'] ?? $this->timeout);
        $maxRetries = max(0, (int) ($options['max_retries'] ?? $this->maxRetries));
        $req = new HttpRequest($method, $url, $headers, $content, $timeout);

        for ($attempt = 0; ; $attempt++) {
            try {
                $res = $this->transport->send($req);
            } catch (ApiConnectionException $e) {
                // Without an idempotency key a POST may have run on the server: never repeat it blindly.
                if ($attempt >= $maxRetries || !($method === 'GET' || $key)) {
                    throw $e;
                }
                ($this->sleeper)($this->backoff($attempt, null));
                continue;
            }
            if ($res->isSuccess()) {
                return $this->success($res, $key, $expect);
            }
            $err = self::error($res);
            $delay = $attempt < $maxRetries && self::shouldRetry($err, $method, $key) ? $this->backoff($attempt, $err->retryAfter) : null;
            if ($delay === null) {
                throw $err;
            }
            ($this->sleeper)($delay);
        }
    }

    /**
     * Called by the generated capability methods: `POST {path}` with the input as the JSON body.
     *
     * @internal
     * @param array<string, mixed> $input
     * @param array{idempotency_key?: string, timeout?: int|float, max_retries?: int, headers?: array<string, string>} $options
     * @return ApiResponse<array<string, mixed>>
     */
    public function call(string $path, array $input, array $options = []): ApiResponse
    {
        return $this->request('POST', $path, $input, $options);
    }

    /**
     * 429 `rate_limited` and 503 never ran the calculation, so they are always safe to retry. Other
     * `retryable` errors (500, 502, 504, an idempotency key still in progress) are retried only when the
     * request carries an idempotency key, so a retry can never be charged twice. `quota_exhausted`,
     * `spend_cap_reached` and `project_budget_exceeded` (also 429) are never retried.
     */
    public static function shouldRetry(ApiException $err, string $method, ?string $key): bool
    {
        if (in_array($err->errorCode, ApiException::NOT_RETRIED, true)) {
            return false;
        }
        if ($err->status === 429 || $err->status === 503) {
            return true;
        }
        return $err->retryable && ($method === 'GET' || ($key !== null && $key !== ''));
    }

    /** Seconds to wait before the next attempt, or null when Retry-After asks for longer than max_retry_delay. */
    private function backoff(int $attempt, ?float $retryAfter): ?float
    {
        if ($retryAfter !== null) {
            return $retryAfter > $this->maxRetryDelay ? null : $retryAfter;
        }
        $cap = min($this->maxRetryDelay, 0.5 * (2 ** $attempt));
        return $cap / 2 + (mt_rand() / mt_getrandmax()) * $cap / 2; // exponential backoff with jitter
    }

    /** `Retry-After` in seconds (delta-seconds or an HTTP date), or null. */
    public static function parseRetryAfter(?string $value, ?float $now = null): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }
        $v = trim($value);
        if (is_numeric($v)) {
            return max(0.0, (float) $v);
        }
        $at = strtotime($v);
        if ($at === false) {
            return null;
        }
        return max(0.0, $at - ($now ?? microtime(true)));
    }

    /** @return ApiResponse<mixed> */
    private function success(HttpResponse $res, ?string $key, string $expect): ApiResponse
    {
        $rateLimit = RateLimitInfo::fromHeaders($res->headers);
        $requestId = $res->header('X-Request-Id');
        if ($expect === 'bytes') {
            return new ApiResponse($res->body, [], $res->status, $requestId, $rateLimit, false, $key, $res->headers);
        }
        try {
            $body = $res->body === '' ? [] : json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new ApiException($res->status, 'invalid_response', 'the API answered with a body that is not JSON', null, false, $requestId, null, $rateLimit, $res->headers, $res->body);
        }
        $meta = is_array($body['meta'] ?? null) ? $body['meta'] : [];
        return new ApiResponse(
            $body['data'] ?? null,
            $meta,
            $res->status,
            $requestId ?? (is_string($meta['request_id'] ?? null) ? $meta['request_id'] : null),
            $rateLimit,
            $res->header('Idempotent-Replayed') === 'true' || ($meta['replayed'] ?? null) === true,
            $key,
            $res->headers,
        );
    }

    /** Maps a non-2xx response to the ApiException subclass for its status and code. */
    public static function error(HttpResponse $res): ApiException
    {
        $body = null;
        if ($res->body !== '') {
            try {
                $body = json_decode($res->body, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $body = $res->body;
            }
        }
        $e = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
        $status = $res->status;
        $str = static fn ($v): ?string => is_string($v) ? $v : null;
        $code = $str($e['code'] ?? null) ?? ($status === 429 ? 'rate_limited' : ($status >= 500 ? 'unavailable' : 'http_error'));
        $retryable = $e['retryable'] ?? null;
        $class = ApiException::classFor($status, $code);
        return new $class(
            $status,
            $code,
            $str($e['message'] ?? null) ?? ($res->reason !== '' ? $res->reason : "HTTP {$status}"),
            $str($e['field'] ?? null),
            is_bool($retryable) ? $retryable : in_array($status, [429, 502, 503, 504], true),
            $str($e['request_id'] ?? null) ?? $res->header('X-Request-Id'),
            self::parseRetryAfter($res->header('Retry-After')),
            RateLimitInfo::fromHeaders($res->headers),
            $res->headers,
            $body,
        );
    }

    // ------------------------------------------------------------------ PDF jobs

    /**
     * Queue a PDF of a report capability (`POST /v1/jobs/pdf`). Answers 202 with the job.
     *
     * @param string $capability one of Catalog::PDF_CAPABILITIES, e.g. "report-career"
     * @param array<string, mixed> $input that capability's request body
     * @param array<string, mixed>|null $branding e.g. ['brand_name' => 'Shree Jyotish Kendra']
     * @param array{idempotency_key?: string, timeout?: int|float, max_retries?: int, headers?: array<string, string>} $options
     * @return ApiResponse<array<string, mixed>>
     */
    public function createAudioJob(string $capability, array $input, array $settings = [], array $options = []): ApiResponse
    {
        // $settings: language, minutes, tone, voice, listener_name.
        $body = ['capability' => $capability, 'input' => $input === [] ? new \stdClass() : $input] + $settings;
        return $this->request('POST', '/v1/jobs/audio', $body, $options);
    }

    public function createPdfJob(string $capability, array $input, ?array $branding = null, ?string $language = null, array $options = []): ApiResponse
    {
        $body = ['capability' => $capability, 'input' => $input === [] ? new \stdClass() : $input];
        if ($branding !== null) {
            $body['branding'] = $branding;
        }
        if ($language !== null) {
            $body['language'] = $language;
        }
        return $this->request('POST', '/v1/jobs/pdf', $body, $options);
    }

    /**
     * Poll a job once (`GET /v1/jobs/{id}`). Free.
     *
     * @param array{timeout?: int|float, max_retries?: int, headers?: array<string, string>} $options
     * @return ApiResponse<array<string, mixed>>
     */
    public function getJob(string $jobId, array $options = []): ApiResponse
    {
        return $this->request('GET', '/v1/jobs/' . rawurlencode($jobId), null, $options);
    }

    /**
     * Poll until the job is final. Returns the succeeded job (`download_url` is set); throws
     * JobException when it failed, expired or did not finish within `timeout` seconds.
     *
     * @param string|array<string, mixed> $job the job or its id
     * @param array{interval?: int|float, max_interval?: int|float, timeout?: int|float, on_poll?: callable(array<string, mixed>): void} $options
     * @return array<string, mixed>
     */
    public function waitForJob(string|array $job, array $options = []): array
    {
        $id = is_string($job) ? $job : (string) $job['id'];
        $interval = (float) ($options['interval'] ?? 2.0);
        $maxInterval = (float) ($options['max_interval'] ?? 10.0);
        $deadline = microtime(true) + (float) ($options['timeout'] ?? 300.0);
        while (true) {
            $data = $this->getJob($id)->data;
            if (isset($options['on_poll'])) {
                ($options['on_poll'])($data);
            }
            $status = $data['status'] ?? null;
            if ($status === 'succeeded') {
                return $data;
            }
            if ($status === 'failed' || $status === 'expired') {
                throw new JobException($data, $status);
            }
            if (microtime(true) + $interval > $deadline) {
                throw new JobException($data, 'timeout');
            }
            ($this->sleeper)($interval);
            $interval = min($maxInterval, $interval * 1.5);
        }
    }

    /**
     * Download a succeeded job's PDF bytes. Pass the job (or its id) so an expired link
     * (`410 link_expired`) can be refreshed by polling once; a bare URL is fetched as is.
     * The download URL is signed, so no API key is sent with it.
     *
     * @param string|array<string, mixed> $source a job, a job id or a download URL
     * @param array{timeout?: int|float, max_retries?: int, headers?: array<string, string>} $options
     */
    public function downloadFile(string|array $source, array $options = []): string
    {
        $job = null;
        if (is_string($source) && !preg_match('#^https?://#i', $source)) {
            $job = $this->getJob($source, $options)->data;
        } elseif (is_array($source)) {
            $job = $source;
        }
        $url = $job !== null ? ($job['download_url'] ?? null) : $source;
        if (!is_string($url) || $url === '') {
            throw new Astro96Exception(sprintf(
                'job %s has no download_url yet (status %s)',
                (string) ($job['id'] ?? '?'),
                (string) ($job['status'] ?? '?'),
            ));
        }
        try {
            return $this->request('GET', $url, null, $options, false, 'bytes')->data;
        } catch (ApiException $e) {
            if ($e->errorCode !== 'link_expired' || $job === null) {
                throw $e;
            }
            $fresh = $this->getJob((string) $job['id'], $options)->data['download_url'] ?? null;
            if (!is_string($fresh) || $fresh === '') {
                throw $e;
            }
            return $this->request('GET', $fresh, null, $options, false, 'bytes')->data;
        }
    }

    /**
     * createPdfJob + waitForJob + downloadFile in one call; returns the PDF bytes.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed>|null $branding
     */
    public function renderPdf(string $capability, array $input, ?array $branding = null, ?string $idempotencyKey = null, float $timeout = 300.0): string
    {
        $opts = $idempotencyKey !== null ? ['idempotency_key' => $idempotencyKey] : [];
        $job = $this->createPdfJob($capability, $input, $branding, null, $opts)->data;
        return $this->downloadFile($this->waitForJob($job, ['timeout' => $timeout]));
    }
}
