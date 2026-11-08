<?php

declare(strict_types=1);

namespace EmailMagicLink\Guards;

use EmailMagicLink\Contracts\GuardResolver;
use EmailMagicLink\Support\MagicLinkConfig;
use Illuminate\Http\Request;

/**
 * The default: the guard the request names when the allowlist holds it, otherwise the default.
 *
 * An unknown guard falls back without a word, so the request endpoint answers a guessed guard
 * the way it answers a listed one.
 */
final readonly class AllowlistGuardResolver implements GuardResolver
{
    public function __construct(private MagicLinkConfig $config) {}

    public function guardFor(Request $request, ?string $requested): string
    {
        return $this->config->resolveGuard($requested);
    }
}
