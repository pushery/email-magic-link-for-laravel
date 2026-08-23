# Changelog

All notable changes to this package are documented here. The format is based on
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres
to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.22.0] - 2026-08-23

### Changed

- **The resend countdown's script is now a same-origin file rather than an inline block.**
  Inline, it needed a Content-Security-Policy nonce under any strict policy — and an
  application whose policy issues no nonces had nothing it could pass through, so for those
  hosts the countdown could not be made to run at all. Served from
  `/magic-link/resend-countdown.js`, a plain `script-src 'self'` accepts it with no nonce and
  no configuration.

  It still carries the nonce when the application has one, and that is not redundancy: a
  policy built on `'strict-dynamic'` ignores `'self'`, so there the nonce remains the only
  thing that grants the tag.

  No build step, no npm, no publishable assets and no `dist/` — the source is a constant in
  `EmailMagicLink\Support\ResendCountdownScript`, so it is present the moment the package is
  installed rather than the moment a host remembers to publish something. The URL carries a
  digest of that source, which is what makes its immutable cache header honest: change the
  script and the URL changes with it.

  The tag is `defer`, so it no longer blocks the parse of the sign-in screen. Its response
  is cached `private`, not `public`: the route runs inside your route middleware group, so
  it leaves with the session and XSRF cookies attached like every other page, and a shared
  cache that stores a response together with its `Set-Cookie` headers can hand one
  visitor's session cookie to the next. The browser cache is where the benefit is anyway.

- **The plain layout's inline stylesheet now carries the CSP nonce too.** The WireKit
  layout has nonced its `<style>` block since the CSP work landed; the plain one had been
  left out. The consequence is not subtle: under a strict policy that block is blocked, and
  that block is the entire styling of the bundled screens — the sign-in page arrives
  completely unstyled. The countdown script degrades politely when it is blocked. This does
  not. Hosts without a policy render byte-identically.

  The stylesheet stays **inline** rather than moving to a file the way the countdown script
  did, and that is now a measured decision rather than an inherited default: the whole
  sign-in screen fits inside one initial congestion window, so it paints in a single round
  trip. A stylesheet in a file would cost a second, render-blocking round trip on a page
  that is entirely above the fold. A test holds that threshold, so growing past it is a
  notice rather than a silent regression.

- **Looking at an invitation no longer spends the budget for accepting one.** The page that
  displays an invitation was the only `GET` in the package carrying a throttle, and it drew
  on the `consume` limiter — the same budget as the three routes that actually spend a
  credential. Reloading the page therefore cost a real sign-in attempt, and because the
  per-IP half of that limiter is shared, behind one egress address (an office, a carrier's
  CGNAT, a school) one person reloading could lock another out.

  It now has its own limiter, `limiters.invitation_view`
  (`email-magic-link:invitation-view`), with its own `limits.invitation_view` default of 30
  per minute — higher than `consume` because it guards a page load rather than a credential
  being spent. The route stays throttled: unlike the sign-in confirmation it is not behind
  `signed`, so its answer tells an unauthenticated caller whether a token exists.

  Nothing to do on upgrade. Hosts that redefine the package limiters with
  `RateLimiter::for()` can leave the new one alone and get the bundled definition; hosts
  that want the old behavior can point `limiters.invitation_view` at their consume limiter.

### Fixed

- **A base URL without a scheme produced a link without one.** `issueLink()` and `invite()`
  take an optional `baseUrl` so a link can be built for another host. Passed a bare host —
  `tenant.example.com` or `//tenant.example.com` — the generator forced a root URL that
  carried no scheme of its own, and the root then decided: the link came out as
  `tenant.example.com/magic-link/verify/...`, absolute nowhere.

  That string goes in an email, and mail clients disagree about it — some make a link, some
  make none, some make a relative one. For an invitation it is the difference between working
  and the invited person never getting in.

  A schemeless base URL is now completed with the scheme the application itself is served
  over, which is the one completion that invents nothing the host has not already stated. A
  base URL that carries its own scheme is untouched, including a tenant on https while the
  app runs on http. The signature is still computed over the final URL, so the completed link
  verifies where it points.

## [0.21.0] - 2026-08-21

### Added

