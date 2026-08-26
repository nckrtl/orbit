---
name: orbit-cli-development
description: Use when changing the thin Orbit Laravel Zero commands, options, request mapping, output envelopes, configuration, or local-only operator actions.
---

# Orbit CLI Development

This repository is the thin operator client. Normal commands translate input
to `orbit-php-sdk` requests and render gateway responses.

## Boundaries

- Keep the CLI stateless except for explicit files under `$ORBIT_HOME`.
- Send infrastructure commands through `orbit-php-sdk` to the gateway.
- Do not add remote SSH execution, infrastructure business logic, an Agent,
  hidden transport, generic script endpoint, or automatic migrations.
- Local-only actions can make visible OS changes when the command contract
  requires them, such as root-CA trust or one-time macOS setup.
- Preserve request IDs and structured errors end to end.
- Before adding a command, search `~/orbit-old` for proven input, output,
  idempotency, and OS-adapter tests. Port only the simplified command surface.

## Required skills

- Read `spatie-laravel-php` for PHP and Laravel Zero changes.
- Read `pest-testing` before changing tests.
- Read `spatie-security` for configuration, credentials, certificates, local
  privilege, or network behavior.

## Verification

```bash
composer test
composer check
composer test:full
```

When the HTTP contract changes, also run the focused SDK and gateway API tests.
