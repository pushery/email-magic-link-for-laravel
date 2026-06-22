<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers\Concerns;

use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Http\JsonResponse;
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

    /**
     * Build the standard JSON error envelope: a human `message` and a stable,
     * machine-readable `error` code a client can branch on without parsing prose.
     */
    protected function apiError(string $message, string $error, int $status): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'error' => $error], $status);
    }
}