- **Invitation tokens — the storage half.** A magic link signs in somebody who already
  exists; an invitation puts an account into service for an address that may have no
  account at all. The two cannot share a table: every sign-in row is bound to an indexed,
  NOT NULL `user_id`, and widening that column would mean altering a populated table in
  every application that already installed this package.

  So invitations get their own table, `email_magic_link_invitations`, with their own store
  behind the `EmailMagicLink\Contracts\InvitationStore` contract. The separation also
  removes a filter someone could forget: the sign-in store narrows on `channel = 'link'`,
  and here there is no shared table to narrow, so an invitation token cannot reach the
  sign-in path even in principle.

  What the store guarantees: the plaintext is never stored, only a keyed hash; issuing
  again supersedes the previous invitation for the same address and guard, so re-inviting
  never leaves two working links behind; looking at an invitation consumes nothing; and
  claiming one is a single conditional UPDATE, so two concurrent claims cannot both
  succeed.

  The `EmailMagicLink\Contracts\InvitationIssuer` contract mints one and hands back the
  signed URL, and `EmailMagicLink\Contracts\InvitationHandler` is where you say what
  accepting one MEANS — setting a password, creating membership, granting the roles the
  inviter chose. Return an authenticatable from it and the package signs that user in
  through the same path a magic link uses; return null and the invitation is accepted
  without a session.

  Turning invitations on requires both a handler and an acceptance view, and the package
  refuses to boot without them rather than failing at the moment an invited person clicks
  their link. No acceptance screen ships with it: one carrying a password field would put
  credential handling inside a package that deliberately handles none.

  `email-magic-link:purge` clears settled invitations along with expired tokens, so there
  is no second command to schedule. The line only appears when invitations are on.

  Two routes carry the acceptance, and the package owns both. The GET is inert — it
  verifies the signature, checks the invitation, and only then renders your view — so an
  email security scanner following the link cannot burn it, and a dead invitation is
  refused *before* the recipient is asked for anything. Only the POST spends it, and it
  runs the claim and your handler in one transaction: if your handler throws, the
  acceptance rolls back and the link still works.

  Every dead invitation gets the same answer, byte for byte: unknown, expired, already
  accepted, revoked, tampered signature. The reason reaches your application through the
  `InvitationRejected` event and goes nowhere else — anything more specific in the
  response would answer "was this ever a real link".

  The `invitations` config block defaults to `enabled => false`, so nothing changes for an
  existing installation.

### Fixed

- **A magic link could be reported as unknown on a deployment with a read connection.**
  `claimLink()` now runs in a transaction, so every statement it issues uses the write
  connection.

  `Connection::getReadPdo()` returns the read connection unless a transaction is open,
  `sticky` is set after an earlier write, or the application forced read-on-write. Claiming
  a link satisfied none of those — it is usually the first database work of the request — so
  all four of its statements went to the replica: both lookups, the failure classification,
  and, on PostgreSQL, the atomic claim itself, which is the one driver that reaches
  `select()` rather than `update()`.

  The lookups are the quieter half and the likelier one to be seen: a link issued moments
  ago may not have replicated yet, so a valid link is rejected as unknown — intermittently,
  and only under replication lag. One-time codes were never affected; `claimCode()` has
  always run in a transaction.

  Nothing changes for a single-connection deployment, which is the default.

- **An application that sets `Date::use(CarbonImmutable::class)` could not mint at all.**
  `issueLink()` and `issueCode()` are now typed against `Carbon\CarbonInterface`, as is
  `MagicLinkToken::isExpired()`.

  The reach was wider than the signatures suggest. Eloquent's `datetime` cast resolves
  through the `Date` facade on every return path, so under that setting `$token->expires_at`
  is itself a `CarbonImmutable` — and this package fed exactly that value into `IssuedLink`
  and `IssuedCode`, which required the mutable `Illuminate\Support\Carbon`. The `TypeError`
  was therefore raised inside the package on a call that passes no date at all, not at the
  edge where a caller hands one in. Both entry points were unusable, not merely awkward.

  No class type could ever have covered both cases: `CarbonImmutable` does not extend
  `Illuminate\Support\Carbon`, and the two branches part further down still, at `DateTime`
  and `DateTimeImmutable`. `CarbonInterface` is the only surface that spans them, and both
  implement it.

  **What you get back has not changed.** Without `Date::use()` these values are the mutable
  `Illuminate\Support\Carbon` they have always been; a test pins that alongside the
  immutable case.

  Two consequences worth knowing before you upgrade:

  - **Type your own parameters against `CarbonInterface`.** Runtime behavior is unchanged,
    but code that passes `IssuedLink::$expiresAt` into a parameter typed as
    `Illuminate\Support\Carbon` will be flagged by your own static analysis, because the
    property is now declared wider than that.
  - **If you extend `MagicLinkToken` and override `isExpired()`**, widen your parameter to
    `?CarbonInterface` in the same upgrade. PHP forbids narrowing an inherited parameter, so
    an override still typed `?Carbon` is a fatal error at class-load time.

