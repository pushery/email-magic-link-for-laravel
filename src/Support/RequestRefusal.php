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

    /**
     * A concurrent issuance for the same address held the lock past the wait budget.
     *
     * The response is the endpoint's ordinary one, so this is the only way a host can see
     * it happening at all -- and it is worth seeing: sustained contention on one address
     * is either a user hammering the button or somebody hammering it for them.
     */
    case IssuanceContended = 'issuance_contended';

    public static function fromResendDenial(ResendDenialReason $reason): self
    {
        return match ($reason) {
            ResendDenialReason::Cooldown => self::ResendCooldown,
            ResendDenialReason::WindowCap => self::ResendWindowCap,
        };
    }
}
