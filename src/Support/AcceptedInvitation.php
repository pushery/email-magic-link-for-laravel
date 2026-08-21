<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * The invitation that was just spent, as your handler sees it.
 *
 * A flat value rather than the Eloquent model on purpose: the handler is a domain
 * seam, not a place to edit the package's rows. Everything it needs to act is here,
 * and nothing it could use to change the invitation's own state is.
 */
final readonly class AcceptedInvitation
{
    /**
     * @param  array<string, mixed>|null  $context  What the inviter decided in advance.
     *                                              Possibly a week old — check it against
     *                                              current state before acting on it.
     */
    public function __construct(
        public int $id,
        public string $email,
        public string $guard,
        public ?array $context,
        public ?string $invitedBy,
    ) {}
}
