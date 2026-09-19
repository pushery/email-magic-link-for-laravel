<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Why an attempt to claim a token did not succeed.
 *
 * Deliberately coarse: the HTTP layer collapses every failure into one generic
 * message so a caller can never distinguish "wrong code" from "no such token".
 */
enum ClaimFailure
{
    case NotFound;
    case Expired;
    case AlreadyConsumed;
    case InvalidCode;
    case InvalidPassphrase;
    case LockedOut;

    /**
     * An invitation that was withdrawn before anybody used it -- through revoke(), or
     * by a newer invitation for the same address and guard superseding it.
     * Distinct from AlreadyConsumed on purpose: a click on a revoked link is the
     * one refusal that is a signal rather than noise, and a host that alerts on
     * it must be able to tell it from a re-click on an accepted one.
     */
    case Revoked;

    /**
     * The token was genuine and the account exists, but the application says that
     * account may not sign in -- suspended, dormant, deleted-in-grace.
     * Distinct from NotFound for the same reason Revoked is distinct from
     * AlreadyConsumed: a refused account holding a valid link is a signal a host
     * will want to alert on, and it is indistinguishable from ordinary noise if
     * it arrives under the name of a token that was never issued. The HTTP
     * response stays identical either way.
     */
    case Ineligible;
}
