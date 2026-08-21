<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\AcceptedInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * An invitation was spent and the handler ran to completion.
 *
 * Fired AFTER the transaction commits, so a listener can rely on the acceptance
 * having actually happened -- and so a slow listener cannot hold a database
 * transaction open. `$user` is whatever the handler returned: null means the
 * invitation was accepted without signing anybody in.
 */
final readonly class InvitationAccepted
{
    public function __construct(
        public AcceptedInvitation $invitation,
        public ?Authenticatable $user,
        public Request $request,
    ) {}
}
