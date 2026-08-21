<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use RuntimeException;

/**
 * Thrown when an invitation is issued while the channel is switched off.
 *
 * Loud rather than silent: minting a token nobody can redeem would look like it
 * worked, and the person holding the dead link is the one who finds out.
 */
final class InvitationsDisabledException extends RuntimeException
{
    public static function make(): self
    {
        return new self(
            'Invitations are disabled. Set email-magic-link.invitations.enabled to true, '
            .'and configure invitations.handler and invitations.view.',
        );
    }
}
