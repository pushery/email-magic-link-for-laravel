<?php

declare(strict_types=1);

namespace EmailMagicLink\Http\Controllers;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Renders the form where a user enters the one-time code (code mode).
 */
final readonly class ShowCodeFormController
{
    public function __construct(private Factory $views) {}

    public function __invoke(Request $request): View
    {
        $email = $request->query('email');

        return $this->views->make('email-magic-link::code', [
            'email' => is_string($email) ? $email : '',
        ]);
    }
}
