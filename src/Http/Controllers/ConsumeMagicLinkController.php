<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Http\Controllers\Concerns\CompletesMagicLinkLogin;
use EmailMagicLink\Models\MagicLinkToken;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumes a magic-link token — the only side-effecting step of the link flow.
 *
 * The token is verified and atomically claimed here; only this POST mutates
 * state. On success the swappable authenticator decides login versus 2FA.
 */
final class ConsumeMagicLinkController
{
    use CompletesMagicLinkLogin;

    public function __invoke(Request $request, string $token, TokenStore $store): Response
    {
        $result = $store->claimLink($token);
        $claimed = $result->token;

        if (! $result->successful || ! $claimed instanceof MagicLinkToken) {
            return $this->failedConsumption($request, 'email-magic-link.request.form');
        }

        return $this->completeLogin($request, $claimed, 'email-magic-link.request.form');
    }
}
