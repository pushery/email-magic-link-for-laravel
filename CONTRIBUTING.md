# Contributing

Thanks for considering a contribution. This package holds itself to a strict quality
bar, and every pull request is expected to keep all of the gates green.

## Local requirements

**PHP 8.4.1 or newer to work on the package, even though the package itself installs on
8.4.0.** The two floors are different on purpose. The published requirement stays `^8.4`
and it is honest: on 8.4.0 Composer resolves the runtime tree to the Symfony 8.0 line and
installs cleanly. The development toolchain does not have that option — Pest 5 and the
current Laravel test stack pull Symfony 8.1, which requires `php >=8.4.1`.

So on exactly 8.4.0 `composer install` fails here with a message naming `symfony/process`
or `phpunit/phpunit`, never Pest. Upgrade the patch version; nothing else is wrong.

## Getting started

```bash
git clone git@github.com:pushery/email-magic-link-for-laravel.git
cd email-magic-link-for-laravel
composer install
```

## Quality gates

All of the following must pass. The aggregate static + test gate is:

```bash
composer qa
```

which runs, and each can be run on its own:

| Command | Gate |
|---|---|
| `composer format:test` | Code style — Laravel Pint, zero diffs (`composer format` to fix). |
| `composer rector:test` | Refactoring — Rector with the PHP 8.4 rule set, dry-run clean (`composer rector` to apply). |
| `composer analyse` | Static analysis — Larastan at `max` level, no errors. |
| `composer test:type-coverage` | 100% type coverage of `src/`. |
| `composer test:coverage` | 100% line coverage of `src/`. |

`composer mutate` (see below) is **not** part of `qa`; it runs separately.

### Tests

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench. The defining axis is
**Fortify present versus absent**: the `Integration` suite boots Fortify, while the
`Unit` and `Feature` suites run the core in isolation and must never reference a Fortify
symbol. CI (the self-hosted Woodpecker gate) runs the suite on PHP 8.4 with `prefer-stable`
and Fortify installed, against real PostgreSQL 18 and MySQL 8.4 at 100 % line and type
coverage; a weekly lane repeats it with `prefer-lowest`. The PHP 8.4/8.5 × prefer-lowest/stable
× with/without-Fortify matrix in `.github/workflows/tests.yml` is a disabled, manual-only
workflow — run it before a release that changes the dependency floor.

The `Postgres` suite verifies the `RETURNING`-based atomic claim against a real
PostgreSQL connection. It is skipped automatically when Postgres is unavailable; to run
it locally, provide a database and set `PG_TEST_HOST`, `PG_TEST_PORT`, `PG_TEST_DB`,
`PG_TEST_USER`, and `PG_TEST_PASSWORD` as needed (defaults target
`127.0.0.1:5432` / `email_magic_link_test` / `postgres`).

The `MySql` suite does the same against a real MySQL 8.4 connection (MariaDB is rejected, not
supported — it clears the version floor numerically and is refused by identity). It reads
`MYSQL_TEST_HOST`, `MYSQL_TEST_PORT`, `MYSQL_TEST_DB`, `MYSQL_TEST_USER` and
`MYSQL_TEST_PASSWORD` (defaults `127.0.0.1:3308` / `email_magic_link_test` / `root`). Set
`REQUIRE_DB_TESTS=1` to turn "skipped when unavailable" into a hard failure, which is how CI
runs both suites.

### Mutation testing

Mutation testing runs through Pest 5's built-in mutation plugin (Infection doesn't support
Pest's function-style tests). It isn't a gate: nothing refuses a release over
the score, and the CI lane runs without a floor at all so a measurement is always recorded.
What the local `composer mutate` enforces is an overall mutation score indicator of at
least 93%, taken from the last serial run rather than chosen; the security-critical paths
— the atomic claim, the entropy guardrail,
and the two-factor handoff — are mutation-tested to effectively 100%, and the residual
gap is made up of equivalent mutants in glue and presentation code (for example the
PostgreSQL/portable claim branches, which are behaviorally identical because SQLite
also supports `RETURNING`). Please do not add assertions whose only purpose is to kill an
equivalent mutant.

## Pull request expectations

- Keep `composer qa` and `composer mutate` green.
- Add tests for behavior changes; the security invariants in `SECURITY.md` must stay
  covered.
- Update `README.md` and `CHANGELOG.md` (`## [Unreleased]`) when behavior or
  configuration changes.
- Keep commits focused and the public API stable, or call out the break explicitly.
