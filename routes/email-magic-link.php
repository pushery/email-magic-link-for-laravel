<?php

declare(strict_types=1);

use EmailMagicLink\Http\Controllers\AcceptInvitationController;
use EmailMagicLink\Http\Controllers\ConfirmMagicLinkController;
use EmailMagicLink\Http\Controllers\ConsumeCodeController;
use EmailMagicLink\Http\Controllers\ConsumeMagicLinkController;
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

Route::post('magic-link/verify/{token}', ConsumeMagicLinkController::class)
    ->middleware("throttle:{$consumeLimiter}")
    ->name('email-magic-link.consume');

Route::get('magic-link/code', ShowCodeFormController::class)
    ->name('email-magic-link.code.form');

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
    Route::get('magic-link/invitation/{token}', ShowInvitationController::class)
        ->middleware("throttle:{$consumeLimiter}")
        ->name('email-magic-link.invitation.show');

    Route::post('magic-link/invitation/{token}', AcceptInvitationController::class)
        ->middleware("throttle:{$consumeLimiter}")
        ->name('email-magic-link.invitation.accept');
}
