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
| `link_ttl` / `code_ttl` | Per-channel lifetime in seconds (default 900) |
| `max_uses` | Bounded multi-use links; `1` is single-use |
| `guard` / `guards` | Which auth guard to sign in to; `guards` is an allowlist |
| `routes.*` | Prefix, middleware, and the post-login redirect |
| `api.enabled` | Adds the JSON contract for first-party SPA/mobile clients |
| `invalid_response.via` | What an expired or invalid link renders: `redirect`, `view`, `abort`, `json`, or your own `InvalidLinkResponder` class-string |
| `fortify.mode` | `auto` (bridge on when Fortify is installed), plus `respect_two_factor` |

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

**Housekeeping.** Schedule the purge so spent and expired tokens do not accumulate:

```php
Schedule::command('email-magic-link:purge')->daily();
```

## Examples

Sign in to a second guard, with codes instead of links, behind a custom prefix:

```php
// config/email-magic-link.php
'mode' => 'code',
'guards' => ['admin'],
'routes' => [
    'prefix' => 'admin/sign-in',
    'redirect_to' => '/admin',
],
```

Gate a resource behind a link without creating a session — mint the link, deliver
it yourself, and let consumption redirect to the resource:

```php
$link = EmailMagicLink::issueLink($user, maxUses: 3, passphrase: $sharedSecret);

Mail::to($user)->send(new InvoiceReady($link->url, $link->expiresInMinutes));
```

Swap the resolution of an email address to a user (multi-tenant lookups, soft
deletes, custom columns) by binding the contract:

```php
// config/email-magic-link.php
'user_lookup' => App\Auth\TenantUserLookup::class,
```

The same pattern applies to `token_store`, `captcha`, `notification` and
`invalid_response.via` — each takes the class-string of a published contract and
falls back to a bundled default.

## Anti-Patterns

- Do not consume the token on `GET`, and do not remove the confirmation page.
  That page is the scanner- and prefetch-safety property, not a UX wart.
- Do not branch on whether an email address exists. Every response on the request
  path is deliberately identical so account existence never leaks; reintroducing a
  "no such user" message undoes it.
- Do not raise `link_ttl` to days to avoid expiry complaints. Lengthen the resend
  cooldown instead, or switch to bounded multi-use links.
- Do not treat the passphrase gate as two-factor. It is a shared secret on the
  link; a passphrase-gated link for a 2FA user still goes through the Fortify
  challenge.
- Do not document package internals here; keep this skill focused on adoption.
  Deeper reference material lives at
  <https://docs.pushery.com/email-magic-link-for-laravel/>.
