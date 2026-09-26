<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\CaptchaGuard;
use EmailMagicLink\Contracts\ResendGuard;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Events\MagicLinkRequested;
use EmailMagicLink\Events\MagicLinkRequestRefused;
use EmailMagicLink\Http\Controllers\Concerns\RespondsToApiClients;
use EmailMagicLink\Http\Requests\SendMagicLinkRequest;
use EmailMagicLink\Notifications\MagicLinkNotification;
use EmailMagicLink\Support\AccountAddress;
use EmailMagicLink\Support\ConfirmationUrl;
use EmailMagicLink\Support\IssuanceLock;
use EmailMagicLink\Support\IssuedToken;
use EmailMagicLink\Support\MagicLinkConfig;
use EmailMagicLink\Support\RequestRefusal;
use EmailMagicLink\Support\ResendDecision;
use EmailMagicLink\Support\ResendDenialReason;
use EmailMagicLink\Support\ResendKey;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Notification as NotificationSender;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Issues a magic link or code for a submitted email.
 *
 * Enumeration-resistant: the response is identical whether or not the
 * email belongs to a user. A token is issued and a queued notification
 * dispatched only when a user is found, but the caller can never observe that.
 *
 * The user is resolved through the guard's own lookup, so this flow trusts the
 * resolved user as belonging to the guard. The public Mint-API
 * (MagicLinkIssuer), which accepts an arbitrary user, performs the stricter
 * provider re-resolution instead.
 */
final class SendMagicLinkController
{
    use RespondsToApiClients;

    public function __invoke(
        SendMagicLinkRequest $request,
        UserLookup $lookup,
        TokenStore $store,
        MagicLinkConfig $config,
        CaptchaGuard $captcha,
        ResendGuard $resendGuard,
        IssuanceLock $lock,
    ): Response {
        // Pre-issue challenge, before any user lookup, so it gates the request
        // without ever depending on whether the account exists.
        if (! $captcha->passes($request)) {
            return $this->captchaFailed($request);
        }

        $email = $request->email();

        // Escalating cooldown + rolling cap, keyed on the submitted email alone
        // (never on whether it resolves to a user), so it stays enumeration-safe.
        //
        // `resend.enabled` is checked HERE, not inside the guard. It is the switch
        // for THIS endpoint, which is what its name and documentation promise. Inside
        // the guard it would disarm every key, including the host application's own:
        // the contract invites hosts to inject the guard for their own mail
        // endpoints, so disabling magic-link throttling would silently disable, say,
        // a second-factor flood guard as well.
        if ($config->resendEnabled()) {
            $decision = $resendGuard->attempt(ResendKey::forRequest($email));

            if (! $decision->allowed) {
                return $this->resendThrottled($request, $decision);
            }
        }

        $channel = $this->resolveChannel($request->requestedChannel(), $config->mode());
        $guard = $config->resolveGuard($request->requestedGuard());

        // A code is issued under a lock, and the lock is taken only for an address that
        // resolves, so a store that cannot lock would fail only for known addresses -- a
        // 500 against the redirect. Asked here, before the lookup, it fails the same way
        // for every address.
        if ($channel === 'code') {
            $lock->assertUsable();
        }

        $user = $lookup->findByEmail($email, $guard);

        // The credential goes to the address the account states, never to the string
        // that was typed: a database that folds accents resolves `victim@exämple.com` to
        // the account stored as `victim@example.com`, and the typed form is a mailbox on
        // a look-alike domain. An account that states no address gets nothing, the same
        // as an unknown one.
        $route = $user instanceof Authenticatable ? AccountAddress::mailRoute($user) : null;

        if ($user instanceof Authenticatable && $route !== null) {
            try {
                // NOT $store->issue(...) directly, and the wrapper is the whole fix for
                // the timing half of the enumeration hole the catch below closes for the
                // status half.
                //
                // The lock is taken only for an address that RESOLVES, so every millisecond
                // spent queueing for it is an answer to "does this account exist" -- and the
                // attacker produces the contention himself by sending two requests at once.
                // Waiting out the budget instead, measured: 827 ms for a known contended
                // address against 12 ms for an unknown one, on a one-second budget.
                //
                // Waiting was never buying this response anything: the request holding the
                // lock is the one sending the credential, so the answer below is already
                // true. The programmatic issuers keep the configured budget -- see
                // IssuanceLock::withoutWaiting() for why the two paths differ.
                $issued = $lock->withoutWaiting(fn (): IssuedToken => $store->issue($user, $guard, $channel));

                // The notification is queued, and a worker renders it under ITS locale unless
                // the request's travels with it. The mail route is an anonymous notifiable
                // with no locale preference of its own, so this is the only place the
                // request's language can be captured.
                NotificationSender::route('mail', $route)
                    ->notify($this->buildNotification($issued, $channel, $config)->locale(app()->getLocale()));

                $this->dispatchUniformly(new MagicLinkRequested($user, $channel, $request));
            } catch (LockTimeoutException) {
                // A concurrent request for this same address is mid-issuance and outlasted
                // the wait budget. Falling through to the ordinary response is not
                // swallowing the problem: that other request IS sending the credential, so
                // "we have sent you a link if the address exists" stays true.
                //
                // Letting it surface instead would cost twice. It is a 500 on a path that
                // works, and -- worse -- it is a 500 that only ever happens for an address
                // that RESOLVES TO A USER, on an endpoint whose whole design is to answer
                // identically either way. The lock would have handed an enumeration oracle
                // to anyone willing to send two requests at once.
                //
                // The event is how a host sees it at all, since the response cannot say.
                $this->dispatchUniformly(new MagicLinkRequestRefused(RequestRefusal::IssuanceContended, $email, null, $request));
            }
        }

        // Echo back the raw requested guard (not the resolved one) so the redirect
        // shape is identical for allowed and unknown guards — guards stay
        // un-enumerable. resolveGuard() re-validates it on consume.
        return $this->sentResponse($request, $channel, $email, $request->requestedGuard());
    }

