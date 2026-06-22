# Contributing

Thanks for considering a contribution. This package holds itself to a strict quality
bar, and every pull request is expected to keep all of the gates green.

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
| `composer mutate` | Mutation testing (see below). |

### Tests

The suite uses [Pest](https://pestphp.com) and Orchestra Testbench. The defining axis is
**Fortify present versus absent**: the `Integration` suite boots Fortify, while the
`Unit` and `Feature` suites run the core in isolation and must never reference a Fortify
symbol. CI exercises both axes across PHP 8.4/8.5, the lowest and highest dependency
resolutions, and with and without Fortify.

The `Postgres` suite verifies the `RETURNING`-based atomic claim against a real
PostgreSQL connection. It is skipped automatically when Postgres is unavailable; to run
it locally, provide a database and set `PG_TEST_HOST`, `PG_TEST_PORT`, `PG_TEST_DB`,
`PG_TEST_USER`, and `PG_TEST_PASSWORD` as needed (defaults target
`127.0.0.1:5432` / `email_magic_link_test` / `postgres`).

### Mutation testing

Mutation testing runs through Pest 4's built-in mutation plugin (Infection does not
support Pest 4's function-style tests). The gate is an overall mutation score indicator
of at least 85%; the security-critical paths — the atomic claim, the entropy guardrail,
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
