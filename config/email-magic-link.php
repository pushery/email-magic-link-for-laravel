<?php

declare(strict_types=1);

use EmailMagicLink\Notifications\MagicLinkNotification;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | Turns the magic-link channel on or off independently of whether Fortify
    | is installed. When false, no routes, notifications, or rate limiters are
    | registered: the package becomes inert.
    |
    */

    'enabled' => env('EMAIL_MAGIC_LINK_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Authentication mode
    |--------------------------------------------------------------------------
    |
    | "link" emails a high-entropy magic link, "code" emails a short numeric or
    | alphanumeric one-time code, and "both" offers either. Code mode is bound
    | by the entropy guardrail (see below); link mode passes it trivially.
    |
    | Supported: "link", "code", "both"
    |
    */

    'mode' => env('EMAIL_MAGIC_LINK_MODE', 'link'),

    /*
    |--------------------------------------------------------------------------
    | Token lifetime (seconds)
    |--------------------------------------------------------------------------
    |
    | How long an issued link or code stays valid. Expired tokens are rejected
    | regardless of whether they were ever consumed.
    |
    */

    'ttl' => (int) env('EMAIL_MAGIC_LINK_TTL', 900),

    /*
    |--------------------------------------------------------------------------
    | One-time code (code mode)
    |--------------------------------------------------------------------------
    |
    | The keyspace of a code is (distinct characters) ^ length. Codes are drawn
    | uniformly from the distinct characters of the alphabet, so repeated
    | characters do not add entropy. Together with the per-token attempt lockout
    | this determines brute-force resistance, which the boot-time entropy
    | guardrail enforces. The default alphabet omits visually ambiguous
    | characters (0/O, 1/I/L) for readability.
    |
    */

    'code_length' => 8,

    'code_alphabet' => 'ABCDEFGHJKMNPQRSTUVWXYZ23456789',

    'max_attempts_per_token' => 5,

    /*
    |--------------------------------------------------------------------------
    | Entropy safety factor
    |--------------------------------------------------------------------------
    |
    | The guardrail requires keyspace / max_attempts_per_token >= this value,
    | i.e. at most a 1-in-N chance of guessing a code within the lockout. It
    | cannot be lowered to disable the check; obviously broken combinations
    | (zero ttl, missing attempt cap) always fail closed.
    |
    */

    'entropy_safety_factor' => 1_000_000,

    /*
    |--------------------------------------------------------------------------
    | Guard and user resolution
    |--------------------------------------------------------------------------
    |
    | The stateful guard to log the user into, and how to resolve a user from a
    | submitted email. Leave "guard" null to use the application default; by
    | default users are resolved through that guard's configured provider.
    | Provide a "user_lookup" class implementing the UserLookup contract to fully
    | control resolution (custom columns, multi-tenancy, soft-deletes, and so on).
    |
    | When the Fortify two-factor handoff is enabled, "guard" must resolve to the
    | same provider as "fortify.guard" so Fortify can re-resolve the challenged
    | user from the same table.
    |
    */

    'guard' => env('EMAIL_MAGIC_LINK_GUARD'),

    'user_lookup' => null,

    /*
    |--------------------------------------------------------------------------
    | Token store
    |--------------------------------------------------------------------------
    |
    | The implementation of the TokenStore contract responsible for issuing,
    | hashing, and atomically claiming tokens. Leave null for the bundled
    | Eloquent-backed store.
    |
    */

    'token_store' => null,

    /*
    |--------------------------------------------------------------------------
    | Notification
    |--------------------------------------------------------------------------
    |
    | The notification used to deliver the link or code. Swap it for your own
    | to control branding, channels, and copy.
    |
    */

    'notification' => MagicLinkNotification::class,

    /*
    |--------------------------------------------------------------------------
    | Routing
    |--------------------------------------------------------------------------
    |
    | The browser flow needs the "web" middleware group for sessions and CSRF.
    | "redirect_to" is the fallback destination after a successful login when no
    | intended URL was captured.
    |
    */

    'routes' => [
        'prefix' => '',
        'middleware' => ['web'],
        'redirect_to' => '/',

        // After login, return the user to the URL they originally requested (the
        // protected route that triggered the flow), falling back to "redirect_to"
        // when there is none. Set false to always land on "redirect_to".
        'intended' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | API token exchange
    |--------------------------------------------------------------------------
    |
    | When enabled, first-party SPA and mobile clients may exchange a token via
    | a direct JSON POST without the browser interstitial. The secure default
    | for the browser flow remains the GET confirmation page.
    |
    */

    'api' => [
        'enabled' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | User interface
    |--------------------------------------------------------------------------
    |
    | "mode" selects the look of the bundled sign-in screens:
    |   "auto"   render WireKit (pushery/wirekit) views when it is installed,
    |            otherwise the plain Blade views
    |   "blade"  always the plain Blade views, even when WireKit is installed
    |
    | "vite" lists the Vite entry points the WireKit layout loads, so the host's
    | compiled Tailwind (including WireKit's styles) is present on the page.
    |
    */

    'ui' => [
        'mode' => env('EMAIL_MAGIC_LINK_UI', 'auto'),
        'vite' => ['resources/css/app.css'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Fortify bridge
    |--------------------------------------------------------------------------
    |
    | "mode" controls activation of the optional two-factor handoff:
    |   "auto"  activate only when laravel/fortify is installed
    |   true    same, and warn at boot if Fortify is missing
    |   false   never activate, even when Fortify is installed
    |
    | "respect_two_factor" governs whether a magic-link user with confirmed TOTP
    | is routed through Fortify's challenge. Setting it false is a deliberate
    | security downgrade that disables 2FA for magic-link logins; it emits a
    | boot-time warning.
    |
    */

    'fortify' => [
        'mode' => env('EMAIL_MAGIC_LINK_FORTIFY', 'auto'),
        'respect_two_factor' => true,
        'challenge_route' => 'two-factor.login',
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate limiting
    |--------------------------------------------------------------------------
    |
    | Named limiters applied to the request and consume endpoints. Override them
    | from your application with RateLimiter::for() using the same names. The
    | "limits" defaults below are used by the bundled limiter definitions.
    |
    */

    'limiters' => [
        'request' => 'email-magic-link:request',
        'consume' => 'email-magic-link:consume',
    ],

    'limits' => [
        'request' => ['max' => 5, 'per_minutes' => 1],
        'consume' => ['max' => 10, 'per_minutes' => 1],
    ],

];
