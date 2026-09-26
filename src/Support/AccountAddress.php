<?php

declare(strict_types=1);

namespace EmailMagicLink\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The address an account answers to, as the account itself states it.
 *
 * A link or code is a credential for one account, so it goes to that account's own
 * address and never to the string somebody typed. The two are only as equal as the
 * database finds them: MySQL's default collations ignore accents, so the typed
 * `victim@exämple.com` matches the account stored as `victim@example.com` -- and a
 * mailer handed the typed string delivers to `victim@xn--exmple-cua.com`, a different
 * domain that anyone can register.
 */
final class AccountAddress
{
    /**
     * The account's `email` attribute, which is the column a lookup by address matched.
     *
     * @return non-empty-string|null
     */
    public static function of(Authenticatable $user): ?string
    {
        $email = data_get($user, 'email');

        return is_string($email) && $email !== '' ? $email : null;
    }

    /**
     * Where the account's mail goes: its own mail route when it declares one, the same
     * route Laravel's notifications use, and its `email` attribute otherwise.
     *
     * @return non-empty-string|non-empty-array<array-key, mixed>|null
     */
    public static function mailRoute(Authenticatable $user): string|array|null
    {
        if (method_exists($user, 'routeNotificationFor')) {
            $route = $user->routeNotificationFor('mail');

            if ((is_string($route) && $route !== '') || (is_array($route) && $route !== [])) {
                return $route;
            }
        }

        return self::of($user);
    }
}