## [0.20.2] - 2026-08-18

### Changed

- **Development only — nothing changes for an application that installs this package.**
  The `test:coverage`, `mutate` and `mutate:detect` composer scripts now run through
  `@php -d pcov.directory=.`.

  pcov collects coverage only within its own directory scope, and with that scope unset it
  reaches neither `config/` nor `database/`. Both therefore reported 0.0% while the suite
  had been widened to certify everything the package ships; with the scope set they report
  100%. The two halves only work together, which is why the scripts moved with it.

  These scripts are run by this package's own contributors, never by the applications that
  require it, so upgrading from 0.20.1 is a no-op at runtime. Everything else on this
  release line is tests, continuous-integration lanes and tooling, none of which ship.

## [0.20.1] - 2026-08-04

### Fixed

- **The CSP story on the WireKit screens ended one line early.** The layout resolved a
  nonce and handed it to `@wirekitStyles` and `@wirekitScripts` — and then called
  `@livewireScripts` with no argument. Livewire falls back to `Vite::cspNonce()` when
  it is called bare, which is a **different source** than this package's `ScriptNonce`
  contract, so an application that resolves its nonce through us and not through Vite
  served a nonced WireKit tag beside a bare Livewire one.

  Under a policy built on `'strict-dynamic'` the nonce is the only thing that grants a
  tag, so that one was blocked — and a blocked script raises no error and renders no
  message. Alpine never booted, and the screen looked like a styling bug rather than a
  policy rejection. This is the same failure shape as the missing countdown nonce in
  0.20.0, one layer further down.

  `@livewireScripts(['nonce' => $emlNonce])` now carries it. Passing `null` is
  byte-identical to passing nothing, so an application without a policy renders exactly
  as before.

### Changed

- **The WireKit screens now state the version they actually need.** `pushery/wirekit`
  was suggested without a floor, which read as "any 2.x will do". Two of them do not:
  the one-time-code field needs the `alphabet` prop (WireKit 2.22) to accept a
  non-numeric code at all, and its case-folding validation pattern (WireKit 2.26) to
  accept that code in lower case on a page where scripting is unavailable. On an older
  WireKit the boxed field renders a digits-only pattern and silently refuses the code
  this package issues.

- **A one-time code typed in lower case is now accepted by the browser too.** With
  WireKit 2.26 the field's validation pattern carries both cases whenever the alphabet
  is single-case — like the shipped default — so the lower-case normalization no longer
  depends on the field's JavaScript running. It was previously refused by the browser's
  own constraint validation on exactly the strict-CSP page where that JavaScript cannot
  run. Nothing to change in your application; the documentation no longer carries the
  restriction.

## [0.20.0] - 2026-08-03

### Fixed

- **The CSP nonce is found again.** `AutoScriptNonce` looked for a global `csp_nonce()`
  function. That function was introduced in spatie/laravel-csp 2.10.3 and does not exist in
  **any** 3.x release — every 3.x version publishes `autoload.psr-4` and no `autoload.files`,
  so it cannot define a global function at all. Detection therefore returned `null` for every
  application on the current series, the resend countdown's inline script shipped with no
  nonce, and a strict policy blocked it **silently** — the countdown never ran and the button
  stayed live, so a reader who clicked too early met a rejection instead of a visible wait.

  It now reads the `csp-nonce` container binding that package actually registers — the same
  one its own `@cspNonce` directive reads — and keeps the function as a fallback for
  applications that define one themselves. Both are probed by name, so this package still
  references none of that package's symbols and stays installable without it.

  Reported independently by four consuming applications, which is what a failure with no error
  message costs.

