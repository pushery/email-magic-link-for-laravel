<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Renders the inert confirmation interstitial for a magic link.
 *
 * This GET endpoint performs no consumption, no authentication, and no state
 * change. It only renders a page whose CSRF-protected form POSTs to the consume
 * route, so email security scanners and browser prefetch cannot burn the
 * single-use token by merely following the link.
 */
final readonly class ConfirmMagicLinkController
{
    public function __construct(private Factory $views) {}

    public function __invoke(string $token): View
    {
        return $this->views->make('email-magic-link::confirm', [
            'token' => $token,
            'action' => route('email-magic-link.consume', ['token' => $token]),
        ]);
    }
}
