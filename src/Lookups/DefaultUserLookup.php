<?php

declare(strict_types=1);

namespace EmailMagicLink\Lookups;

use EmailMagicLink\Contracts\UserLookup;
use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;

/**
 * Resolves a user through the guard's configured user provider.
 *
 * Uses retrieveByCredentials so the lookup honours the application's provider
 * (Eloquent or database), model, and connection. A miss returns null with the
 * same single-query shape as a hit, keeping the request endpoint
 * enumeration-resistant.
 */
final readonly class DefaultUserLookup implements UserLookup
{
    public function __construct(
        private AuthManager $auth,
        private Repository $config,
    ) {}

    public function findByEmail(string $email, string $guard): ?Authenticatable
    {
        $provider = $this->config->get("auth.guards.{$guard}.provider");
        $provider = is_string($provider) && $provider !== '' ? $provider : null;

        return $this->auth->createUserProvider($provider)
            ?->retrieveByCredentials(['email' => $email]);
    }
}
