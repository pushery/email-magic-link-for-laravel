# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.14.0] - 2026-06-25

### Added

- Bundle the regional locale variants `en-GB`, `en-US`, `pt-PT`, and `pt-BR` (copies of the
  `en`/`pt` messages, ready for regional refinement), so apps that distinguish them get fully
  localized magic-link screens and emails with no fallback. `nl` and the existing locales are
  unchanged.
- A public Mint-API for issuing magic links and one-time codes **without sending
  mail**, so you can deliver them over any channel (SMS, chat, an existing email,
  a queued job). Use the `EmailMagicLink` facade or inject the new
  `MagicLinkIssuer` contract: `issueLink($user)` returns a signed single-use
  `IssuedLink` (URL plus expiry) and `issueCode($user)` returns an `IssuedCode`.
  Minted credentials are hashed at rest and single-use exactly like the emailed
  flow. Issuance re-resolves the user through the target guard's provider and
  fails closed (`UserNotInGuardException`, `UnknownGuardException`,
  `MagicLinkDisabledException`) rather than minting a dead or misdirected token.

## [0.13.3] - 2026-06-23

### Changed

- The Composer dist is now lean: a shipped `.gitattributes` marks the marketing
  banner (`art/`), repository metadata (`.github/`, `CHANGELOG.md`,
  `CONTRIBUTING.md`), and itself as `export-ignore`, so the installed package
  carries only the runtime code, config, views, translations, and license.

## [0.13.2] - 2026-06-23

### Added

- GitHub issue templates (bug report and feature request forms, plus a chooser
  config that routes security reports to private disclosure). Repository metadata
  only; the installed package and its API are unchanged.

## [0.13.1] - 2026-06-23

### Added

- A header banner and a short "Built by Pushery" section in the README. The
  banner ships in a new `art/` directory; the package API is unchanged.

## [0.13.0] - 2026-06-22

### Fixed

- The status, invalid-or-expired, and challenge-failed response messages were
  hardcoded in English and ignored the active locale. They now run through the
  translator like the rest of the package, with translations in all seven bundled
  locales (en/de/es/fr/it/nl/pt). A new guard test fails the build if any controller
  response reintroduces a hardcoded user-facing string instead of using the
  translator, so this gap cannot recur.

## [0.12.0] - 2026-06-22

### Added

- Per-channel token lifetimes. The new `link_ttl` and `code_ttl` config keys give
  links and codes their own expiry — for example a shorter, hand-typed code — while
  `ttl` remains the default both inherit when an override is unset or non-positive.
  The notification's "expires in N minutes" line and the link's signed-route expiry
  follow the channel's lifetime.

## [0.11.0] - 2026-06-22

### Added

- A `CaptchaGuard` extension point. Point the new `captcha` config at a class
  implementing `EmailMagicLink\Contracts\CaptchaGuard` to verify a CAPTCHA
  (hCaptcha, Turnstile, reCAPTCHA) or any pre-issue challenge before a link or code
  is issued. It runs before the user lookup, so a failed challenge rejects the
  request identically whether or not the account exists, and returns a
  `captcha_failed` error (JSON) or a form error. The default applies no challenge.

## [0.10.0] - 2026-06-22

### Added

- A stable JSON error envelope for the API variant: failed consumptions now return
  `{ "message": …, "error": "invalid_or_expired" }`, a machine-readable code a SPA
  or mobile client can branch on without parsing the human message.
- The two-factor hand-off now answers an API client with
  `{ "authenticated": false, "two_factor": true, "redirect": … }` instead of a bare
  redirect, so the client knows it must complete the challenge and where to go.

### Changed

- Documented the full JSON token-exchange contract (success, two-factor, error,
  validation, and rate-limit shapes) in the README.

## [0.9.0] - 2026-06-22

### Added

- `MagicLinkAuthenticated($user, $guard, $request)` event, fired the moment a user
  is actually logged in (never for a two-factor hand-off) — the precise signal for
  an audit log, carrying the guard.
