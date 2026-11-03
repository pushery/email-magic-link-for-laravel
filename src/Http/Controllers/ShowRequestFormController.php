<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Exceptions\CaptchaMisconfiguredException;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Renders the "enter your email" form that starts the flow.
 */
final readonly class ShowRequestFormController
{
    public function __construct(
        private MagicLinkConfig $config,
        private Factory $views,
    ) {}

    public function __invoke(): View
    {
        // The form includes the host's captcha widget by name, and only the view factory can say
        // whether that name resolves. Asked here, so a typo names the key to fix instead of
        // surfacing as a view the form could not include.
        $captchaView = $this->config->captchaView();

        if ($captchaView !== null && ! $this->views->exists($captchaView)) {
            throw CaptchaMisconfiguredException::viewNotFound($captchaView);
        }

        return $this->views->make($this->config->view('request'), ['mode' => $this->config->mode()]);
    }
}
