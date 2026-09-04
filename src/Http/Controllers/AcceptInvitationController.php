<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\InvitationHandler;
use EmailMagicLink\Contracts\InvitationStore;
use EmailMagicLink\Contracts\MagicLinkAuthenticator;
use EmailMagicLink\Events\InvitationAccepted;
use EmailMagicLink\Events\InvitationRejected;
use EmailMagicLink\Http\Controllers\Concerns\RejectsGenerically;
use EmailMagicLink\Models\Invitation;
use EmailMagicLink\Support\AcceptedInvitation;
use EmailMagicLink\Support\ClaimFailure;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Spends the invitation and hands the decision to the host.
 *
 * The only state-changing step of the flow, and the only one behind CSRF.
 *
 * The claim and the host's handler run in ONE transaction. That is what makes a
 * thrown handler safe: a validation failure on a too-short password, or a unique
 * constraint losing a race, rolls the acceptance back with it, and the invited
 * person's link still works. The alternative -- spend first, create after -- turns
 * every handler failure into a burnt invitation and a support request.
 *
 * Signing in happens AFTER the commit and goes through the same authenticator
 * singleton a magic link uses, which the Fortify bridge decorates. So an invited
 * person who already has confirmed two-factor lands in Fortify's challenge instead
 * of being quietly signed in -- which the package gets for free precisely because
 * the host does not call Auth::login() itself.
 *
 * The signature is verified HERE rather than by the `signed` middleware, for the same
 * reason the GET checks it itself: that middleware answers a bad signature with 403
 * and an unknown token with the generic page, and telling those apart tells a caller
 * whether the token was ever real. Checking it here folds both into one refusal.
 */
final readonly class AcceptInvitationController
{
    use RejectsGenerically;

    public function __construct(
        private MagicLinkConfig $config,
        private InvitationStore $store,
    ) {}

    public function __invoke(Request $request, string $token): Response
    {
        // Before anything is spent: the token is the whole credential, so accepting a
        // bare one would undo the host binding the signature exists for. An application
        // that answers a forged `Host` mails the invitation to the attacker's origin;
        // without this the attacker replays the token here and the invitation is gone.
        if (! URL::hasValidSignature($request)) {
            return $this->refuse($request, ClaimFailure::NotFound);
        }

        // The closure returns EITHER a failure OR the pair, rather than writing into
        // captured variables and reporting success separately. With by-reference captures
        // nothing can know the pair was set, which forced a third branch that no input can
        // reach -- and an unreachable branch is not a hole to test, it is a shape to fix.
        /** @var ClaimFailure|array{0: AcceptedInvitation, 1: ?Authenticatable} $outcome */
        $outcome = (new Invitation)->getConnection()->transaction(function () use ($token, $request): ClaimFailure|array {
            $result = $this->store->claim($token);

            if (! $result->successful || ! $result->invitation instanceof Invitation) {
                return $result->failure ?? ClaimFailure::NotFound;
            }

            $accepted = new AcceptedInvitation(
                $result->invitation->id,
                $result->invitation->email,
                $result->invitation->guard,
                $result->invitation->context,
                $result->invitation->invited_by,
            );

            return [$accepted, app(InvitationHandler::class)->accept($accepted, $request)];
        });

        if ($outcome instanceof ClaimFailure) {
            return $this->refuse($request, $outcome);
        }

        [$accepted, $user] = $outcome;

        event(new InvitationAccepted($accepted, $user, $request));

        if (! $user instanceof Authenticatable) {
            // The handler accepted without producing a session -- which is what you want
            // when acceptance still needs somebody else's approval.
            return redirect()->to($this->config->invitationRedirectTo());
        }

        return app(MagicLinkAuthenticator::class)->authenticate($request, $user, $accepted->guard, false);
    }

    private function refuse(Request $request, ClaimFailure $reason): Response
    {
        event(new InvitationRejected($reason, $request));

        return $this->genericRejection(
            $request,
            (string) __('email-magic-link::messages.invitation_failed'),
            'email-magic-link.request.form',
        );
    }
}
