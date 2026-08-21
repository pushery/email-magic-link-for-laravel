<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Carbon\CarbonInterface;

/**
 * A freshly issued invitation, ready to deliver over any channel.
 *
 * Deliver `url` verbatim. Unlike IssuedCode there is deliberately no plaintext
 * property: a code has to be shown to the person typing it, whereas an invitation
 * token is only ever useful inside its URL. Not exposing it separately means there
 * is no second copy for a log line or an exception dump to pick up.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public string $url,
        public string $email,
        public string $guard,
        public CarbonInterface $expiresAt,
        public int $expiresInMinutes,
    ) {}
}
