# Email Magic Link for Laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/pushery/email-magic-link-for-laravel.svg)](https://packagist.org/packages/pushery/email-magic-link-for-laravel)
[![Total Downloads](https://img.shields.io/packagist/dt/pushery/email-magic-link-for-laravel.svg)](https://packagist.org/packages/pushery/email-magic-link-for-laravel)
[![PHP Version](https://img.shields.io/packagist/php-v/pushery/email-magic-link-for-laravel.svg)](https://packagist.org/packages/pushery/email-magic-link-for-laravel)
[![License](https://img.shields.io/packagist/l/pushery/email-magic-link-for-laravel.svg)](https://packagist.org/packages/pushery/email-magic-link-for-laravel)

Passwordless email authentication for Laravel — magic links and one-time codes — that works **standalone** or alongside **Laravel Fortify**.

Plenty of packages send a magic link. This one is built around two properties most of them get wrong:

### 1. A correct, no-bypass Fortify two-factor handoff

If a user has confirmed TOTP through Fortify, clicking a magic link does **not** log them in. Instead they are handed off to Fortify's own two-factor challenge in a not-yet-authenticated state, and the login only completes inside Fortify after the code is verified. There is no path that signs a two-factor user in without the second factor — and an end-to-end test runs the real Fortify challenge to keep it that way across Fortify upgrades.

### 2. Scanner-safe and prefetch-safe link consumption

The emailed link is a `GET` that **only renders a confirmation page** — it performs no authentication and no state change. The single-use token is consumed solely by an explicit `POST` from that page. Corporate email security scanners (Microsoft SafeLinks, Mimecast, Proofpoint) and browser prefetch follow the `GET` and cannot burn the link before the human clicks "Sign in".

---

## Requirements

| Component | Constraint |
|---|---|
| PHP | `^8.4` (8.4 and 8.5) |
| Laravel | `^13.0` |
| Laravel Fortify | `^1.0` — optional, only for the two-factor handoff |

The package requires `laravel/framework` (for the `FormRequest` base it validates with) and adds no third-party runtime dependencies. Fortify is a **suggested** dependency; the core never references a Fortify symbol unless Fortify is installed and the bridge is enabled.

## Installation

```bash
composer require pushery/email-magic-link-for-laravel
```

Then run the installer to publish the configuration and print the next steps:

```bash
php artisan email-magic-link:install
```

Add `--views` to also publish the Blade views. The migration is loaded automatically, so a fresh app works without publishing anything.

Prefer to do it by hand? The individual publish tags are still available:

```bash
php artisan vendor:publish --tag=email-magic-link-config
php artisan vendor:publish --tag=email-magic-link-migrations
php artisan vendor:publish --tag=email-magic-link-views
```

## Quick start

Out of the box the package registers a complete browser flow under the `web` middleware group:

| Method | URI | Name | Purpose |
|---|---|---|---|
| `GET` | `/magic-link` | `email-magic-link.request.form` | "Enter your email" form |
| `POST` | `/magic-link` | `email-magic-link.request` | Issue a link or code |
| `GET` | `/magic-link/verify/{token}` | `email-magic-link.confirm` | Inert, signed confirmation page |
| `POST` | `/magic-link/verify/{token}` | `email-magic-link.consume` | Consume a magic link |
| `GET` | `/magic-link/code` | `email-magic-link.code.form` | Enter a one-time code |
| `POST` | `/magic-link/code` | `email-magic-link.code.consume` | Consume a one-time code |

Point your "log in" link at `route('email-magic-link.request.form')` and you have passwordless login. A user enters their email, receives a link, clicks it, confirms, and is signed in.

## The three configurations

**Standalone — no Fortify.** A verified user is logged in directly with `Auth::login`. There is no second factor in standalone mode, by design.

**With Fortify, bridge on (`fortify.mode = 'auto'`, the default).** A user with confirmed TOTP is routed through Fortify's challenge; everyone else logs in directly.

**With Fortify, bridge off (`fortify.mode = false`).** Fortify can be installed for other flows while the magic-link channel ignores it entirely and logs users in directly.

The channel itself can be turned off completely with `enabled = false`, independent of whether Fortify is installed.

## Why a magic link costs one extra click

Because consumption is `POST`-only, the user clicks the emailed link (a `GET`) and then clicks "Sign in" on the confirmation page. That second click is the price of being safe against link-following security scanners and prefetch — tools that would otherwise spend a single-use token before the person ever sees it. We consider that trade-off worth it; it is the whole point of the package.

For first-party SPA or mobile clients that exchange the token over JSON without an interstitial, set `api.enabled = true` and `POST` the token with an `Accept: application/json` header.

## The two-factor handoff (and its trade-off)

When the bridge is active and a verified user has **confirmed** two-factor authentication (gated on `two_factor_confirmed_at`, not merely a stored secret, so a user mid-setup is never locked out):

1. The token is consumed.
2. Fortify's `login.id` session key is set and the request is redirected to Fortify's `two-factor.login` challenge — **without** logging the user in.
3. The login completes inside Fortify only after the TOTP code passes.

**Trade-off:** the token is already spent when the handoff happens, so if a user abandons the TOTP step they must request a fresh link. This is intentional — the link is single-use and the challenge is a separate, deliberate step.

**Guard alignment:** when the handoff is enabled, `email-magic-link.guard` must resolve to the same provider as `fortify.guard`, because Fortify re-resolves the challenged user from its own guard's provider. With mismatched providers the challenge fails closed (the user cannot complete login) rather than logging anyone in. The default `web` guard satisfies this out of the box.

`fortify.respect_two_factor = false` disables this handoff. **This is a security downgrade: magic-link logins will skip two-factor for users who have it enabled.** It emits a warning at boot.

## Configuration

All values live in `config/email-magic-link.php`.

| Key | Default | Purpose |
|---|---|---|
| `enabled` | `true` | Master switch for the channel (routes, notifications, limiters). |
| `mode` | `'link'` | `'link'`, `'code'`, or `'both'`. |
| `ttl` | `900` | Token lifetime in seconds. |
| `code_length` | `8` | One-time code length. |
| `code_alphabet` | unambiguous A–Z/2–9 | Alphabet for codes (governs keyspace). |
| `max_attempts_per_token` | `5` | Hard per-token lockout for code mode. |
| `entropy_safety_factor` | `1_000_000` | Guardrail bar; cannot be lowered below this floor. |
| `guard` | app default | Stateful guard to log into. |
| `user_lookup` | bundled | `UserLookup` implementation. |
| `token_store` | bundled | `TokenStore` implementation. |
| `notification` | `MagicLinkNotification` | Notification class (extend it to customize). |
| `routes.prefix` | `''` | Route prefix. |
| `routes.middleware` | `['web']` | Route middleware (sessions + CSRF). |
| `routes.redirect_to` | `'/'` | Fallback redirect after login. |
| `api.enabled` | `false` | Direct JSON token exchange for SPA/mobile. |
| `fortify.mode` | `'auto'` | `'auto'` (on if Fortify present), `true`, or `false`. |
| `fortify.respect_two_factor` | `true` | Route confirmed-2FA users through the challenge. |
| `fortify.challenge_route` | `'two-factor.login'` | Fortify challenge route name. |
| `limiters.request` / `limiters.consume` | named limiters | Override with `RateLimiter::for()`. |
| `limits.request` / `limits.consume` | `5` / `10` per minute | Defaults for the bundled limiters. |

## One-time codes

Set `mode` to `'code'` (or `'both'`) to email a short code instead of a link. Codes are governed by a **boot-time entropy guardrail**: the package refuses to boot if a code's keyspace divided by its attempt lockout falls below `entropy_safety_factor`, naming the exact keys to fix and the minimum length that would pass. Magic links carry 256 bits of entropy and pass trivially.

In `'both'` mode the request endpoint issues a link by default, or a code when `channel=code` is submitted.

## Cleaning up tokens

Every request inserts a row, and consumption only marks it consumed. Schedule the bundled command to delete expired and consumed tokens so the table stays small:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('email-magic-link:purge')->daily();
```

## Translations

Every user-facing string — in the views and the notification — runs through
Laravel's translator under the `email-magic-link` namespace, so the package ships
in English and adapts to the application's active locale. Publish the language
files to translate or reword them:

```bash
php artisan vendor:publish --tag=email-magic-link-lang
```

That copies the strings to `lang/vendor/email-magic-link/{locale}`. Add a locale
by copying the `en` directory (for example to `de`) and translating the values;
the `:app` and `:minutes` placeholders are filled in at render time.

## Extension points

**Take over the post-verification flow** by rebinding the authenticator contract:

```php
use EmailMagicLink\Contracts\MagicLinkAuthenticator;

$this->app->bind(MagicLinkAuthenticator::class, MyAuthenticator::class);
```

The contract returns a response, so it — not an event — is where login-versus-2FA is decided.

**React to events** (observability only — they must not drive flow control):

- `MagicLinkRequested($user, $channel, $request)`
- `MagicLinkVerified($user, $request)`
- `TwoFactorChallengeRequired($user, $request)` (fired by the bridge)

Successful logins also fire Laravel's own `Illuminate\Auth\Events\Login`.

**Swap collaborators** via config: the `notification` class (extend `MagicLinkNotification`), a `UserLookup` (resolve users your way), and a `TokenStore` (custom persistence).

## Security at rest

Tokens are never stored in the clear — only a keyed HMAC-SHA256 hash, looked up via an index. Consumption is a single race-free conditional claim (PostgreSQL `RETURNING`, with a portable affected-rows fallback) so two concurrent requests can never both succeed. Links are additionally protected by Laravel signed routes. Raw tokens and full link URLs are never logged.

The request endpoint is rate-limited per email and per IP out of the box. For high-risk deployments, layer a CAPTCHA or challenge widget on the request form as an additional bot-protection measure — that is a host-application concern this package deliberately leaves to you. Throttled responses carry the standard `Retry-After` and `X-RateLimit-*` headers, so API and SPA clients can back off correctly.

See [SECURITY.md](SECURITY.md) for the supported versions and how to report a vulnerability.

## Versioning

This package follows [Semantic Versioning](https://semver.org). It is in its `0.x` line while the public API settles; the backward-compatibility promise begins at `1.0.0`.

## License

The MIT License. See [LICENSE](LICENSE).
