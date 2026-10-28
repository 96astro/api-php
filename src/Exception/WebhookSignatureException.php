<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** The webhook request is not signed by 96Astro, or is too old to accept. Answer it with 400. */
class WebhookSignatureException extends Astro96Exception
{
}
