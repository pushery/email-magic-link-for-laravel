<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\ResendDecision;

/**
 * An escalating cooldown plus a rolling send cap for mail-sending endpoints.
 *
 * Every allowed attempt arms a per-key cooldown that grows with each further
 * send (base × factor, up to a ceiling) and counts toward a rolling window cap
 * (by default five sends per hour). Both limits answer with the seconds until
 * the next send is allowed, so callers can render a countdown instead of a
 * bare error.
 *
 * The package applies the guard to its own request endpoint, keyed per email.
 * Host applications may consume the same service for their own mail-sending
 * endpoints (a "resend code" button on a custom challenge, for example) by
 * injecting this contract and choosing their own keys. Prefix your keys with a
 * namespace of your own (for example "two-factor:{user id}") — the package
 * keys its request endpoint with "request:{email}", and keys are shared per
 * store.
 */
interface ResendGuard
{
    /**
     * Gate a send attempt: when allowed, the attempt is recorded (the ladder
     * climbs one step and the send counts toward the rolling window); when
     * denied, nothing is recorded and the decision carries the seconds until
     * the next attempt would pass.
     */
    public function attempt(string $key): ResendDecision;

    /**
     * Answer what attempt() would decide right now without recording anything.
     * Use it to render a countdown or disable a button before the user tries.
     */
    public function peek(string $key): ResendDecision;

    /**
     * Clear all recorded state for the key — the cooldown ladder and the
     * rolling window start over. The package calls this once a token issued
     * for the address is successfully verified; call it yourself after your
     * own flow completes.
     */
    public function reset(string $key): void;
}
