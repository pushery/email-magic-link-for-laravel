<?php

declare(strict_types=1);

namespace EmailMagicLink\Events;

use EmailMagicLink\Support\LeavesTheRequestBehind;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Fired when the request endpoint issues a link or code for a resolved user. The Mint API
 * (issueLink(), issueCode()) does not fire it: a host that audits issuance records those
 * credentials where it mints them.
 *
 * Observability only: never dispatched for an unknown email, so it must not be
 * used to decide whether an account exists in a response.
 */
final readonly class MagicLinkRequested
{
    use LeavesTheRequestBehind;

    public function __construct(
        public Authenticatable $user,
        public string $channel,
        public ?Request $request,
    ) {}
}
