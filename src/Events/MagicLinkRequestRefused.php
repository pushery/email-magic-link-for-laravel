<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\LeavesTheRequestBehind;
use EmailMagicLink\Support\RequestRefusal;
use EmailMagicLink\Support\ResendDecision;
use Illuminate\Http\Request;

/**
 * Fired when the request endpoint does not issue for the address a request named. The
 * reason says why:
 *
 * - `Captcha`: the captcha failed.
 * - `ResendCooldown`, `ResendWindowCap`: the resend guard held the address back.
 * - `IssuanceContended`: a concurrent request for the same address was still issuing
 *   past the wait budget. That request sends the credential, so nothing is lost.
 *
 * Observability only, and server-side only: the HTTP response stays generic and
 * enumeration-resistant. The framework's own `throttle:` limiter answers 429
 * before the controller runs and carries no event; a host that wants that one
 * listens to RequestHandled with status 429.
 *
 * `email` is the normalized address the request carried. For the captcha and resend
 * reasons it may or may not belong to an account -- that is the point: a flood against
 * unknown addresses is exactly the thing they make visible. `IssuanceContended` fires
 * only for an address that resolves to an account, so a host that writes it anywhere
 * others can read records which addresses exist.
 */
final readonly class MagicLinkRequestRefused
{
    use LeavesTheRequestBehind;

    public function __construct(
        public RequestRefusal $reason,
        public string $email,
        public ?ResendDecision $decision,
        public ?Request $request,
    ) {}
}
