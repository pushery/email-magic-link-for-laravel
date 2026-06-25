<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a link or code is requested for a guard that is not allowed.
 *
 * Only the default guard and those listed under `email-magic-link.guards` may be
 * used. The message names the offending guard and the full allowlist so the
 * caller can correct it without inspecting config.
 */
final class UnknownGuardException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $allowed
     */
    public static function for(string $guard, array $allowed): self
    {
        return new self(sprintf(
            'The [%s] guard is not allowed for magic-link issuance. Allowed guards: [%s].',
            $guard,
            implode(', ', $allowed),
        ));
    }
}
