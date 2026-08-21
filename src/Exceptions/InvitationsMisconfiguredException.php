<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use EmailMagicLink\Contracts\InvitationHandler;
use RuntimeException;

/**
 * Thrown at boot when invitations are on but cannot work.
 *
 * At boot rather than at request time, and that is the whole point: the alternative
 * is a 500 at the moment an invited person clicks their link, which is both the
 * worst time to find out and the hardest place to see it.
 */
final class InvitationsMisconfiguredException extends RuntimeException
{
    public static function missingHandler(): self
    {
        return new self(
            'Invitations are enabled but email-magic-link.invitations.handler is not set. '
            .'Point it at a class implementing '.InvitationHandler::class.'; it decides what '
            .'accepting an invitation does in your application.',
        );
    }

    public static function missingView(): self
    {
        return new self(
            'Invitations are enabled but email-magic-link.invitations.view is not set. '
            .'Point it at your acceptance screen; the package ships none, because one '
            .'carrying a password field would put credential handling inside a package '
            .'that handles none.',
        );
    }

    public static function handlerContract(string $class): self
    {
        return new self(
            "[{$class}] must implement [".InvitationHandler::class.'] to be used as '
            .'email-magic-link.invitations.handler.',
        );
    }
}
