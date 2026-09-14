---
title: "Tech stack"
description: "The PHP projects, managed-machine platform, data stores, and development tools that make up Orbit."
---

# Tech stack

Orbit contains separate PHP projects in one repository. Each has its own dependencies, tests, and Composer lock file.

## PHP applications

Every project requires PHP 8.5 and uses Composer. Its `composer.json` records the framework version.

- `apps/gateway` is a Laravel 13 application.
- `apps/cli` uses Laravel Zero 13.
- `apps/docs` uses Laravel 13 and Librarian.
- `packages/php-sdk` uses Saloon for HTTP requests, without Laravel.
- `apps/e2e` provides the Incus test harness.

## Managed machines

Orbit supports Ubuntu 26.04 Resolute Nodes. Ubuntu 24.04 is unsupported, including for operator clients without roles. Orbit installs PHP from a pinned Sury apt source and manages services with systemd. See [PHP runtime defaults](/reference/php-runtime) for the settings.

Caddy 2.6 or newer handles HTTP and HTTPS traffic. WireGuard provides the private network between Nodes. Orbit runs these services directly instead of putting everything in containers.

## Data

The Gateway stores Orbit's data in SQLite. Applications manage their own data.

Mintlify publishes Markdown and MDX pages from `docs/`. A generated JSON index helps contributors and agents find pages for each component or concept.

## Development and testing

Orbit uses these tools to keep its code and documentation consistent:

- Pest runs the automated test suites.
- Laravel Pint formats PHP code and checks syntax and style.
- Larastan analyzes the Laravel applications. PHPStan analyzes the framework-neutral SDK.
- Rector checks PHP refactoring rules.
- Librarian checks documentation.
- Incus creates temporary Linux machines for end-to-end testing.

Project scripts use test impact analysis (TIA) to run affected tests alongside local quality checks. The [implementation loop](/reference/implementation-loop#candidate-quality-gate) explains checks and review. GitHub continuous integration is disabled.
