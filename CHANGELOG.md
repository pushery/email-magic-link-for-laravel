# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.18.0] - 2026-07-26

### Added

- A bundled **Laravel Boost skill** (`resources/boost/skills/email-magic-link-for-laravel/SKILL.md`).
  Boost surfaces it inside consuming applications, so an agent integrating the package works from
  the package's own adoption guidance — the routes, the config keys that shape an integration, the
  Mint API, and the anti-patterns that quietly remove what the package is for.
- An **umbrella publish tag**. `php artisan vendor:publish --tag="email-magic-link"` now publishes
  every group at once; previously `--tag` took one group name at a time and there was no way to
  publish everything, so a consumer had to know all four names and would miss any group added later.

### Changed

- The documentation moved out of the README to
  [docs.pushery.com/email-magic-link-for-laravel](https://docs.pushery.com/email-magic-link-for-laravel/).
  Everything the README carried — the detailed installation steps, every configuration key, the
  Mint API, multi-use and passphrase-gated links, the resend guard, the two-factor handoff, the
  JSON contract, the extension points and the security model — is there, restructured into
  navigable pages rather than shortened. The README is now a showcase that links to them, so a
  reader no longer scrolls a manual to find one default.

### Fixed

- The `EmailMagicLink` facade's `@method` annotation for `issueLink()` advertised only `$user` and
  `$guard`, so calling it with the `maxUses`, `passphrase` or `baseUrl` arguments added in `0.17.0`
  failed static analysis even though it worked at runtime. The annotation now matches the
  `MagicLinkIssuer` contract, and a test keeps the two in step.
- **Published migrations kept their `0001_01_01_*` ordering prefix.** That prefix is correct while
  the package auto-loads them, and wrong once they land in an application's `database/migrations`,
  where it sorts them before `create_users_table` — so `migrate` on a fresh application tried to
  create a table with a foreign key to a `users` table that did not exist yet. They are now
  published through `publishesMigrations()`, which rewrites the prefix to the publish date.
- **A non-string `limiters.request` / `limiters.consume` config value threw during route
  registration**, i.e. on every request, with an error pointing at the package's routes file rather
  than at the host's configuration. Both are now resolved through the package's own typed config
  accessor, which falls back to the documented default.

## [0.17.1] - 2026-07-12

### Added

- A README recipe for **authorizing a gated resource without login** — a one-time
  file download or gated view. Mint a single-use token with the Mint-API /
  `TokenStore` and consume it on your own route with `claimLink()`; a successful
  claim authorizes serving the resource without creating a session. No new
  channel, no serialized payload — it reuses the existing single-use,
  hashed-at-rest token model.

## [0.17.0] - 2026-07-11

### Added

- **Per-link base-URL override.** `issueLink($user, baseUrl: 'https://tenant-a.example.com')`
  builds the confirmation link for another host — useful for multi-domain or
  multi-tenant apps. The signature is computed over the final host, so the link
  verifies only when visited there and there is no open redirect.
- **Optional per-link passphrase gate.** A magic link can now require a shared
  secret — `issueLink($user, passphrase: '…')` — that the recipient enters on the
  confirmation page before the link is consumed. The passphrase is stored only as
  a bcrypt hash and verified before the token is spent, so a wrong passphrase
  never consumes a use of a multi-use link, and wrong or missing passphrases fail
  through the same generic, rate-limited response as any other bad link. This is
  a lightweight gate, **not** two-factor authentication: a passphrase-gated link
  for a two-factor user still hands off to the Fortify TOTP challenge afterwards —
  the passphrase never replaces or bypasses the second factor.
- **Bounded multi-use links.** A magic link can now be redeemed a configurable
  number of times before it is spent — pass `issueLink($user, maxUses: 3)`, or
  set the `max_uses` config default. Each redemption is decremented atomically in
  the same conditional `UPDATE` that consumes the token (never read-then-write),
  so concurrent redemptions can never exceed the limit; this is proven under real
  PostgreSQL and MySQL 8.4 row locking. An exhausted link behaves exactly like a
  spent or expired one, so it stays enumeration-resistant. The default is 1
  (single-use, unchanged), and one-time codes are always single-use.

  A custom `TokenStore` or `MagicLinkIssuer` implementation must match the widened
  signatures: `TokenStore::issue()` gains optional `?int $maxUses = null` and
  `?string $passphrase = null`, `claimLink()` gains `?string $passphrase = null`,
  `TokenStore` adds `requiresPassphrase(string $token): bool`, and
  `MagicLinkIssuer::issueLink()` gains optional `?int $maxUses`, `?string
  $passphrase` and `?string $baseUrl` parameters. Callers of these methods are
  unaffected. The token table gains `uses_remaining` and `passphrase_hash`
  columns — run `php artisan migrate` after upgrading.
- The response to an invalid or expired magic link or one-time code is now
  configurable under a new `invalid_response` config block. Choose `redirect`
  (the previous behaviour, still the default), `view` to render your own error
  page, `abort` to return an HTTP status through your app's error page, or `json`
  to return the `{message, error}` envelope to every client — or point `via` at
  your own `EmailMagicLink\Contracts\InvalidLinkResponder` implementation for
  full control. Every strategy keeps the response generic and identical for an
  unknown versus an expired token, so the flow stays enumeration-resistant, and
  the JSON error code is configurable via `invalid_response.error_code`.

## [0.16.1] - 2026-07-11

### Fixed

- The PHP version badge in the README renders again. It relied on the
  `packagist/php-v` shields.io route, which currently returns empty for every
  package (a broken upstream endpoint, not specific to this package); it now reads
  the supported PHP version from the package's own requirement via the
  `packagist/dependency-v` route.

## [0.16.0] - 2026-07-07

### Added

- A **resend guard** that layers an escalating cooldown plus a rolling cap on top
  of the existing per-minute limiters, so a repeatedly clicked "send again" can no
  longer flood an inbox. After each send the next one for that email is held back a
  little longer (30s → 60s → 120s …, up to a ceiling) and no more than five go out
  per rolling hour. All values are configurable under the new `resend` config block,
  and the whole guard can be switched off with `resend.enabled = false`. It is keyed
  on the submitted email alone — never on whether an account exists — so it stays
  enumeration-safe, and it clears itself once a link or code for the address is
  verified, so a real sign-in is never punished.
- A held-back request now tells the caller how many seconds remain instead of a bare
  error: the bundled request screen disables its button and counts down, and JSON
  clients receive a `429` with a `Retry-After` header and a stable
  `"error": "resend_throttled"` code. The new `resend_throttled` message ships
  translated in every bundled locale.
- The guard is a **public, host-consumable service**: inject the
  `EmailMagicLink\Contracts\ResendGuard` contract and call `attempt()`, `peek()`, and
  `reset()` with a key of your own to protect your app's own mail-sending endpoints
  (a "resend code" button on a custom challenge, a re-invite, a reset resend). It
  needs a cache store that supports atomic locks and fails closed on one that does
  not.

## [0.15.2] - 2026-07-05

### Added

- The token-claim path is now tested against **MySQL 8.4** — the database engine
  Laravel Cloud runs (alongside PostgreSQL) — so the package is proven to work on
  Laravel Cloud out of the box. The atomic single-use link claim and the
  one-time-code lockout are now exercised under real InnoDB row locking, closing
  the gap that in-memory SQLite (whose row locking is a no-op) could not cover.

## [0.15.1] - 2026-07-04

### Changed

- Documentation and the `email-magic-link:install` command now spell out the two
  setup steps that are easy to miss: running `php artisan migrate` to create the
  token table, and keeping a queue worker running (or using the `sync` queue
  connection locally) because the magic-link email is dispatched to the queue.

## [0.15.0] - 2026-06-29

### Fixed

- The WireKit sign-in screens rendered unstyled. The layout never injected
  WireKit's `@wirekitStyles`, so none of its design tokens (`--color-wk-*`,
  `--padding-wk-*`, …) were defined and every component fell back to a
  transparent, padding-less default. The layout now injects `@wirekitStyles`,
  wraps each card in `<x-wirekit::card.body>` so its content is padded, spaces
  the fields with `<x-wirekit::stack>`, and lets a long one-time code wrap
  rather than forcing a horizontal scroll on narrow phones. The page shell is
  now self-contained, so the screens center correctly even when the host's
  Tailwind build does not scan the package's own views.

### Added

- `ui.styles`: a list of plain stylesheet URLs to `<link>` into the WireKit
  layout, for hosts that ship a pre-compiled stylesheet instead of (or alongside)
  a Vite build. `ui.vite` now also accepts `false` to skip `@vite` entirely on a
  non-Vite host.

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
