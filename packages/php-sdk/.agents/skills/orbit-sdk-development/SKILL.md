---
name: orbit-sdk-development
description: Use when changing Orbit gateway request objects, Saloon connectors, response envelopes, exceptions, fakes, or API contract alignment between the gateway and CLI.
---

# Orbit SDK Development

This repository is the small Saloon client for the Orbit gateway API. The CLI
loads it through a Composer path repository during development.

Use PHP 8.5 with strict types. Follow the Spatie PHP coding guidelines.

It is framework-neutral. Laravel Boost is deliberately required in
`orbit-gateway` and `orbit-cli`, not in this SDK.

## Boundaries

- Put request classes in `src/Requests/`.
- Implement typed Saloon requests, response DTOs, envelopes, exceptions, and
  fakes only. Do not add business logic, validation policy, or remote execution.
- Keep gateway HTTP handlers and all business logic in `orbit-gateway`.
- Keep CLI presentation and local trust-store actions in `orbit-cli`.
- Model only the simplified gateway's public API. Do not restore legacy Agent,
  executor, Docker, Swarm, or unused command surfaces.
- Preserve `X-Orbit-Request-Id` across every command request.
- Preserve the gateway's structured error code, safe message, details, and
  request ID.
- Redact credentials recursively from URL userinfo and sensitive query values,
  nested request or response payloads, app defaults, exception text, previous
  transport errors, and debug output.
- Keep ordinary transport, envelopes, exceptions, and test fakes reusable and
  typed. `GatewayRootCaClient` is deliberately one-shot after each safe-origin
  fetch attempt. Create a fresh client for a retry.
- Keep `verify=false` inside that one root-CA bootstrap invocation. A
  `FetchRootCaCertificateRequest` sent through a normal connector must inherit
  the connector's configured CA verification.

## Contract workflow

1. Read the matching gateway route, request, resource, and feature test.
2. Read the matching `/home/nckrtl/orbit` SDK implementation and tests, then
   inspect the installed Saloon request, exception, and faking patterns. Reuse
   proven transport invariants, but do not restore retired architecture.
3. Add or change the SDK request with the smallest public surface.
4. Add a Pest test for the exact method, path, payload omission, correlation
   header, success envelope, structured failure, and redaction boundary.
5. Verify matching gateway and CLI behavior only when repository integration is
   authorized.

## Verification

```bash
composer test
composer check
composer test:full
vendor/bin/mago format --check
vendor/bin/mago lint src tests --reporting-format=medium
vendor/bin/mago analyze src --reporting-format=medium
vendor/bin/rector process --dry-run
git diff --check
```

Use Pest 5 with `describe()` and `it()`. Use Pest 5 TIA for the normal
development loop. Run a no-TIA full suite before handoff. Run focused gateway
API and CLI tests when a contract changes and the integration gate permits work
in those repositories. Run Mago format, lint, and analysis and Rector before
handoff.
