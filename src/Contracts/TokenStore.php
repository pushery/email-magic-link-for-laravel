<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\ClaimResult;
use EmailMagicLink\Support\IssuedToken;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Issues, stores, and atomically consumes magic-link tokens and one-time codes.
 *
 * The raw secret is never persisted: only a keyed hash is stored and indexed.
 * Consumption is a single race-free conditional claim, so two concurrent
 * requests for the same token can never both succeed.
 */
interface TokenStore
{
    /**
     * Issue a fresh token for the user and return the plaintext secret.
     *
     * @param  'link'|'code'  $channel
     * @param  int|null  $maxUses  Redemptions allowed for a link; null uses the
     *                             configured default. Ignored for codes (always 1).
     * @param  string|null  $passphrase  An optional shared secret that must be
     *                                   entered on the confirmation page before a
     *                                   link is consumed. Link-only; not 2FA.
     */
    public function issue(Authenticatable $user, string $guard, string $channel, ?int $maxUses = null, ?string $passphrase = null): IssuedToken;

    /**
     * Atomically claim a magic-link token by its plaintext value.
     *
     * When the link carries a passphrase, it is verified before the token is
     * spent, so a wrong passphrase never consumes a use of a multi-use link.
     */
    public function claimLink(string $token, ?string $passphrase = null): ClaimResult;

    /**
     * Whether the given magic-link token is gated by a passphrase, so the
     * confirmation page can prompt for it. Read-only; consumes nothing.
     */
    public function requiresPassphrase(string $token): bool;

    /**
     * Atomically claim a one-time code for a known user on a specific guard,
     * accounting for failed attempts and enforcing the per-token lockout.
     */
    public function claimCode(Authenticatable $user, string $code, string $guard): ClaimResult;

    /**
     * Delete expired and consumed tokens. Returns the number removed.
     */
    public function purge(): int;
}
