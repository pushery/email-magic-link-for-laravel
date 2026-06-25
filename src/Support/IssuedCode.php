<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Support\Carbon;

/**
 * A freshly minted one-time code, ready to deliver over any channel.
 *
 * Issuing a new code for the same user and guard invalidates the previous one,
 * so deliver this `code` promptly and keep no stale copies.
 */
final readonly class IssuedCode
{
    public function __construct(
        public string $code,
        public Carbon $expiresAt,
        public int $expiresInMinutes,
    ) {}
}
