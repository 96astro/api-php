<?php

declare(strict_types=1);

namespace Astro96\Api\Exception;

/** A PDF job ended `failed` or `expired`, or did not finish within the wait timeout. */
class JobException extends Astro96Exception
{
    /**
     * @param array<string, mixed> $job
     * @param string $reason "failed", "expired" or "timeout"
     */
    public function __construct(public readonly array $job, public readonly string $reason)
    {
        $id = (string) ($job['id'] ?? '?');
        $err = is_array($job['error'] ?? null) ? $job['error'] : [];
        $detail = '';
        if (!empty($err['code'])) {
            $detail = ': ' . $err['code'] . (!empty($err['message']) ? ' (' . $err['message'] . ')' : '');
        }
        parent::__construct(
            $reason === 'timeout'
                ? sprintf('job %s is still %s', $id, (string) ($job['status'] ?? 'unknown'))
                : sprintf('job %s %s%s', $id, $reason, $detail)
        );
    }
}
