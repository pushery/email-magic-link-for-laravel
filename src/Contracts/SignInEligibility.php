<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Decides whether an account may be handed a session right now.
 *
 * Suspended, dormant, deleted-in-grace, never-verified: the application owns
 * what those words mean, and the package owns only the question. The default
 * binding allows everybody, so an install that says nothing behaves exactly as
 * it did before this contract existed.
 *
 * The answer is consulted at BOTH ends of the flow, and the second one is the
 * half that is easy to miss. Gating only issuance leaves a window: a link handed
 * out a minute before an account was suspended is still a valid credential
 * afterwards, and it opens a session. So the check runs again on redemption,
 * against a token that was legitimately issued.
 *
 * Invitation acceptance sits outside this gate on purpose. It runs through the
 * host's own InvitationHandler, which is the thing that decides what an account
 * becomes, and asking whether that account may sign in during the request that
 * puts it into service would be asking the host to contradict itself. Said here
 * rather than left for a reader to infer from the absence of a call.
 *
 * Implementations must not decide from the request, only from the account. The
 * issuing endpoint answers identically for an unknown address and a refused one
 * -- that is the whole reason this is a predicate over an Authenticatable rather
 * than a middleware -- and a decision that depended on the request could not
 * keep that promise on the redemption path, where there is no such request.
 */
interface SignInEligibility
{
    /**
     * Whether this account may sign in to this guard at this moment.
     *
     * Returning false is a refusal, never an error: the caller renders the same
     * response it renders for an address it has never seen. A host that needs to
     * see refusals should listen for MagicLinkConsumptionFailed, which carries
     * ClaimFailure::Ineligible, rather than logging from inside this method --
     * the issuing path deliberately calls it for addresses that do not exist.
     */
    public function allows(Authenticatable $user, string $guard): bool;
}
