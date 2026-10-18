<?php

declare(strict_types=1);

namespace EmailMagicLink\Notifications;

use EmailMagicLink\Contracts\MailIdentity;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The default: the application's own name, and the bundled greeting.
 *
 * The name is `app.name`, or the host of `app.url` where that is blank, the same for every account, which
 * is what the mail said before the contract existed.
 */
final readonly class DefaultMailIdentity implements MailIdentity
{
    public function __construct(private MagicLinkConfig $config) {}

    public function applicationName(Authenticatable $user): string
    {
        return $this->config->applicationName();
    }

    public function greeting(Authenticatable $user): ?string
    {
        return null;
    }
}
