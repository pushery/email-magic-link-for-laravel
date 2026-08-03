<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Turns an invalid-or-expired magic link / one-time code into an HTTP response
 * for a browser client.
 *
 * Swap the default via the `invalid_response.via` config to a class-string of
 * your own implementation for full control over branding and behavior.
 *
 * Implementations MUST NOT vary the response by *why* the token failed — an
 * unknown token and an expired one must be indistinguishable — so the flow stays
 * enumeration-resistant. The single generic message is passed in already
 * translated; do not re-derive it from the request.
 */
interface InvalidLinkResponder
{
    /**
     * @param  string  $message  The generic, already-translated failure message.
     * @param  string  $failureRoute  The sign-in form route to fall back to (link vs code flow).
     */
    public function respond(Request $request, string $message, string $failureRoute): Response;
}