- **The WireKit screens can be served under a strict policy at all.** The same nonce now
  reaches three more tags that a strict policy rejects just as hard: the WireKit layout's
  inline `<style>` block — which was this package's own, and had never carried one — and the
  `<link>` and `<script>` WireKit itself writes, both of which accept a nonce as of WireKit
  2.24.0. Under a policy built on `'strict-dynamic'` the nonce is the only thing that grants a
  tag, so before this the screen rendered unstyled with nothing in the logs.

- **The WireKit layout emitted its two script tags in the wrong order.** `@wirekitScripts`
  registers WireKit's Alpine plugins on the `alpine:init` event and `@livewireScripts` is what
  boots Alpine, so WireKit has to come first — WireKit's own installer writes exactly that order
  when it generates a layout. This layout had them reversed, which can cost the one-time-code
  field its auto-advance and its paste distribution while the field still accepts typing one box
  at a time. Nothing caught it because the browser suite asserts the screens render *styled*, and
  styling is CSS; a missed plugin registration is invisible to it. A rendering guard now pins the
  order.

- US spelling on the published surface. Eight British spellings had accumulated across four
  source files, a Blade view and this changelog.

### Changed

- **The one-time-code screen renders the boxed field for every alphabet.** WireKit's
  `otp-input` used to accept digits only and enforced it four ways, so the shipped default
  alphabet — `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, mostly letters — could not be typed into it:
  every keystroke was discarded and the boxes stayed empty. This package worked around that by
  rendering a single monospace field whenever the alphabet contained letters. WireKit 2.24.0
  added an `alphabet` prop that derives the typing filter, the paste filter, the keyboard hint
  and the validation pattern from one value, so the workaround is gone and the boxed field is
  back for everyone. A single-case alphabet is now matched case-insensitively too, so a code
  typed in lowercase is accepted rather than silently refused.

  **If you published `resources/views/vendor/email-magic-link/wirekit/`, republish it** to pick
  this up. Nothing else changes: the plain Blade screens use a single field either way, and the
  flow, routes and validation are untouched.

- **A host that cannot grant `'unsafe-eval'` no longer has to give up the WireKit look.**
  Setting WireKit's own `wirekit.scripts.bundle` to `'csp'` serves a build made against Alpine's
  CSP distribution. The documentation used to say the only option was `ui.mode = 'blade'`; it
  now names both.

### Changed — development only

Nothing in this section changes what the package does at runtime. It is recorded because
`composer.json` and `CONTRIBUTING.md` are part of the published package, so a reader of the
public repository sees the difference.

- **The development toolchain moved to Pest 5**, together with its plugin set (browser,
  Laravel, type-coverage, plus the PHPStan extension, the Rector set, the agent helper and
  the evals plugin). The published requirement is unchanged and still `php: ^8.4` — on
  8.4.0 the runtime tree resolves to the Symfony 8.0 line and installs cleanly. **Working
  on the package now needs PHP 8.4.1 or newer**, because the test toolchain pulls Symfony
  8.1, which requires it. `CONTRIBUTING.md` says so, and names the confusing symptom: on
  exactly 8.4.0 `composer install` fails citing `symfony/process`, never Pest.
- `composer.json` gains a `test:evals` script and drops the finite `process-timeout` in
  favour of `0`. Both are development-side only; no runtime dependency, autoload entry or
  published default moved.

## [0.19.0] - 2026-07-26

### Added

- **`php artisan email-magic-link:doctor`** — compares your published config against the one the
  installed version ships and names every key your file does not mention. Publishing the config
  freezes it at that version; the package still merges its own defaults underneath, so everything
  keeps working, but a setting you cannot see is a setting you cannot tune. One consuming
  application was found running the resend guard on defaults its config file had never heard of.
  It reports keys the package no longer knows too, since a removed key and a typo look identical
  from the file. Exits `0` either way — a drifted config is a thing to read, not a thing to fail a
  deploy on.
- **Optional self-scheduling of the token purge.** Set `prune.schedule = true` and the package
  registers `email-magic-link:purge` in your scheduler (`prune.frequency`: hourly, daily, weekly,
  monthly), so retention no longer needs a line in your own file. Off by default: a package that
  deletes rows on a cadence nobody chose is making your decision, and an application that already
  wires the command itself would end up running it twice.
- **A CSP nonce for the resend countdown.** Its inline `<script>` now carries the application's
  nonce, so a strict Content-Security-Policy no longer blocks it — silently, which is what made
  this hard to notice: the countdown is progressive enhancement, so a blocked script produces no
  error, just a button that never counts down. The bundled default detects `csp_nonce()` with no
  configuration; point `ui.script_nonce` at your own `ScriptNonce` implementation when your nonce
  lives elsewhere.

### Fixed

- **`resend.enabled` was a global kill-switch.** It was checked inside the guard itself, so it
  disarmed *every* key — including the ones your own application guards with the same service,
  which the contract explicitly invites. An operator setting `EMAIL_MAGIC_LINK_RESEND=false` to
  stop magic-link throttling could silently remove flood protection from an unrelated subsystem,
  with no warning and no failing test. The switch now governs this package's request endpoint
  only. If you pinned `resend.enabled` to `true` to work around this, you no longer need to.
- **A published config could not inherit nested defaults it predates.** The merge was shallow, so
  a host that published once received new top-level keys from later releases but never a key added
  inside a block it already had — that key stayed `null` instead of its shipped default, silently.
  Measured on a real upgrade: `routes.intended` left the intended-redirect off, and `ui.styles`
  handed `null` to code expecting a list. Lists still replace wholesale rather than merging, so a
  guard you removed from `guards` cannot come back.
- **`pt-PT` was written in the formal `você` form.** It shipped as a byte-identical copy of the
  base bundle, so the pt-PT/pt-BR split carried none of the difference it exists for. European
  Portuguese now addresses the reader as `tu` throughout, matching every other locale's register;
  `pt-BR` keeps `você`.
- **The WireKit one-time-code screen could not accept the codes this package issues.** WireKit's
  `otp-input` is digits-only and enforces it four ways — typing a non-digit clears the box,
  pasting strips it, `inputmode` is numeric, `pattern` is `[0-9]`. The shipped default
  `code_alphabet` is `ABCDEFGHJKMNPQRSTUVWXYZ23456789`, mostly letters. So on a default install
  the screen erased every character the user typed from the code they had just been mailed, with
  no error to explain it. The boxed field is now used only when the configured alphabet really is
  numeric, where it is correct; otherwise the screen renders a monospace text field, which is what
  the plain Blade screen has always used.

  Nothing is lost by the swap: auto-advance, arrow navigation and paste-distribution are all
  digit-filtered, so none of them ever worked for a letter. This is not a new defect — it has been
  present since the WireKit screens shipped, and it survived because the browser suite renders that
  screen but never entered a code. It does now.
- **The delivery choice on the WireKit request screen had no group label.** With both channels
  enabled, a screen reader announced "Magic link" and "One-time code" with nothing saying what was
  being chosen. It is now a real `fieldset` with a `legend`, matching the plain Blade screen, which
  had it all along.
- **The resend countdown announced itself once a second.** It was a polite live region, and its text
  changes every second, so waiting thirty seconds meant hearing the remaining time read out thirty
  times with no way to interrupt. It is now a timer region: the value is read when the reader
  navigates to it, never on its own. It gains a translatable accessible name in its place —
  a new `resend_countdown_label` string in all eleven bundled locales, which reaches you even if you
  published the translations at an earlier version, because a package's own lines are the base a
  published override merges into.

### Changed

- `en-GB`, `en-US` and `pt-BR` now delegate to their base bundle with a one-line `require` instead
  of holding a duplicate copy, so there is nothing to keep in step by hand. They resolve exactly as
  before. (They are deliberately not removed: Laravel falls back to `app.fallback_locale`, not to
  the base language, so an application whose fallback is `de` would serve German on `en-GB`.)
- **The `ui` config block now states what the WireKit screens need from a
  Content-Security-Policy.** `ui.script_nonce` covers this package's own inline script, which is
  the whole story for the plain screens. The WireKit screens additionally need `'unsafe-eval'` in
  `script-src`, because WireKit's components are driven by Alpine and Alpine compiles its
  expressions with the `Function` constructor — on these screens that is the one-time-code field,
  whose digit boxes stop advancing without it. Like the blocked-script case, it fails silently.
  A host whose policy cannot grant `'unsafe-eval'` should set `ui.mode` to `blade`.

  Comment-only: no key was added, moved or renamed, so a published config keeps working and
  `email-magic-link:doctor` reports no drift.

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
  (the previous behavior, still the default), `view` to render your own error
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
