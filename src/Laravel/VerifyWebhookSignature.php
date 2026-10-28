<?php

declare(strict_types=1);

namespace Astro96\Api\Laravel;

use Astro96\Api\Exception\WebhookSignatureException;
use Astro96\Api\Webhook;
use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Route middleware for your 96Astro webhook endpoint. Answers 400 to an unsigned or stale request;
 * otherwise puts the verified event on the request as the `astro96_event` attribute.
 *
 * ```php
 * Route::post('/webhooks/96astro', HandleAstroWebhook::class)
 *     ->middleware(\Astro96\Api\Laravel\VerifyWebhookSignature::class)
 *     ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);
 * ```
 */
class VerifyWebhookSignature
{
    public function __construct(private readonly Repository $config)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) $this->config->get('astro96.webhook_secret', '');
        $secrets = array_values(array_filter(array_map('trim', explode(',', $secret))));
        if ($secrets === []) {
            return new Response('ASTRO_WEBHOOK_SECRET is not set', 500, ['Content-Type' => 'text/plain']);
        }
        try {
            $event = Webhook::verify(
                $request->getContent(),
                $request->headers->all(),
                $secrets,
                (int) $this->config->get('astro96.webhook_tolerance', Webhook::DEFAULT_TOLERANCE),
            );
        } catch (WebhookSignatureException $e) {
            return new Response($e->getMessage(), 400, ['Content-Type' => 'text/plain']);
        }
        $request->attributes->set('astro96_event', $event);
        return $next($request);
    }
}
