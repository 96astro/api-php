<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 403: `account_suspended`, `plan_upgrade_required` (the Free plan has no AI or PDF on live keys). */
class PermissionDeniedException extends ApiException
{
}