- `MagicLinkConsumptionFailed($reason, $request)` event, fired on every failed
  consume. The `ClaimFailure` reason distinguishes a stale or unknown token from a
  wrong code or a brute-force `LockedOut`, so a host can log all failures and alert
  on lockouts without the user-facing response ever leaking the reason.

## [0.8.0] - 2026-06-22

### Added

- Multi-guard sign-in. A request may select a guard (from the new `guards`
  allowlist) via a `guard` field; the token is issued for that guard, the user is
  resolved through its provider, and login completes on it. Unknown guards fall
  back to the default. The link and code flows are both guard-aware.

### Changed

- The `MagicLinkAuthenticator::authenticate()` contract gains a `string $guard`
  argument (before `$remember`). Custom authenticators must add the parameter.

## [0.7.0] - 2026-06-22

### Added

- The post-login redirect now returns the user to the URL they originally requested
  (the protected route that triggered the flow), falling back to `redirect_to`. It
  applies to both the browser redirect and the API response, and can be turned off
  with the new `routes.intended` config.

### Changed

- The API response `redirect` field is now the resolved absolute destination URL
  (the intended URL when present, otherwise `redirect_to`).

## [0.6.0] - 2026-06-22

### Added

- Bundled Italian, Dutch, and Portuguese translations, bringing the built-in locales
  to English, German, Spanish, French, Italian, Dutch, and Portuguese.

## [0.5.0] - 2026-06-22

### Added

- Bundled German, Spanish, and French translations alongside the English baseline,
  so the sign-in screens and notification follow the application's locale out of the box.

## [0.4.0] - 2026-06-22

### Added

- Optional WireKit (`pushery/wirekit`) sign-in screens. When WireKit is installed
  the views render with its components automatically; otherwise the plain Blade
  views are used. Controlled by the new `ui.mode` (`auto` / `blade`) and `ui.vite`
  configuration. The routes, CSRF-protected POSTs, and single-use consumption are
  unchanged — only the presentation differs.

## [0.3.1] - 2026-06-22

### Added

- Packagist badges (version, downloads, PHP version, license) in the README.

### Documentation

- Document that throttled (429) responses carry the `Retry-After` and
  `X-RateLimit-*` headers, so API and SPA clients can back off correctly.

## [0.3.0] - 2026-06-22

### Added

- Translatable user-facing strings: the views and the mail notification now resolve
  every string through the `email-magic-link` translation namespace, with publishable
  language files (`--tag=email-magic-link-lang`).

## [0.2.0] - 2026-06-22

### Added

- An `email-magic-link:install` command that publishes the configuration (and,
  with `--views`, the Blade views) and prints the setup steps.

## [0.1.1] - 2026-06-22

### Security

- Normalize the one-time-code regex with the `/u` flag for multibyte safety.

### Documentation

- Recommend layering a CAPTCHA on the request form for high-risk deployments.

## [0.1.0] - 2026-06-22

### Added

- Passwordless email authentication via magic links and one-time codes.
- Scanner-safe, prefetch-safe consumption: an inert signed `GET` confirmation page
  and a `POST`-only, atomically claimed single-use token.
- Optional, isolated Laravel Fortify bridge that routes a confirmed-two-factor user
  through Fortify's challenge with no bypass, verified end-to-end against the real
  Fortify TOTP flow.
- Enumeration-resistant request endpoint, per-email and per-IP rate limiting, and a
  per-token attempt lockout for code mode.
- Boot-time, fail-closed entropy guardrail that refuses brute-forceable code
  configurations, with the generator and guardrail sharing one canonical
  distinct-character alphabet.
- An `email-magic-link:purge` command to delete expired and consumed tokens.
- Swappable authenticator, user-lookup, token-store, and notification, plus
  observability events (`MagicLinkRequested`, `MagicLinkVerified`,
  `TwoFactorChallengeRequired`).
- Publishable configuration, migration, and views.
