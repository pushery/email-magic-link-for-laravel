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
     */
    public function issue(Authenticatable $user, string $guard, string $channel): IssuedToken;

    /**
     * Atomically claim a magic-link token by its plaintext value.
     */
    public function claimLink(string $token): ClaimResult;

    /**
     * Atomically claim a one-time code for a known user, accounting for failed
     * attempts and enforcing the per-token lockout.
     */
    public function claimCode(Authenticatable $user, string $code): ClaimResult;

    /**
     * Delete expired and consumed tokens. Returns the number removed.
     */
    public function purge(): int;
}
