<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

/**
 * Why the resend guard denied an attempt.
 */
enum ResendDenialReason: string
{
    /** The escalating cooldown after a previous send has not elapsed yet. */
    case Cooldown = 'cooldown';

    /** The rolling window already contains the maximum number of sends. */
    case WindowCap = 'window_cap';
}
