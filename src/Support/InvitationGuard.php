<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use EmailMagicLink\Contracts\InvitationHandler;
use EmailMagicLink\Exceptions\InvitationsMisconfiguredException;

/**
 * Refuses to boot an installation that has invitations on but cannot serve them.
 *
 * Same shape and the same reasoning as EntropyGuard: the alternative to failing here
 * is failing at the moment an invited person clicks their link, which is the worst
 * time to discover it and the hardest place to see it. Both requirements are things
 * only the host can supply, so neither can be defaulted into working.
 */
final readonly class InvitationGuard
{
    public function __construct(private MagicLinkConfig $config) {}

    public function validate(): void
    {
        if (! $this->config->invitationsEnabled()) {
            return;
        }

        $handler = $this->config->invitationHandler();

        if ($handler === null) {
            throw InvitationsMisconfiguredException::missingHandler();
        }

        // class_exists AND the contract check, in that order: a typo in the class name
        // and a class that simply does not implement the interface are different
        // mistakes, and telling them apart is the difference between a useful message
        // and "something is wrong with your handler".
        if (! class_exists($handler) || ! is_a($handler, InvitationHandler::class, true)) {
            throw InvitationsMisconfiguredException::handlerContract($handler);
        }

        if ($this->config->invitationView() === null) {
            throw InvitationsMisconfiguredException::missingView();
        }
    }
}
