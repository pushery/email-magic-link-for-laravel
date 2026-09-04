---
name: email-magic-link-for-laravel
description: >
  Install, configure, and apply the Email Magic Link for Laravel package in a Laravel
  application — passwordless sign-in by emailed magic link or one-time code, standalone
  or with a no-bypass Laravel Fortify two-factor handoff.
license: MIT
metadata:
  author: pushery
---

# Email Magic Link for Laravel

Use this skill when a Laravel application installs or integrates the
`pushery/email-magic-link-for-laravel` package. Laravel Boost surfaces it inside
consuming applications, so keep it focused on adoption — never on package
internals.

## Primary Goal

Apply the package's public API in the smallest correct way for the consuming
application. The smallest correct integration is usually one line: point the
application's "log in" link at a route the package already registered.

## Workflow

### 1. Install

```bash
composer require pushery/email-magic-link-for-laravel
php artisan email-magic-link:install
php artisan migrate
```

The service provider is registered through package discovery. The installer
publishes the config and prints the setup steps; the migration is loaded from the
package, so `migrate` creates the token table without publishing anything. Publish
it only to customize it.

After a later upgrade, ask what the published config does not know about yet:

```bash
php artisan email-magic-link:doctor
```

Publishing freezes the file at that version. The package still merges its own
defaults underneath, so nothing breaks — but a key the file never mentions is a
setting the operator cannot see or tune.

Requires PHP `^8.4` and Laravel `^13.0`. The emails are queued
(`ShouldQueue`), so a queue worker must be running or nothing is delivered.

### 2. Configure

Publish everything at once, or one group:

```bash
php artisan vendor:publish --tag="email-magic-link"           # umbrella
php artisan vendor:publish --tag="email-magic-link-config"
php artisan vendor:publish --tag="email-magic-link-migrations"
php artisan vendor:publish --tag="email-magic-link-views"
php artisan vendor:publish --tag="email-magic-link-lang"
```

Every option in `config/email-magic-link.php` is documented inline. The keys that
decide the shape of the integration:

