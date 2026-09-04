<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\AcceptedInvitation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Http\Request;

/**
 * An invitation was spent and the handler ran to completion.
 *
 * Fired AFTER the transaction commits, so a listener can rely on the acceptance
 * having actually happened -- and so a slow listener cannot hold a database
 * transaction open. The dispatch site already sits outside the package's own
 * transaction; ShouldDispatchAfterCommit makes the promise hold when the HOST
 * wrapped the request in one (a transactional middleware, a test), which call
 * order alone could not. `$user` is whatever the handler returned: null means
 * the invitation was accepted without signing anybody in.
 */
final readonly class InvitationAccepted implements ShouldDispatchAfterCommit
{
    public function __construct(
        public AcceptedInvitation $invitation,
        public ?Authenticatable $user,
        public Request $request,
    ) {}
}
