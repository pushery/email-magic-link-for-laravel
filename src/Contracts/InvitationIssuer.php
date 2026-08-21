<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\IssuedInvitation;

/**
 * Issues invitations for an email address WITHOUT sending mail.
 *
 * The counterpart to MagicLinkIssuer, and the difference is the addressee: a magic
 * link signs in somebody who already exists, so it is issued for a resolved user.
 * An invitation is issued for an ADDRESS, which may have no account behind it — that
 * is the whole point, and it is why this cannot be a method on the other contract.
 *
 * Deliver the returned URL over any channel you like. The raw token exists only
 * inside that URL; nothing in the package stores or logs it.
 */
interface InvitationIssuer
{
    /**
     * Issue an invitation, superseding any earlier unaccepted one for the same
     * address and guard.
     *
     * @param  string|null  $guard  An allowed guard, or null for the default.
     * @param  array<string, mixed>|null  $context  Whatever was decided in advance —
     *                                              roles, a team, a plan. Stored
     *                                              verbatim, handed back to your
     *                                              handler on acceptance, never
     *                                              interpreted by the package.
     * @param  string|null  $invitedBy  Free-form identifier of whoever invited, for
     *                                  your own audit trail.
     * @param  string|null  $baseUrl  Build the link for this host (e.g. a tenant
     *                                domain) instead of the app's. The signature
     *                                binds to that host, so the link verifies only
     *                                there.
     */
    public function invite(string $email, ?string $guard = null, ?array $context = null, ?string $invitedBy = null, ?string $baseUrl = null): IssuedInvitation;

    /**
     * Revoke every unaccepted invitation for the address and guard. Returns the
     * number revoked; an already accepted invitation is left alone.
     */
    public function revoke(string $email, ?string $guard = null): int;
}
