# Security Policy

## Supported versions

While this package is in its `0.x` line, security fixes are released against the latest minor version only.

| Version | Supported |
|---|---|
| `0.x` (latest) | Yes |
| older | No |

## Reporting a vulnerability

**Please do not open a public issue for security vulnerabilities.**

Report them privately through GitHub's [private vulnerability reporting](https://github.com/pushery/email-magic-link-for-laravel/security/advisories/new) (the "Report a vulnerability" button on the repository's Security tab). Include:

- a description of the vulnerability and its impact,
- the steps to reproduce it,
- the affected version(s),
- and, if possible, a suggested fix.

You can expect an acknowledgment within **3 business days** and an assessment of the report, including a remediation timeline, within **10 business days**. We will keep you informed throughout and credit you in the release notes once a fix ships, unless you prefer to remain anonymous.

## Dependency updates

This package declares version ranges, not a lock file: the versions of its dependencies in your application come from your own `composer.lock`. Keep them current with `composer update`, and run `composer audit` to check them against the known advisories.

This repository is a read-only mirror of the released tree. Releases arrive as tags, and it carries no update pull requests.

## Scope

This package's security model rests on a few invariants, all covered by the test suite:

- The emailed `GET` link is inert; the single-use token is consumed only on an explicit `POST`.
- Token consumption is atomic — two concurrent requests can never both succeed.
- A magic-link user whose two-factor authentication Fortify considers enabled is routed through Fortify's challenge and is never logged in without the second factor.
- The request endpoint is enumeration-resistant.
- Code mode is protected by a boot-time entropy guardrail.

Reports that demonstrate a break in any of these invariants are especially valuable.
