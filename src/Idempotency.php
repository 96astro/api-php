<?php

declare(strict_types=1);

namespace Astro96\Api;

final class Idempotency
{
    /**
     * A new idempotency key (a random UUID v4). Generate one per logical operation and reuse it only to
     * retry that same operation: the API then replays the stored result and charges once.
     */
    public static function key(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
