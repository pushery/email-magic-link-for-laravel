<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps the package's URLs out of the Referer header that other sites receive.
 *
 * The confirmation page and the invitation page live at a URL whose path is the credential, and
 * a page loads things: `ui.styles` may name a stylesheet on a CDN, and a host's layout or view
 * may link elsewhere. A browser sends the page's own URL along wherever its referrer policy
 * allows it, which `unsafe-url` does always and `no-referrer-when-downgrade` does on every HTTPS
 * request. The default, `strict-origin-when-cross-origin`, sends only the origin, but the policy
 * is the application's to change. `same-origin` sends nothing to another origin and leaves the
 * header in place for the application itself, where `redirect()->back()` reads it.
 */
final class SameOriginReferrer
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
