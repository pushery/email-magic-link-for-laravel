<?php

declare(strict_types=1);

use EmailMagicLink\Http\Controllers\AcceptInvitationController;
use EmailMagicLink\Http\Controllers\ConfirmMagicLinkController;
use EmailMagicLink\Http\Controllers\ConsumeCodeController;
use EmailMagicLink\Http\Controllers\ConsumeMagicLinkController;
use EmailMagicLink\Http\Controllers\ResendCountdownScriptController;
use EmailMagicLink\Http\Controllers\SendMagicLinkController;
use EmailMagicLink\Http\Controllers\ShowCodeFormController;
use EmailMagicLink\Http\Controllers\ShowInvitationController;
use EmailMagicLink\Http\Controllers\ShowRequestFormController;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Support\Facades\Route;

// Resolved through MagicLinkConfig, not a raw `(string) config(...)`: config()
// returns mixed, and casting mixed to string is an error waiting for the first
// host that sets a limiter to an array or null — it would throw during route
// registration, i.e. on every request, with a message pointing at this file
// rather than at their config. MagicLinkConfig::string() narrows and falls back
// to the documented default instead, and it is the same resolution every other
// caller in the package already uses, so the two cannot drift.
$config = app(MagicLinkConfig::class);
$requestLimiter = $config->requestLimiter();
$consumeLimiter = $config->consumeLimiter();
$invitationViewLimiter = $config->invitationViewLimiter();

// All routes are registered whenever the channel is enabled; the configured
// mode governs which one actually issues a token, not which routes exist.
Route::get('magic-link', ShowRequestFormController::class)
    ->name('email-magic-link.request.form');

Route::post('magic-link', SendMagicLinkController::class)
    ->middleware("throttle:{$requestLimiter}")
    ->name('email-magic-link.request');

// GET is signed and inert; only the POST consumes the token.
Route::get('magic-link/verify/{token}', ConfirmMagicLinkController::class)
    ->middleware('signed')
    ->name('email-magic-link.confirm');

// The POST is signed too, and that is the arm that matters. The token is the whole
// credential, so a consume step that accepts a bare token undoes what signing the GET
// bought: an application that answers a forged `Host` mails a link pointing at the
// attacker, the victim opens it, and the attacker replays the bare token here. Both
// routes share this URI, so the signature the GET arrived with verifies the POST
// unchanged -- the confirmation form simply posts back to the URL it was reached at.
Route::post('magic-link/verify/{token}', ConsumeMagicLinkController::class)
    ->middleware(["throttle:{$consumeLimiter}", 'signed'])
    ->name('email-magic-link.consume');

Route::get('magic-link/code', ShowCodeFormController::class)
    ->name('email-magic-link.code.form');

// The resend countdown's client script, served as a same-origin file so a strict
// `script-src 'self'` accepts it with no nonce and no host action at all. Inline, it
// was unreachable for a host whose policy issues no nonces: there was nothing to pass
// through. Unthrottled and unsigned like the two form routes -- it carries no
// credential, spends nothing, and its body is a constant. The URL carries a digest of
// that constant, which is what makes the immutable cache header honest.
Route::get('magic-link/resend-countdown.js', ResendCountdownScriptController::class)
    ->name('email-magic-link.resend-countdown-script');

Route::post('magic-link/code', ConsumeCodeController::class)
    ->middleware("throttle:{$consumeLimiter}")
    ->name('email-magic-link.code.consume');

// Invitations are a separate channel and register only when the host turns them on:
// both routes are useless without an acceptance view and a handler, and the boot guard
// has already refused to start if those are missing.
if ($config->invitationsEnabled()) {
    // Deliberately NOT behind `signed`. That middleware answers an expired signature with
    // 403 and an unknown token with the generic page, and those two answers are
    // distinguishable -- at a seven-day lifetime, expiry is the ordinary case. The
    // signature is verified inside the controller and folded into the one refusal.
    //
    // Inert like the sign-in confirmation: only the POST spends the invitation, so a
    // scanner following the link cannot burn it.
    //
    // It is nevertheless the one GET in the package that carries a limiter, and that
    // is deliberate rather than left over: unlike the sign-in confirmation it is not
    // behind `signed`, so an unauthenticated caller can address it with any token it
    // likes and the answer says whether that token exists.
    //
    // Its OWN limiter, though, not the consume one. Sharing that budget would mean
    // looking at an invitation spends the allowance accepting it needs -- and behind
    // one egress address, everyone else's sign-in too.
    Route::get('magic-link/invitation/{token}', ShowInvitationController::class)
        ->middleware("throttle:{$invitationViewLimiter}")
        ->name('email-magic-link.invitation.show');

    Route::post('magic-link/invitation/{token}', AcceptInvitationController::class)
        ->middleware("throttle:{$consumeLimiter}")
        ->name('email-magic-link.invitation.accept');
}
