---
name: orbit-cli-development
description: Use when changing the thin Orbit Laravel Zero commands, options, request mapping, output envelopes, configuration, or local-only operator actions.
---

# Orbit CLI Development

This repository is the thin operator client. Normal commands translate input
to typed `orbit-php-sdk` requests and render gateway responses.

## Boundaries

- Keep the CLI stateless except for explicit files under `$ORBIT_HOME`.
- Send remote operations through `orbit-php-sdk` to the gateway.
- Do not add remote SSH execution, infrastructure business logic, an Agent,
  hidden transport, a generic executor, or automatic migrations.
- Local-only actions can make visible OS changes only when the command contract
  requires them, such as root-CA trust.
- Preserve request IDs and structured errors end to end.
- Before adding command or OS-adapter behavior, inspect matching in-repository
  code and tests. The legacy project can provide optional research when it is
  available, but it is not a checkout dependency. Port only behavior that fits
  the simplified command surface.

## Required Skills

- Read `command-designer` before changing command behavior.
- Read `spatie-laravel-php` for PHP and Laravel Zero changes.
- Read `pest-testing` before changing tests.
- Read `spatie-security` for configuration, credentials, certificates, local
  privilege, or network behavior.

## Verification

Run focused Pest tests while developing. Then run `composer test`,
`composer test:full`, `composer check`, and `git diff --check -- apps/cli` from
the monorepo root. Coordinate SDK or Gateway contract changes in the same
feature branch.
