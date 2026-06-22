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
    case LockedOut;
}
