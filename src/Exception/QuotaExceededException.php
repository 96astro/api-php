<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 429 `quota_exhausted`, `spend_cap_reached` or `project_budget_exceeded`: retrying will not help. */
class QuotaExceededException extends ApiException
{
}
