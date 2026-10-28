<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

use Astro96\Api\Exception\ApiConnectionException;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\GuzzleException;

/**
 * The default transport when guzzlehttp/guzzle is installed (Laravel ships it). Unlike a bare PSR-18
 * client it honours the per-attempt timeout. Pass your own Guzzle client to add proxies or middleware.
 */
final class GuzzleTransport implements Transport
{
    private readonly ClientInterface $guzzle;

    public function __construct(?ClientInterface $guzzle = null)
    {
        $this->guzzle = $guzzle ?? new GuzzleClient();
    }

    public static function available(): bool
    {
        return interface_exists(ClientInterface::class) && class_exists(GuzzleClient::class);
    }

    public function send(HttpRequest $request): HttpResponse
    {
        try {
            $res = $this->guzzle->request($request->method, $request->url, [
                'headers' => $request->headers,
                'body' => $request->body,
                'timeout' => $request->timeout,
                'connect_timeout' => min($request->timeout, 30.0),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
        } catch (ConnectException $e) {
            $ctx = $e->getHandlerContext();
            $timedOut = ($ctx['errno'] ?? null) === 28 || stripos($e->getMessage(), 'timed out') !== false;
            throw new ApiConnectionException(
                $timedOut ? sprintf('request timed out after %s s', $request->timeout) : 'connection failed: ' . $e->getMessage(),
                $timedOut,
                $e,
            );
        } catch (GuzzleException $e) {
            throw new ApiConnectionException('connection failed: ' . $e->getMessage(), false, $e);
        }
        return new HttpResponse($res->getStatusCode(), $res->getHeaders(), (string) $res->getBody(), $res->getReasonPhrase());
    }
}
