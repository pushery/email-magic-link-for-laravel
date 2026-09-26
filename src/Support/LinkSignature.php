<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Why a sign-in link's signature does not hold, or null when it does.
 *
 * Checked by the two link routes themselves rather than by the `signed` middleware. The
 * signature expires together with the token, so the middleware answered the most common
 * failure there is, an expired link, with Laravel's bare 403: before the configured
 * refusal and before the failure event, which is where a host counts such attempts.
 *
 * The reason only travels in the event. The response is the same for both.
 */
final class LinkSignature
{
    public static function failure(Request $request): ?ClaimFailure
    {
        if (URL::hasValidSignature($request)) {
            return null;
        }

        return URL::hasCorrectSignature($request) ? ClaimFailure::Expired : ClaimFailure::NotFound;
    }
}