    /**
     * Dispatch an event of the branch only a resolved address reaches.
     *
     * A host listener that throws here would turn this branch, and only this one, into a
     * 500, and the difference between a 500 and the ordinary redirect is whether the
     * account exists. The exception is reported, so nobody loses it; the answer stays the
     * one every address gets.
     */
    private function dispatchUniformly(object $event): void
    {
        try {
            event($event);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function captchaFailed(SendMagicLinkRequest $request): Response
    {
        event(new MagicLinkRequestRefused(RequestRefusal::Captcha, $request->email(), null, $request));

        $message = __('email-magic-link::messages.captcha_failed');

        if ($this->wantsJson($request)) {
            return $this->apiError($message, 'captcha_failed', 422);
        }

        return redirect()->route('email-magic-link.request.form')
            ->withErrors(['email' => $message])
            ->withInput($request->only('email'));
    }

    private function resendThrottled(SendMagicLinkRequest $request, ResendDecision $decision): Response
    {
        if ($decision->reason instanceof ResendDenialReason) {
            event(new MagicLinkRequestRefused(RequestRefusal::fromResendDenial($decision->reason), $request->email(), $decision, $request));
        }

        $seconds = $decision->retryAfterSeconds;
        // trans_choice, not __(): the string is pipe-form so "1 second" reads correctly
        // on the last second of the wait, in every bundled language.
        $message = trans_choice('email-magic-link::messages.resend_throttled', $seconds, ['seconds' => $seconds]);

        if ($this->wantsJson($request)) {
            return $this->apiError($message, 'resend_throttled', 429)
                ->header('Retry-After', (string) $seconds);
        }

        // Flash the remaining seconds so the request form can disable the button
        // and count down, and carry the same Retry-After header the fixed-window
        // throttle already sets, for parity with the rest of the flow.
        return redirect()->route('email-magic-link.request.form')
            ->withErrors(['email' => $message])
            ->with('resend_retry_after', $seconds)
            ->withInput($request->only('email'))
            ->header('Retry-After', (string) $seconds);
    }

    /**
     * @param  'link'|'code'|'both'  $mode
     * @return 'link'|'code'
     */
    private function resolveChannel(?string $requested, string $mode): string
    {
        return match ($mode) {
            'code' => 'code',
            'both' => $requested === 'code' ? 'code' : 'link',
            default => 'link',
        };
    }

    /**
     * @param  'link'|'code'  $channel
     */
    private function buildNotification(IssuedToken $issued, string $channel, MagicLinkConfig $config): MagicLinkNotification
    {
        $minutes = (int) ceil($config->ttlFor($channel) / 60);

        $actionUrl = $channel === 'link'
            ? ConfirmationUrl::for($issued->record, $issued->plaintext)
            : null;

        $notification = $config->notification();

        return new $notification(
            $channel,
            $actionUrl,
            $channel === 'code' ? $issued->plaintext : null,
            $minutes,
            $channel === 'link' ? $issued->record->uses_remaining : 1,
        );
    }

    /**
     * @param  'link'|'code'  $channel
     */
    private function sentResponse(SendMagicLinkRequest $request, string $channel, string $email, ?string $guard): Response
    {
        $message = $channel === 'code'
            ? __('email-magic-link::messages.status_code_sent')
            : __('email-magic-link::messages.status_link_sent');

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'channel' => $channel]);
        }

        if ($channel === 'code') {
            // The address rides in the session, never in the URL: a query string
            // lands in access logs, proxy logs, browser history and the Referer of
            // every asset the code screen loads. Same handoff the retry path uses.
            return redirect()->route('email-magic-link.code.form')
                ->with('status', $message)
                ->withInput(array_filter(['email' => $email, 'guard' => $guard], fn (?string $value): bool => $value !== null));
        }

        return redirect()->route('email-magic-link.request.form')->with('status', $message);
    }
}
