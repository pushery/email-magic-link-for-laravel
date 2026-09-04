<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\RequestRefusal;
use EmailMagicLink\Support\ResendDecision;
use Illuminate\Http\Request;

/**
 * Fired when the request endpoint refuses to issue anything: the captcha failed,
 * or the resend guard held the address back (cooldown or rolling cap).
 *
 * Observability only, and server-side only: the HTTP response stays generic and
 * enumeration-resistant. The framework's own `throttle:` limiter answers 429
 * before the controller runs and carries no event; a host that wants that one
 * listens to RequestHandled with status 429.
 *
 * `email` is the normalized address the request carried, which may or may not
 * belong to an account -- that is the point: a flood against unknown addresses
 * is exactly the thing this event makes visible.
 */
final readonly class MagicLinkRequestRefused
{
    public function __construct(
        public RequestRefusal $reason,
        public string $email,
        public ?ResendDecision $decision,
        public Request $request,
    ) {}
}
