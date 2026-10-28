<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 402 `payment_required`: the plan's payment is overdue past its grace period. */
class PaymentRequiredException extends ApiException
{
}
