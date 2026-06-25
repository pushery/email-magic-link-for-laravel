<?php

declare(strict_types=1);

namespace EmailMagicLink\Facades;

use EmailMagicLink\Contracts\MagicLinkIssuer;
use EmailMagicLink\Support\IssuedCode;
use EmailMagicLink\Support\IssuedLink;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Facade;
use Override;

/**
 * Mint magic links and one-time codes without sending mail.
 *
 * @method static IssuedLink issueLink(Authenticatable $user, ?string $guard = null)
 * @method static IssuedCode issueCode(Authenticatable $user, ?string $guard = null)
 *
 * @see MagicLinkIssuer
 */
final class EmailMagicLink extends Facade
{
    #[Override]
    protected static function getFacadeAccessor(): string
    {
        return MagicLinkIssuer::class;
    }
}
