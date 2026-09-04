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
}
