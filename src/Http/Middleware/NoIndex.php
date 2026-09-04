<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps every response of the package's route group out of search indexes.
 *
 * The bundled layouts carry a robots meta tag, but four responses at these URLs never
 * pass through them: the host's invitation acceptance view, a host-supplied invalid
 * view, a custom InvalidLinkResponder, and the countdown script. The acceptance page
 * is the one that shows the invited address at a URL carrying the token. A header
 * covers what the body cannot, and a robots.txt cannot stand in: a disallowed URL is
 * still indexed from an external reference, and the disallow hides the meta tag.
 */
final class NoIndex
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
