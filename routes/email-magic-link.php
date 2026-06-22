<?php

declare(strict_types=1);

use EmailMagicLink\Http\Controllers\ConfirmMagicLinkController;
use EmailMagicLink\Http\Controllers\ConsumeCodeController;
use EmailMagicLink\Http\Controllers\ConsumeMagicLinkController;
use EmailMagicLink\Http\Controllers\SendMagicLinkController;
use EmailMagicLink\Http\Controllers\ShowCodeFormController;
use EmailMagicLink\Http\Controllers\ShowRequestFormController;
use Illuminate\Support\Facades\Route;

$requestLimiter = (string) config('email-magic-link.limiters.request', 'email-magic-link:request');
$consumeLimiter = (string) config('email-magic-link.limiters.consume', 'email-magic-link:consume');

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
