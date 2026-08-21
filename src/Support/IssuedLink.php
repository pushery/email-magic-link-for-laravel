<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Carbon\CarbonInterface;

/**
 * A freshly minted magic link, ready to deliver over any channel.
 *
 * The URL points at the inert, signed confirmation page (a GET that changes
 * nothing); the single-use token is spent only when the user submits that page.
 * Deliver `url` verbatim — never the consume endpoint, and never prefetch it.
 */
final readonly class IssuedLink
{
    public function __construct(
        public string $url,
        public CarbonInterface $expiresAt,
        public int $expiresInMinutes,
    ) {}
}
