<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Renders the form where a user enters the one-time code (code mode).
 */
final readonly class ShowCodeFormController
{
    public function __construct(
        private MagicLinkConfig $config,
        private Factory $views,
    ) {}

    public function __invoke(Request $request): View
    {
        $email = $request->query('email');
        $guard = $request->query('guard');

        return $this->views->make($this->config->view('code'), [
            'email' => is_string($email) ? $email : '',
            'guard' => is_string($guard) ? $guard : '',
        ]);
    }
}
