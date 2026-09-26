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

    /**
     * The master switch is off while invitations themselves are on. Telling the host to set
     * invitations.enabled would name a key that is already true.
     */
    public static function channelOff(): self
    {
        return new self(
            'Invitations are disabled because the whole email-magic-link channel is '
            .'(email-magic-link.enabled = false). Enable it before issuing invitations.',
        );
    }
}
