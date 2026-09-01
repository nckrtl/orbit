# Tech Stack

Orbit is built as several PHP projects in one repository. Each application or
package keeps its own dependencies, tests, and Composer lock file.

## PHP applications

All projects run on PHP 8.5 and use Composer 2.

- `apps/gateway` is a Laravel 13 application.
- `apps/cli` uses Laravel Zero 13.
- `apps/docs` uses Laravel 13 and Librarian.
- `packages/php-sdk` is a plain PHP package with no Laravel dependency.

## Managed machines

Orbit manages Ubuntu Nodes. It installs PHP from the Sury packages and manages
services with systemd. The [PHP runtime defaults](reference/php-runtime.md)
page lists the exact versions and settings used on those machines.

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
- Mago formats, lints, and analyzes PHP code.
- Rector checks PHP refactoring rules.
- Librarian checks documentation.
- Incus creates temporary Linux machines for end-to-end testing.

GitHub Actions tests each project separately. Scripts at the repository root
let you install and test everything together.
