# Tech Stack

This page records the implementation technologies that shape current Orbit
development. Product behavior remains governed by accepted ADRs and the owning
project's contracts.

## Runtime

Repository projects run on PHP 8.5 with Composer 2. Managed Laravel application
runtimes use the pinned Sury package source and PHP-FPM policies documented in
[PHP runtime defaults](reference/php-runtime.md).

Orbit-managed Ubuntu Nodes use native operating-system services and protected
files where the owning feature requires them. Runtime-specific proof uses the
repository's Incus harness.

## Frameworks

- `apps/gateway` uses Laravel 13 for the HTTP control plane.
- `apps/cli` uses Laravel Zero 13 for the command-line client.
- `apps/docs` uses Laravel 13 and Librarian for console-only documentation
  verification.
- `packages/php-sdk` remains framework-neutral PHP.
- Pest provides automated tests, Mago provides formatting, linting, and static
  analysis, and Rector provides automated refactoring checks.

## Storage

The Gateway uses SQLite for durable Orbit-owned control-plane state. Each
project keeps an independent Composer lock file and verification configuration.

Documentation is stored as Markdown under root `docs/`. The generated JSON
context index is committed derived state and contains no separate product
intent.

## Infrastructure

Incus provides disposable development and proof topologies. Caddy, WireGuard,
systemd, PHP-FPM, and role-specific services form managed Node infrastructure
where accepted feature contracts require them.

GitHub Actions installs and verifies each Composer project independently. Root
scripts coordinate bootstrap, the full Pest suites, documentation context, and
repository-wide checks without combining project dependency boundaries.
