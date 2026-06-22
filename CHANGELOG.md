# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

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
