<?php

declare(strict_types=1);

namespace EmailMagicLink\Contracts;

use Illuminate\Http\Request;

/**
 * Decides whether a request may proceed to issue a link or code.
 *
 * Runs before any user lookup, so an implementation must depend only on the
 * request itself — a CAPTCHA token, a challenge header, a custom rule — and never
 * on whether an account exists, which would turn it into an enumeration oracle.
 */
interface CaptchaGuard
{
    public function passes(Request $request): bool;
}
