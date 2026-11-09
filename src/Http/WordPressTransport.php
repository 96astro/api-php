<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

use Astro96\Api\Exception\ApiConnectionException;

/**
 * Sends through the WordPress HTTP API (wp_remote_request), so the site's proxy settings, CA bundle
 * and `http_request_args` filters apply. Chosen automatically when the SDK runs inside WordPress.
 */
final class WordPressTransport implements Transport
{
    public static function available(): bool
    {
        return function_exists('wp_remote_request');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $args = [
            'method' => $request->method,
            'headers' => $request->headers,
            'timeout' => $request->timeout,
            'redirection' => 0,
            'httpversion' => '1.1',
        ];
        if ($request->body !== null) {
            $args['body'] = $request->body;
        }
        $res = \wp_remote_request($request->url, $args);
        if (\is_wp_error($res)) {
            $message = $res->get_error_message();
            $timedOut = stripos($message, 'timed out') !== false || stripos($message, 'cURL error 28') !== false;
            throw new ApiConnectionException(
                $timedOut ? sprintf('request timed out after %s s', $request->timeout) : "connection failed: {$message}",
                $timedOut,
            );
        }
        $raw = $res['headers'] ?? [];
        if (is_object($raw) && method_exists($raw, 'getAll')) {
            $raw = $raw->getAll();
        }
        $headers = [];
        foreach ((is_iterable($raw) ? $raw : []) as $k => $v) {
            $headers[(string) $k] = $v;
        }
        return new HttpResponse(
            (int) ($res['response']['code'] ?? 0),
            $headers,
            (string) ($res['body'] ?? ''),
            (string) ($res['response']['message'] ?? ''),
        );
    }
}
