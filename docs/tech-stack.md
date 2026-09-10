# Tech Stack

Orbit is built as several PHP projects in one repository. Each application or
package keeps its own dependencies, tests, and Composer lock file.

## PHP applications

Every project requires PHP 8.5 and uses Composer. Each project's `composer.json` pins its own framework, so that file is the source for the version in use.

- `apps/gateway` is a Laravel 13 application.
- `apps/cli` uses Laravel Zero 13.
- `apps/docs` uses Laravel 13 and Librarian.
- `packages/php-sdk` uses Saloon for its HTTP connector and request classes. It
  does not depend on Laravel.

## Managed machines

Orbit supports Ubuntu 26.04 Resolute Nodes. Ubuntu 24.04 is unsupported, including for roleless operator clients. It installs PHP from the Sury apt source that `apps/gateway/app/Infrastructure/Nodes/RemotePhpPackageManager.php` pins and manages services with systemd. The [PHP runtime defaults](reference/php-runtime.md) page lists the settings Orbit publishes on those machines.

Caddy handles HTTP and HTTPS traffic. WireGuard provides the private network
between Nodes. Orbit runs these services directly instead of putting everything
in containers.

## Data

The Gateway stores Orbit's data in SQLite. Your applications continue to manage
their own data.

Documentation is Markdown in the root `docs/` directory. A generated JSON file
helps humans and agents find pages about the part of Orbit they are working on.

## Development and testing

Orbit uses these tools to keep its code and documentation consistent:

- Pest runs the automated test suites.
- Laravel Pint formats PHP code and checks syntax and style.
- Larastan analyzes the Laravel applications. PHPStan analyzes the framework-neutral SDK.
- Rector checks PHP refactoring rules.
- Librarian checks documentation.
- Incus creates temporary Linux machines for end-to-end testing.

Project scripts run focused tests and local quality checks. An independent reviewer runs the root `composer check` gate across every project with test impact analysis before approval. GitHub continuous integration is disabled.
