<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use RuntimeException;

/**
 * Thrown at boot when the magic-link configuration would be brute-forceable.
 *
 * The message always names the offending configuration keys and the minimum
 * value that would satisfy the guardrail, so the adopter can fix it without
 * understanding the underlying entropy math.
 */
final class InsecureMagicLinkConfigurationException extends RuntimeException {}
