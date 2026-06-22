<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Contracts\CaptchaGuard;
use EmailMagicLink\Contracts\TokenStore;
use EmailMagicLink\Contracts\UserLookup;
use EmailMagicLink\Events\MagicLinkRequested;
use EmailMagicLink\Http\Controllers\Concerns\RespondsToApiClients;
use EmailMagicLink\Http\Requests\SendMagicLinkRequest;
use EmailMagicLink\Notifications\MagicLinkNotification;
use EmailMagicLink\Support\IssuedToken;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification as NotificationSender;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Issues a magic link or code for a submitted email.
 *
 * Enumeration-resistant: the response is identical whether or not the
 * email belongs to a user. A token is issued and a queued notification
 * dispatched only when a user is found, but the caller can never observe that.
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
    ): Response {
        // Pre-issue challenge, before any user lookup, so it gates the request
        // without ever depending on whether the account exists.
        if (! $captcha->passes($request)) {
            return $this->captchaFailed($request);
        }

        $email = $request->email();
        $channel = $this->resolveChannel($request->requestedChannel(), $config->mode());
        $guard = $config->resolveGuard($request->requestedGuard());

        $user = $lookup->findByEmail($email, $guard);

        if ($user instanceof Authenticatable) {
            $issued = $store->issue($user, $guard, $channel);

            NotificationSender::route('mail', $email)
                ->notify($this->buildNotification($issued, $channel, $config));

            event(new MagicLinkRequested($user, $channel, $request));
        }

        // Echo back the raw requested guard (not the resolved one) so the redirect
        // shape is identical for allowed and unknown guards — guards stay
        // un-enumerable. resolveGuard() re-validates it on consume.
        return $this->sentResponse($request, $channel, $email, $request->requestedGuard());
    }

    private function captchaFailed(SendMagicLinkRequest $request): Response
    {
        $message = 'The verification challenge failed. Please try again.';

        if ($this->wantsJson($request)) {
            return $this->apiError($message, 'captcha_failed', 422);
        }

        return redirect()->route('email-magic-link.request.form')
            ->withErrors(['email' => $message])
            ->withInput($request->only('email'));
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
            ? URL::temporarySignedRoute(
                'email-magic-link.confirm',
                $issued->record->expires_at,
                ['token' => $issued->plaintext],
            )
            : null;

        $notification = $config->notification();

        return new $notification(
            $channel,
            $actionUrl,
            $channel === 'code' ? $issued->plaintext : null,
            $minutes,
        );
    }

    /**
     * @param  'link'|'code'  $channel
     */
    private function sentResponse(SendMagicLinkRequest $request, string $channel, string $email, ?string $guard): Response
    {
        $message = $channel === 'code'
            ? 'If an account matches that email, we have sent a sign-in code.'
            : 'If an account matches that email, we have sent a sign-in link.';

        if ($this->wantsJson($request)) {
            return response()->json(['message' => $message, 'channel' => $channel]);
        }

        if ($channel === 'code') {
            $params = ['email' => $email];

            if ($guard !== null) {
                $params['guard'] = $guard;
            }

            return redirect()->route('email-magic-link.code.form', $params)->with('status', $message);
        }

        return redirect()->route('email-magic-link.request.form')->with('status', $message);
    }
}
