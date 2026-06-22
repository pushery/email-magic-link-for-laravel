<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Http\Request;

/**
 * Decides whether a request should receive the JSON token-exchange variant.
 *
 * JSON is returned only when the client asks for it and the API variant is
 * explicitly enabled; otherwise the secure browser flow (redirects and the
 * confirmation interstitial) remains in force.
 */
trait RespondsToApiClients
{
    protected function wantsJson(Request $request): bool
    {
        return $request->expectsJson() && app(MagicLinkConfig::class)->apiEnabled();
    }
}
