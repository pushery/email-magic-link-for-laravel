<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\InvitationClaimResult;
use EmailMagicLink\Support\IssuedInvitationToken;

/**
 * Issues, stores, and atomically consumes invitation tokens.
 *
 * The raw secret is never persisted: only a keyed hash is stored and indexed.
 * Consumption is a single race-free conditional claim, so two concurrent
 * requests for the same invitation can never both succeed.
 *
 * An invitation is addressed to an EMAIL, not to a user. That is the difference
 * from TokenStore, and it is the reason this contract exists: a sign-in token can
 * only be issued for somebody who already has an account.
 */
interface InvitationStore
{
    /**
     * Issue a fresh invitation and return the plaintext secret.
     *
     * Issuing supersedes any earlier unaccepted invitation for the same email and
     * guard, so re-inviting someone never leaves two working links behind.
     *
     * @param  array<string, mixed>|null  $context  Whatever the inviting side decided in
     *                                              advance. Stored verbatim and handed back
     *                                              on acceptance; never interpreted here.
     * @param  int|null  $ttl  Lifetime in seconds; null uses the configured default.
     */
    public function issue(string $email, string $guard, ?array $context = null, ?string $invitedBy = null, ?int $ttl = null): IssuedInvitationToken;

    /**
     * Look up an invitation by its plaintext token WITHOUT consuming it.
     *
     * Read-only by contract. It is what lets the confirmation page reject a dead
     * invitation before asking the recipient for anything.
     */
    public function peek(string $token): InvitationClaimResult;

    /**
     * Atomically claim an invitation by its plaintext token.
     */
    public function claim(string $token): InvitationClaimResult;

    /**
     * Revoke every unaccepted invitation for the email and guard. Returns the number
     * revoked. An already accepted invitation is left alone -- it is a record of
     * something that happened, not an open door.
     */
    public function revoke(string $email, string $guard): int;

    /**
     * Delete expired invitations, and accepted or revoked ones past the configured
     * retention window. Returns the number removed.
     */
    public function purge(): int;
}
