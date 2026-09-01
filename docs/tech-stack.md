# Tech Stack

Orbit is a PHP monorepo. Each application or package has its own dependencies,
tests, and Composer lock file.

## PHP applications

All projects run on PHP 8.5 and use Composer 2.

- `apps/gateway` is a Laravel 13 application.
- `apps/cli` uses Laravel Zero 13.
- `apps/docs` uses Laravel 13 and Librarian.
- `packages/php-sdk` is a plain PHP package with no Laravel dependency.

## Managed machines

Orbit manages Ubuntu Nodes. It uses the Sury packages for PHP and runs services
with systemd. [PHP runtime defaults](reference/php-runtime.md) lists the
versions and settings used on those machines.

Caddy handles HTTP and HTTPS traffic. WireGuard provides the private network
between Nodes. Orbit uses native files and services where they are a better fit
than a container.

## Data

The Gateway stores its data in SQLite. Application data stays with the
application and is not stored in the Gateway database.

Documentation is Markdown in the root `docs/` directory. A generated JSON file
helps tools find relevant pages.

## Development and testing

- Pest runs the automated test suites.
- Mago formats, lints, and analyzes PHP code.
- Rector checks automated refactors.
- Librarian checks documentation.
- Incus creates temporary Linux machines for end-to-end testing.

GitHub Actions tests each project separately. The scripts at the repository
root make it easy to install and test everything together.
