<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when the given user does not belong to the target guard's provider.
 *
 * A magic link is consumed by re-resolving the user from the guard's provider,
 * so issuing for a user that provider cannot return — or that resolves to a
 * different model — would mint a dead or misdirected credential. The issuer
 * re-resolves at issuance and fails loud on a mismatch.
 */
final class UserNotInGuardException extends InvalidArgumentException
{
    public static function for(string $guard): self
    {
        return new self(sprintf(
            "The given user does not belong to the [%s] guard's user provider.",
            $guard,
        ));
    }
}
