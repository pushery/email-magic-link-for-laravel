# Contributing

Thanks for considering a contribution. Issues and pull requests are both welcome, and the
section on pull requests says how a change sent here reaches a release.

## Reporting an issue

Use the GitHub issue templates (bug report / feature request). Include the package
version and a minimal reproduction, and never paste secrets or credentials.

## Pull requests

This repository is a mirror of the released tree, and every release replaces its contents
with the tree it releases. A pull request is therefore not merged here: a maintainer carries
an accepted change into the development repository, and it reaches this mirror with the
next release.

- Keep the public API stable, or call out the break explicitly.
- Describe the behavior change and how to reproduce it. **This repository ships no test
  suite** — see below — so a pull request here cannot carry one; the tests are written
  alongside your change in the development repository.
- Update `README.md` and the `CHANGELOG.md` `## [Unreleased]` section.
- Keep each commit focused.

## Local requirements

**PHP 8.4.1 or newer to work on the package, even though the package itself installs on
8.4.0.** The two floors are different on purpose. The published requirement stays `^8.4`
and it is honest: on 8.4.0 Composer resolves the runtime tree to the Symfony 8.0 line and
installs cleanly. The development toolchain does not have that option — Pest 5 and the
current Laravel test stack pull Symfony 8.1, which requires `php >=8.4.1`.

So on exactly 8.4.0 `composer install` fails here with a message naming `symfony/process`
or `phpunit/phpunit`, never Pest. Upgrade the patch version; nothing else is wrong.

## Quality bar

This package holds itself to a strict quality bar — Laravel Pint, Larastan at `max`,
Rector, and Pest with 100% line and type coverage, plus mutation testing, a
real-browser end-to-end suite, and cross-engine tests against real PostgreSQL and
MySQL 8.4 (the engines it runs on in production).

**That bar runs in the development repository, not here.** This repository is a
read-only mirror of the released tree: it carries the package itself — the source, its
configuration, migrations, routes, views and translations — and deliberately not the test
suite, the task runner or the CI configuration. So none of the commands above exist in a
clone of this repository, and there is nothing here for you to run them against.

What that means in practice: send the change with a clear description and a minimal
reproduction. It is rerun against the full bar on the way in, and the tests for it are
written there. Saying plainly what you could not verify is more useful than a claim that
sounds checked.

## The Boost skill

`resources/boost/skills/email-magic-link-for-laravel/SKILL.md` ships in the Composer dist, and
Laravel Boost hands it to the agents working inside a consuming application. It covers adoption
only: install, configure, apply the public API. Package internals belong on the documentation
portal, and a rule about how to write the skill belongs here, not in it.
