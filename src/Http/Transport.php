<?php

declare(strict_types=1);

namespace Astro96\Api\Http;

use Astro96\Api\Exception\ApiConnectionException;

/**
 * Sends one HTTP attempt. Retries, authentication and error mapping happen in the Client, so a
 * transport only moves bytes: it returns every HTTP status (including 4xx/5xx) as an HttpResponse,
 * never follows redirects, and throws ApiConnectionException when no response arrived.
 */
interface Transport
{
    /** @throws ApiConnectionException */
    public function send(HttpRequest $request): HttpResponse;
}
