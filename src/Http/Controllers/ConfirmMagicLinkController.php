<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Renders the inert confirmation interstitial for a magic link.
 *
 * This GET endpoint performs no consumption, no authentication, and no state
 * change. It only renders a page whose CSRF-protected form POSTs to the consume
 * route, so email security scanners and browser prefetch cannot burn the
 * single-use token by merely following the link.
 *
 * The form action is the URL this request arrived at, signature and all, rather than
 * a bare `route()`. Both routes share the URI, so the same signature verifies the
 * POST -- which is what binds spending the token to the host the link was minted for.
 * A bare action would have left the consume step open to a replayed token.
 */
final readonly class ConfirmMagicLinkController
{
    public function __construct(
        private MagicLinkConfig $config,
        private Factory $views,
        private TokenStore $store,
    ) {}

    public function __invoke(Request $request, string $token): View
    {
        return $this->views->make($this->config->view('confirm'), [
            'token' => $token,
            'action' => $request->fullUrl(),
            'requiresPassphrase' => $this->store->requiresPassphrase($token),
        ]);
    }
}
