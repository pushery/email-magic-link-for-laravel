<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use RuntimeException;

/**
 * Thrown when a link or code is requested while the channel is disabled.
 *
 * With `email-magic-link.enabled = false` no routes, rate limiters, or boot-time
 * entropy checks are registered, so an issued credential could never be consumed.
 * The issuer fails loud instead of minting a credential into a dead end.
 */
final class MagicLinkDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self('The email-magic-link channel is disabled (email-magic-link.enabled = false); enable it before issuing links or codes.');
    }
}
