<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

use Astro96\Api\Exception\ApiConnectionException;
use Astro96\Api\Exception\Astro96Exception;

/**
 * Zero-dependency transport on ext-curl: the fallback when neither Guzzle nor WordPress is present,
 * so the SDK works on shared hosting and in plugins that cannot pull in an HTTP stack.
 */
final class CurlTransport implements Transport
{
    /**
     * @param array<int, mixed> $curlOptions extra CURLOPT_* options (proxy, CA bundle, ...)
     */
    public function __construct(private readonly array $curlOptions = [])
    {
        if (!self::available()) {
            throw new Astro96Exception('ext-curl is not loaded: install guzzlehttp/guzzle or pass a PSR-18 client.');
        }
    }

    public static function available(): bool
    {
        return function_exists('curl_init');
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $headers = [];
        $reason = '';
        $lines = [];
        foreach ($request->headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
        }
        $ch = curl_init($request->url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $request->method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => (int) ceil($request->timeout * 1000),
            CURLOPT_CONNECTTIMEOUT_MS => (int) ceil(min($request->timeout, 30.0) * 1000),
            CURLOPT_NOSIGNAL => true,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($ch, string $line) use (&$headers, &$reason): int {
                $trim = trim($line);
                if (preg_match('#^HTTP/\S+\s+\d{3}\s*(.*)$#', $trim, $m)) {
                    $headers = []; // a new status line (100 Continue): start over
                    $reason = $m[1];
                } elseif (str_contains($trim, ':')) {
                    [$k, $v] = explode(':', $trim, 2);
                    $k = strtolower(trim($k));
                    $headers[$k] = isset($headers[$k]) ? $headers[$k] . ', ' . trim($v) : trim($v);
                }
                return strlen($line);
            },
        ];
        if ($request->body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $request->body;
        } elseif ($request->method === 'HEAD') {
            $opts[CURLOPT_NOBODY] = true;
        }
        curl_setopt_array($ch, $this->curlOptions + $opts);
        $body = curl_exec($ch);
        if ($body === false) {
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);
            $timedOut = $errno === CURLE_OPERATION_TIMEDOUT;
            throw new ApiConnectionException(
                $timedOut ? sprintf('request timed out after %s s', $request->timeout) : "connection failed: {$error}",
                $timedOut,
            );
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return new HttpResponse($status, $headers, (string) $body, $reason);
    }
}
