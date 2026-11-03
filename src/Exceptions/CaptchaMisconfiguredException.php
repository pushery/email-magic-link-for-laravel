<?php

declare(strict_types=1);

namespace EmailMagicLink\Exceptions;

use RuntimeException;

/**
 * Thrown when the request form is about to render a captcha widget that does not exist.
 *
 * At render time rather than at boot, because the host may register the namespace that holds
 * the view after this package has booted. Rendering the form without the widget instead would
 * leave a form whose every request the captcha guard refuses, with nothing to say why.
 */
final class CaptchaMisconfiguredException extends RuntimeException
{
    public static function viewNotFound(string $view): self
    {
        return new self(
            "email-magic-link.captcha_view is set to [{$view}], and no such view exists. "
            .'Point it at the view that renders your captcha widget, or register the namespace that holds it.',
        );
    }
}