| Key | Effect |
|---|---|
| `mode` | `link`, `code`, or `both` — which credential the request form issues |
| `ttl` | Credential lifetime in seconds — **900**, and the only one of the three with a default |
| `link_ttl` / `code_ttl` | Per-channel override in seconds; `null` (the default) means fall back to `ttl` |
| `max_uses` | Bounded multi-use links; `1` is single-use |
| `guard` / `guards` | Which auth guard to sign in to; `guards` is an allowlist |
| `routes.*` | Prefix, middleware, and the post-login redirect |
| `api.enabled` | Adds the JSON contract for first-party SPA/mobile clients |
| `invalid_response.via` | What an expired or invalid link renders: `redirect`, `view`, `abort`, `json`, or your own `InvalidLinkResponder` class-string |
| `fortify.mode` | `auto` (bridge on when Fortify is installed), plus `respect_two_factor` |
| `prune.schedule` | Register the daily token purge in the scheduler. Off by default — see Housekeeping |
| `ui.script_nonce` | A `ScriptNonce` class supplying the CSP nonce for every tag the bundled screens emit (countdown script, inline stylesheet, WireKit's tags); `null` reads the `csp-nonce` container binding spatie/laravel-csp registers, then a global `csp_nonce()` |
| `invitations.enabled` | Turn on the invitation channel; needs `invitations.handler` and `invitations.view` — see Invitations |
| `prune.chunk` | Rows one purge `DELETE` removes (default 1000) |

### 3. Apply the package

**The default integration is a link, not code.** The package registers a complete
browser flow under the `web` middleware group:

| Method | URI | Route name |
|---|---|---|
| `GET` | `/magic-link` | `email-magic-link.request.form` |
| `POST` | `/magic-link` | `email-magic-link.request` |
| `GET` | `/magic-link/verify/{token}` | `email-magic-link.confirm` |
| `POST` | `/magic-link/verify/{token}` | `email-magic-link.consume` |
| `GET` | `/magic-link/code` | `email-magic-link.code.form` |
| `POST` | `/magic-link/code` | `email-magic-link.code.consume` |
| `GET` | `/magic-link/resend-countdown.js` | `email-magic-link.resend-countdown-script` |

```blade
<a href="{{ route('email-magic-link.request.form') }}">Sign in without a password</a>
```

That is the whole integration for most applications.

**Consumption is `POST`-only, on purpose.** The emailed link is a signed, inert
`GET` that only renders a confirmation page; the token is spent by the explicit
`POST` behind the "Sign in" button. This is what makes the link safe against
SafeLinks, Mimecast, Proofpoint and browser prefetch, which would otherwise burn a
single-use token before the person ever sees it. Do not "simplify" the flow by
consuming on `GET` — that removes the property the package exists for.

**Two-factor.** `fortify.mode` defaults to `auto`, so with Laravel Fortify
installed the bridge activates by itself: a user with *confirmed* TOTP is handed
to Fortify's own challenge (`fortify.challenge_route`) in a not-yet-authenticated
state. There is no path that signs such a user in without the second factor.

**Issue a credential yourself** when it should travel over SMS, chat, or the
application's own transactional email instead of the bundled notification:

```php
use EmailMagicLink\Facades\EmailMagicLink;

$link = EmailMagicLink::issueLink($user);              // signed URL, nothing sent
$link = EmailMagicLink::issueLink($user, maxUses: 3);  // bounded multi-use
$code = EmailMagicLink::issueCode($user);              // one-time code, nothing sent
```

`issueLink()` also takes `guard`, `passphrase` and `baseUrl`; `issueCode()` takes
`guard`. Both return a value object carrying the credential and its expiry.

**Housekeeping.** Spent and expired tokens must be purged or the table grows
unbounded. Either let the package register the schedule:

```php
// config/email-magic-link.php
'prune' => ['schedule' => true, 'frequency' => 'daily'],
```

…or wire it yourself and leave the switch off:

```php
Schedule::command('email-magic-link:purge')->daily();
```

Do not do both — that is two scheduler entries for one job.

## Examples

Sign in to a second guard, with codes instead of links, behind a custom prefix.
**`guard` is what the request signs in to** — `guards` only widens the allowlist:

```php
// config/email-magic-link.php
'mode' => 'code',
'guard' => 'admin',
'routes' => [
    'prefix' => 'admin/sign-in',
    'redirect_to' => '/admin',
],
```

Reach for `guards` only when ONE installation must serve several guards and the
request picks between them by submitting a `guard` field. An unlisted value falls
back to `guard` silently rather than erroring, so the endpoint never reveals which
guards exist:

```php
'guard' => 'web',
'guards' => ['admin'],   // a request may now ask for "admin" as well
```

Deliver a link over your own channel — mint it, send it yourself, skip the
package's request form and its notification entirely:

```php
$link = EmailMagicLink::issueLink($user, maxUses: 3, passphrase: $sharedSecret);

Mail::to($user)->send(new InvoiceReady($link->url, $link->expiresInMinutes));
```

**What that link does is sign the user in, and only that.** `$link->url` points at
the package's confirmation page — an inert signed `GET` that spends nothing. The
token is consumed when the recipient submits that page, and they then land on
`routes.redirect_to`, exactly as they would coming through the request form. So:

- it is **not** a session-less channel — a consumed link authenticates a session;
- it does **not** redirect to a resource of your choosing — point `redirect_to` at
  the resource, or send the recipient onward once they arrive authenticated.

Deliver `$link->url` verbatim, and never prefetch it.

Swap the resolution of an email address to a user (multi-tenant lookups, soft
deletes, custom columns) by binding the contract:

```php
// config/email-magic-link.php
'user_lookup' => App\Auth\TenantUserLookup::class,
```

The same pattern applies to `token_store`, `captcha` and `invalid_response.via`
— each takes the class-string of a published contract under
`EmailMagicLink\Contracts` and falls back to a bundled default.

`notification` is the odd one out and is **not** a contract: it takes a class that
**extends `MagicLinkNotification`**. Anything else is ignored — the package falls
back to the bundled notification without raising, so a wrong class-string looks
like it worked.

### Invitations — for somebody who has no account yet

A magic link signs in a user who exists. To onboard an address that has no account, use
the invitation channel, never the sign-in flow (which would sign a non-member in):

```php
// config/email-magic-link.php
'invitations' => [
    'enabled' => true,
    'handler' => App\Auth\AcceptInvitation::class,   // implements InvitationHandler
    'view' => 'auth.accept-invitation',               // your screen; the package ships none
],
```

```php
$invitation = app(EmailMagicLink\Contracts\InvitationIssuer::class)
    ->invite('newcomer@example.com', context: ['roles' => ['editor']]);

Mail::to('newcomer@example.com')->send(new YouAreInvited($invitation->url));
```

The handler's `accept()` runs inside the transaction that spends the token: create the
account, set the password, return the user to sign them in (or `null` to accept without a
session). Two routes register while it is on: `GET|POST /magic-link/invitation/{token}`.
`revoke($email)` withdraws every open invitation for an address.

### Multi-tenancy

An application that swaps its cache per tenant discards Laravel's rate limiter and every
named limiter with it. Call `app(EmailMagicLink\Support\RateLimits::class)->define()` after the
swap, and put the tenancy middleware into `routes.middleware`; leave `prune.schedule` off
and run `email-magic-link:purge` through the tenancy runner instead.

## Anti-Patterns

- Do not consume the token on `GET`, and do not remove the confirmation page.
  That page is the scanner- and prefetch-safety property, not a UX wart.
- Do not branch on whether an email address exists. Every response on the request
  path is deliberately identical so account existence never leaks; reintroducing a
  "no such user" message undoes it.
- Do not raise `link_ttl` to days to avoid expiry complaints. Lengthen the resend
  cooldown instead, or switch to bounded multi-use links.
- Do not assume `resend.enabled` turns off the resend guard everywhere. It governs
  this package's request endpoint only; keys your own application guards with the
  same contract stay guarded. (Before 0.19.0 it was global — if you pinned it to
  `true` as a workaround, you can stop.)
- Do not treat the passphrase gate as two-factor. It is a shared secret on the
  link; a passphrase-gated link for a 2FA user still goes through the Fortify
  challenge.
- Do not document package internals here; keep this skill focused on adoption.
  Deeper reference material lives at
  <https://docs.pushery.com/email-magic-link-for-laravel/>.
