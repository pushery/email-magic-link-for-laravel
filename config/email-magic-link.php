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
    | regardless of whether they were ever consumed. "ttl" is the default for
    | both channels; set "link_ttl" or "code_ttl" to a positive number of seconds
    | to give a channel its own lifetime (for example a shorter code that is typed
    | by hand). A null or non-positive override inherits "ttl".
    |
    */

    'ttl' => (int) env('EMAIL_MAGIC_LINK_TTL', 900),

    'link_ttl' => env('EMAIL_MAGIC_LINK_LINK_TTL'),

    'code_ttl' => env('EMAIL_MAGIC_LINK_CODE_TTL'),

    /*
    |--------------------------------------------------------------------------
    | Multi-use links
    |--------------------------------------------------------------------------
    |
    | How many times a magic link may be redeemed before it is spent. The default
    | of 1 keeps every link single-use — the secure default for a login link.
    | Raise it, or pass a per-link override to the Mint API's issueLink(), to hand
    | out a link that may be redeemed a bounded number of times (a shared or
    | multi-device invite). The count is decremented atomically on each claim, so
    | concurrent redemptions can never exceed the limit. Codes are always
    | single-use regardless of this value.
    |
    */

    'max_uses' => 1,

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
    | Case handling follows the alphabet. One that writes a single case accepts
    | either case on submission and folds toward the one it mints, so a reader may
    | type an all-uppercase code in lowercase. One that writes BOTH cases is case
    | sensitive: there `a` and `A` are distinct characters that are both minted,
    | and folding would make valid codes unredeemable.
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

    // Additional guards a request may sign in to (in addition to "guard"). A
    // request selects one by submitting a "guard" field; anything not listed here
    // falls back to the default guard. Only add guards whose user provider you are
    // happy to expose to self-service magic-link login — a user found in a guard's
    // provider can sign in to that guard.
    'guards' => [],

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
    | Captcha guard
    |--------------------------------------------------------------------------
    |
    | A class implementing the CaptchaGuard contract, run before a link or code
    | is issued. Use it to verify a CAPTCHA (hCaptcha, Turnstile, reCAPTCHA) or
    | any pre-issue challenge. It sees only the request, never the account, so it
    | cannot leak whether an email exists. Leave null to apply no challenge.
    |
    */

    'captcha' => null,

    /*
    |--------------------------------------------------------------------------
    | Notification
    |--------------------------------------------------------------------------
    |
    | The notification used to deliver the link or code. To control branding,
    | channels, and copy, point this at a class that EXTENDS MagicLinkNotification.
    |
    | It is not a contract, and a class that does not extend it is ignored rather
    | than rejected: the package falls back to the bundled notification, so mail
    | keeps arriving and the branding never changes.
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
    | Invalid / expired link response
    |--------------------------------------------------------------------------
    |
    | How a browser client sees an invalid or expired magic link / one-time code.
    | "via" selects a built-in strategy — "redirect" | "view" | "abort" | "json"
    | — or the class-string of your own EmailMagicLink\Contracts\
    | InvalidLinkResponder implementation for full control.
    |
    |   redirect  back to the sign-in form (or "redirect_to") with the generic
    |             error flashed and the email re-prefilled (the default)
    |   view      render "view" (it receives a `message` variable)
    |   abort     abort() with "abort_status", using your app's error page
    |   json      return the {message, error} envelope to every client
    |
    | The response never reveals whether the token was unknown or merely expired,
    | so it stays enumeration-resistant whichever strategy you pick. "error_code"
    | is the stable machine code the JSON envelope returns (for JSON clients and
    | the "json" strategy), analogous to the resend "resend_throttled" code.
    |
    */

    'invalid_response' => [
        'via' => 'redirect',
        'view' => 'email-magic-link::invalid',
        'redirect_to' => null,
        'abort_status' => 403,
        'error_code' => 'invalid_or_expired',
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
    | compiled Tailwind (including WireKit's styles) is present on the page. Set
    | it to false (or an empty array) if the host does not use Vite — WireKit's
    | own design tokens are always injected by @wirekitStyles regardless.
    |
    | "styles" lists plain stylesheet URLs to <link> into the WireKit layout.
    | Use it when the host ships a pre-compiled stylesheet instead of (or
    | alongside) a Vite build — e.g. a CDN bundle or an asset() path.
    |
    | "script_nonce" names a class implementing EmailMagicLink\Contracts\
    | ScriptNonce, which supplies the CSP nonce for every tag the bundled screens
    | emit that a strict policy would otherwise reject: the resend countdown's
    | inline script, the WireKit layout's inline stylesheet, and the <link> and
    | <script> WireKit itself writes. Leave it null and the package finds the nonce
    | on its own — it reads the "csp-nonce" container binding spatie/laravel-csp
    | registers, and falls back to a global csp_nonce() function for hosts that
    | define one. Set it only when your nonce lives somewhere else entirely.
    | Under a strict policy without a nonce these are blocked SILENTLY — the
    | countdown simply never runs, and the screen renders unstyled.
    |
    | The WireKit screens have one more requirement, and it is not a nonce. Their
    | components are driven by Alpine, and Alpine's default build compiles its
    | expressions with the Function constructor, which needs "unsafe-eval" in
    | script-src. On these screens that is the one-time-code field: without it the
    | digit boxes stop advancing and pasting a code stops filling them — again
    | silently. Two answers, and only one of them is complete:
    |
    |   1. Set mode to "blade". The plain screens carry the same flow and use no
    |      Alpine and no Livewire, so they need no exception at all. This is the
    |      reliable answer.
    |   2. Set wirekit.scripts.bundle to "csp" (WireKit 2.22.0+) — most of the way,
    |      not all of it. That bundle is built against Alpine's CSP distribution, so
    |      WireKit's own components stop needing "unsafe-eval". But @wirekitScripts
    |      force-injects Livewire's assets (that is how Alpine reaches a page with no
    |      Livewire component on it), and Livewire compiles its own directives at
    |      runtime the same way — so the page still needs "unsafe-eval" unless the app
    |      also runs Livewire's CSP distribution. The bundle also CONTAINS Alpine and
    |      starts it, so an app on it must not load its own as well.
    |
    */

    'ui' => [
        'mode' => env('EMAIL_MAGIC_LINK_UI', 'auto'),
        'vite' => ['resources/css/app.css'],
        'styles' => [],
        'script_nonce' => null,
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
    | Named limiters applied to the package's write endpoints. Override them from
    | your application with RateLimiter::for() using the same names. The "limits"
    | defaults below are used by the bundled limiter definitions.
    |
    | "request" covers ONE route (POST magic-link). "consume" covers FOUR, which
    | therefore share one budget: POST magic-link/verify/{token}, POST
    | magic-link/code, and both invitation routes -- including the GET that only
    | DISPLAYS an invitation, throttled deliberately because the token in its path
    | is guessable-in-principle and the page confirms whether it exists.
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

    /*
    |--------------------------------------------------------------------------
    | Resend guard
    |--------------------------------------------------------------------------
    |
    | An escalating cooldown plus a rolling cap layered on top of the fixed-
    | window limiters above, so a repeatedly clicked "send again" cannot flood
    | an inbox. After each send the next one for that email is held back a
    | little longer (base, then base × factor, up to "max" seconds), and no more
    | than "max_sends" go out per rolling "window". A held-back request is told
    | how many seconds remain so the screen can count down instead of erroring.
    |
    | The same guard is available to your own mail-sending endpoints: inject the
    | EmailMagicLink\Contracts\ResendGuard contract and call attempt()/peek()/
    | reset() with a key of your choosing. It needs a cache store that supports
    | atomic locks (the array, file, database, redis, and memcached stores all
    | do); leave "store" null to use the default cache store.
    |
    | "enabled" turns off throttling on THIS package's request endpoint and
    | nothing else. Keys you own stay guarded — the switch is checked by the
    | endpoint, not by the guard, so disabling magic-link throttling can never
    | disarm your own flood protection.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Token pruning
    |--------------------------------------------------------------------------
    |
    | Every request can create a token row, so the table grows unbounded without a
    | regular purge. The package ships the `email-magic-link:purge` command; this
    | block decides whether it also SCHEDULES it for you.
    |
    | Off by default, deliberately. A package that deletes rows on a schedule
    | nobody asked for is doing something an operator should choose, and hosts
    | that already wire `Schedule::command('email-magic-link:purge')` themselves
    | would otherwise get two entries for one job. Turn it on and the manual line
    | can go.
    |
    | "frequency" accepts: hourly, daily, weekly, monthly. Anything else falls
    | back to daily rather than failing a boot over a typo in a cleanup cadence.
    |
    */

    'prune' => [
        'schedule' => false,
        'frequency' => 'daily',
    ],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    |
    | A magic link signs in somebody who already exists. An invitation does the
    | opposite: it puts an account INTO SERVICE for an address that may have no
    | account at all -- setting a password, confirming the address, making someone a
    | member with roles decided in advance by whoever invited them.
    |
    | The package owns the token: it issues one, supersedes the previous one when you
    | re-invite, rejects an unknown, expired, accepted or revoked one identically, and
    | spends it exactly once. It does NOT own what acceptance means. Setting a
    | password, creating membership and granting roles are your domain, and they reach
    | you through "handler" -- a class implementing EmailMagicLink\Contracts\
    | InvitationHandler. Return an authenticatable from it and the package signs that
    | user in through the same path a magic link uses, including the Fortify two-factor
    | handoff; return null and the invitation is accepted without a session.
    |
    | "view" is the name of YOUR acceptance screen. No screen ships with the package:
    | one that carried a password field would put credential handling inside a package
    | that deliberately handles none. The package renders your view only after the
    | token has already been checked, so a dead invitation is refused before the
    | recipient is asked for anything.
    |
    | Off by default. When you turn it on, "handler" and "view" are both required and
    | the package refuses to boot without them rather than failing at the first click.
    |
    | "retain_accepted_days" is how long accepted and revoked rows survive the purge.
    | They carry the invited address in the clear, so this is a retention decision:
    | 0 deletes them as soon as they settle and keeps no audit trail.
    |
    */

    'invitations' => [
        'enabled' => env('EMAIL_MAGIC_LINK_INVITATIONS_ENABLED', false),
        'ttl' => (int) env('EMAIL_MAGIC_LINK_INVITATION_TTL', 604800),
        'store' => null,
        'handler' => null,
        'view' => null,
        'redirect_to' => '/',
        'retain_accepted_days' => 30,
    ],

    'resend' => [
        'enabled' => env('EMAIL_MAGIC_LINK_RESEND', true),

        'cooldown' => [
            'base' => 30,
            'factor' => 2,
            'max' => 900,
        ],

        'window' => [
            'minutes' => 60,
            'max_sends' => 5,
        ],

        'store' => null,
    ],

];
