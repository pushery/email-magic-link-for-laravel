<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Why a sign-in REQUEST was refused before anything was issued.
 *
 * The consume side reports its failures through ClaimFailure; the request side
 * had no counterpart, so a captcha failure or a held-back resend produced a
 * response and no signal a host could alert on.
 */
enum RequestRefusal: string
{
    case Captcha = 'captcha';
    case ResendCooldown = 'resend_cooldown';
    case ResendWindowCap = 'resend_window_cap';

    public static function fromResendDenial(ResendDenialReason $reason): self
    {
        return match ($reason) {
            ResendDenialReason::Cooldown => self::ResendCooldown,
            ResendDenialReason::WindowCap => self::ResendWindowCap,
        };
    }
}
