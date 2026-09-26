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
 *
 * One check cannot run at boot: whether the configured view resolves, because the
 * host may register the namespace that holds it after this package has booted. That
 * one is thrown when the acceptance screen is about to render, and it names the key
 * to fix rather than only the view that was not found.
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

    public static function viewNotFound(string $view): self
    {
        return new self(
            "email-magic-link.invitations.view is set to [{$view}], and no such view exists. "
            .'Point it at your acceptance screen, or register the namespace that holds it.',
        );
    }

    public static function handlerNotFound(string $class): self
    {
        return new self(
            "email-magic-link.invitations.handler is set to [{$class}], and no such class exists. "
            .'Check the class name, and that Composer can autoload it.',
        );
    }

    public static function handlerContract(string $class): self
    {
        return new self(
            "[{$class}] must implement [".InvitationHandler::class.'] to be used as '
            .'email-magic-link.invitations.handler.',
        );
    }

    public static function handlerUserNotInGuard(string $guard): self
    {
        return new self(
            "The invitation handler returned a user that the [{$guard}] guard's user provider does "
            .'not resolve to. A session keeps only the identifier and resolves it through that '
            .'provider, so the invited person would become whichever of its accounts carries the '
            .'same id. Return a user of the invitation\'s guard from accept(); '
            .'AcceptedInvitation::$guard names it.',
        );
    }
}
