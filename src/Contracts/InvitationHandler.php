<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use EmailMagicLink\Support\AcceptedInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * What accepting an invitation MEANS in your application.
 *
 * This is the one seam the invitation flow has, and the package deliberately owns
 * nothing behind it. Setting a password, creating the account, making somebody a
 * member, granting the roles the inviter chose — all of that is your domain. A
 * package that did it for you would have to know your user model, your password
 * policy and your membership rules, and it would stop being an authentication
 * building block.
 *
 * Return an authenticatable and the package signs that user in through the same
 * path a magic link uses — including the Fortify two-factor handoff, if you have
 * it. Return null and the invitation is accepted without a session, which is what
 * you want when acceptance still has to be approved by somebody else.
 *
 * Runs INSIDE the transaction that spends the token: throw, and the acceptance is
 * rolled back with it, so a half-created account cannot outlive a spent invitation.
 * Keep the work here short for the same reason.
 *
 * The `context` you passed to the issuer comes back untouched. Treat it as a
 * statement about the past, not the present: an invitation can be a week old, and
 * whatever it names — a role, a team, a plan — may have changed or disappeared in
 * the meantime. Validate it against current state before acting on it.
 */
interface InvitationHandler
{
    public function accept(AcceptedInvitation $invitation, Request $request): ?Authenticatable;
}
