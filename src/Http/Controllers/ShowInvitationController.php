<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Events\InvitationRejected;
use EmailMagicLink\Http\Controllers\Concerns\RejectsGenerically;
use EmailMagicLink\Models\Invitation;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Renders the host's acceptance screen for a live invitation, and nothing at all
 * for a dead one.
 *
 * Inert by construction: this GET performs no claim, no write and no sign-in, so a
 * link-following scanner or a browser prefetch cannot burn the invitation before the
 * invited person ever sees it. Only the POST spends it.
 *
 * The order of the three steps is the ticket's fourth promise made structural. The
 * refusal happens BEFORE the host's view is rendered, so a dead invitation can never
 * reach a screen that asks for a password -- and it is a promise rather than consumer
 * discipline only because this package owns the GET.
 *
 * The route deliberately carries no `signed` middleware. That middleware answers an
 * expired signature with 403 and an unknown token with the generic page, and those two
 * answers are distinguishable. With a seven-day lifetime expiry is the ordinary case,
 * so the signature is checked here instead and a failure folded into the same refusal
 * as everything else.
 */
final readonly class ShowInvitationController
{
    use RejectsGenerically;

    public function __construct(
        private MagicLinkConfig $config,
        private Factory $views,
        private InvitationStore $store,
    ) {}

    public function __invoke(Request $request, string $token): Response|View
    {
        // A tampered or expired signature is NotFound as far as the visitor is
        // concerned: telling those apart is telling them whether the token was ever real.
        if (! URL::hasValidSignature($request)) {
            return $this->refuse($request, ClaimFailure::NotFound);
        }

        $result = $this->store->peek($token);

        if (! $result->successful || ! $result->invitation instanceof Invitation) {
            return $this->refuse($request, $result->failure ?? ClaimFailure::NotFound);
        }

        $view = $this->config->invitationView();

        if ($view === null) {
            return $this->refuse($request, ClaimFailure::NotFound);
        }

        return $this->views->make($view, [
            'token' => $token,
            'action' => route('email-magic-link.invitation.accept', ['token' => $token]),
            'email' => $result->invitation->email,
            'context' => $result->invitation->context,
            'expiresAt' => $result->invitation->expires_at,
        ]);
    }

    private function refuse(Request $request, ClaimFailure $reason): Response
    {
        // The reason reaches the application through the event and stops there. It is
        // never in the response, never in the status code, and the caller passes it in
        // rather than the refusal looking it up again -- a second lookup would both cost
        // a query and be wrong for a bad signature, where there is nothing to look up.
        event(new InvitationRejected($reason, $request));

        return $this->genericRejection(
            $request,
            (string) __('email-magic-link::messages.invitation_failed'),
            'email-magic-link.request.form',
        );
    }
}
