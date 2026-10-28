<?php

declare(strict_types=1);

namespace Astro96\Api\Tests;

use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Http\HttpRequest;
use Astro96\Api\Http\HttpResponse;
use Astro96\Api\Http\Transport;
use PHPUnit\Framework\Assert;

/** Replays scripted responses and records every request (the PHP twin of httpx.MockTransport in the Python tests). */
final class FakeTransport implements Transport
{
    /** @var list<HttpRequest> */
    public array $calls = [];

    /** @param list<HttpResponse|\Throwable|callable(HttpRequest): HttpResponse> $replies */
    public function __construct(private array $replies)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->calls[] = $request;
        if ($this->replies === []) {
            Assert::fail("unexpected call {$request->method} {$request->url}");
        }
        $next = array_shift($this->replies);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        return $next instanceof HttpResponse ? $next : $next($request);
    }

    /** @return mixed the decoded JSON body of call $i */
    public function body(int $i): mixed
    {
        $b = $this->calls[$i]->body;
        return $b === null ? null : json_decode($b, true);
    }

    public static function ok(mixed $data, array $meta = [], array $headers = [], int $status = 200): HttpResponse
    {
        $meta = $meta ?: ['request_id' => 'req_1', 'api_version' => 'v1', 'credits_used' => 0, 'warnings' => []];
        return new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode(['data' => $data, 'meta' => $meta]));
    }

    public static function err(int $status, string $code, array $headers = [], array $extra = []): HttpResponse
    {
        $body = ['error' => $extra + ['code' => $code, 'message' => "msg {$code}", 'field' => null, 'request_id' => 'req_err', 'retryable' => false]];
        return new HttpResponse($status, $headers + ['Content-Type' => 'application/json'], json_encode($body));
    }

    public static function down(bool $timedOut = false): ApiConnectionException
    {
        return new ApiConnectionException($timedOut ? 'request timed out after 1 s' : 'connection failed: refused', $timedOut);
    }
}
