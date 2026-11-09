<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** 410: `link_expired` (poll the job for a fresh link) or `file_expired` (create a new PDF job). */
class GoneException extends ApiException
{
}
