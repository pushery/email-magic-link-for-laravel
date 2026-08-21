<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Contracts\InvalidLinkResponder;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one refusal every dead token gets, whatever killed it.
 *
 * Unknown, expired, already spent, revoked, tampered signature -- all of them land
 * here and come back byte for byte identical. Anything more specific would answer
 * "was this ever a real link", which is the question the uniformity exists to leave
 * unanswered.
 *
 * Extracted from CompletesMagicLinkLogin so the sign-in flow and the invitation flow
 * share ONE response path. They used to have one; the moment there were two, they
 * could drift, and drift between two refusals is exactly what makes them tell apart.
 */
trait RejectsGenerically
{
    use RespondsToApiClients;

    protected function genericRejection(Request $request, string $message, string $failureRoute): Response
    {
        $config = app(MagicLinkConfig::class);

        // A client that negotiated JSON always gets the stable envelope; its
        // shape is a fixed contract independent of the browser strategy below.
        if ($this->wantsJson($request)) {
            return $this->apiError($message, $config->invalidResponseErrorCode(), 422);
        }

        // The browser response is host-configurable (view/redirect/abort/json,
        // or a custom class bound in the service provider) but never varies by
        // the failure reason, so it stays enumeration-resistant.
        return app(InvalidLinkResponder::class)->respond($request, $message, $failureRoute);
    }
}
