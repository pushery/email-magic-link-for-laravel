<?php

declare(strict_types=1);

namespace EmailMagicLink\Captcha;

use EmailMagicLink\Contracts\CaptchaGuard;
use Illuminate\Http\Request;

/**
 * The default guard: no challenge, every request passes.
 *
 * Bind your own CaptchaGuard via the `captcha` config to verify hCaptcha,
 * Cloudflare Turnstile, reCAPTCHA, or any pre-issue challenge before a link or
 * code is sent.
 */
final class NullCaptchaGuard implements CaptchaGuard
{
    public function passes(Request $request): bool
    {
        return true;
    }
}
