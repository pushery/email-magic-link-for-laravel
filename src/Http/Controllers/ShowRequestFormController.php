<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

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
        return $this->views->make('email-magic-link::request', ['mode' => $this->config->mode()]);
    }
}
