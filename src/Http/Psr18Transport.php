<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

use Astro96\Api\Exception\ApiConnectionException;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Any PSR-18 client (Symfony HttpClient's Psr18Client, php-http adapters, Buzz, ...) with PSR-17 factories.
 *
 * PSR-18 has no per-request timeout: configure the timeout on the client you pass in. The `timeout`
 * client option still bounds Retry-After waits but cannot interrupt a request.
 */
final class Psr18Transport implements Transport
{
    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $psr = $this->requestFactory->createRequest($request->method, $request->url);
        foreach ($request->headers as $name => $value) {
            $psr = $psr->withHeader($name, $value);
        }
        if ($request->body !== null) {
            $psr = $psr->withBody($this->streamFactory->createStream($request->body));
        }
        try {
            $res = $this->client->sendRequest($psr);
        } catch (NetworkExceptionInterface $e) {
            $timedOut = stripos($e->getMessage(), 'timed out') !== false || stripos($e->getMessage(), 'timeout') !== false;
            throw new ApiConnectionException('connection failed: ' . $e->getMessage(), $timedOut, $e);
        } catch (ClientExceptionInterface $e) {
            throw new ApiConnectionException('request failed: ' . $e->getMessage(), false, $e);
        }
        return new HttpResponse($res->getStatusCode(), $res->getHeaders(), (string) $res->getBody(), $res->getReasonPhrase());
    }
}
