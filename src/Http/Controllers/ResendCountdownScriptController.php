<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Support\ResendCountdownScript;
use Illuminate\Http\Response;

/**
 * Serves the resend countdown's client script as a same-origin file.
 *
 * A route rather than a published asset: a package that publishes assets is a
 * package whose script is missing on every host that never ran the publish command,
 * and the whole point of moving this out of the page is to make it work WITHOUT the
 * host doing anything. There is no build step and no `dist/` -- see
 * `ResendCountdownScript` for why the source sits in a constant.
 *
 * The response is cacheable forever because the URL carries a digest of the bytes:
 * change the script and the URL changes with it.
 */
final class ResendCountdownScriptController
{
    public function __invoke(): Response
    {
        return new Response(ResendCountdownScript::contents(), 200, [
            'Content-Type' => 'application/javascript; charset=utf-8',
            // PRIVATE, not public, and that is a correctness point rather than a
            // conservative default. This route runs inside the host's route middleware
            // group -- `web` by default -- so the response leaves with a session cookie
            // and an XSRF cookie attached, exactly like every other page of the package.
            // `public` invites a shared cache to store it, and a shared cache that stores
            // a response WITH its Set-Cookie headers can hand one visitor's session
            // cookie to the next. Conformant caches strip it; "conformant" is not a thing
            // an authentication package gets to assume about somebody else's proxy.
            //
            // `private` costs nothing here: the browser cache is where the benefit
            // actually is, since the script is fetched once per visitor and the URL is
            // versioned by content digest, so `immutable` still holds.
            'Cache-Control' => 'private, max-age=31536000, immutable',
            // The body is JavaScript and says so; without this a browser that
            // sniffs could decide otherwise for a response it fetched as a script.
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
