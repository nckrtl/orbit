# Orbit PHP SDK

Saloon 4 client for the Orbit gateway API.

## Repository bootstrap

Read [the repository rule index](.ai/rules/index.md) before you change or
review package files. Treat a missing index, a missing indexed rule, or missing
path and policy coverage as a repository-bootstrap failure. Repair the
guidance and run `composer guidance:check`; do not silently skip it.

The `.ai/rules/` tree contains repository instructions only. It does not imply
Laravel or Boost support.

## Package boundary

- Use PHP 8.5 with strict types.
- Follow the Spatie PHP coding guidelines.
- Keep this SDK framework-neutral. Laravel Boost is deliberately required in
  `orbit-gateway` and `orbit-cli`, not in this package.
- Keep the public API typed and small.
- Model only commands exposed by the simplified gateway.
- Implement typed Saloon request and DTO transport only. Keep business logic,
  validation policy, and remote execution outside this repository.
- Keep ordinary transport, envelopes, exceptions, responses, and test fakes
  reusable. `GatewayRootCaClient` is deliberately one-shot after each
  safe-origin fetch attempt. Create a fresh client for a retry.
- Keep `verify=false` inside that one root-CA bootstrap invocation. A
  `FetchRootCaCertificateRequest` sent through a normal connector must inherit
  the connector's configured CA verification.
- Preserve structured gateway error codes, safe messages, details, and request
  IDs. Redact credentials from URLs, nested payloads, defaults, exception text,
  and debug output.
- Review the matching `/home/nckrtl/orbit-old` SDK and Saloon request,
  exception, correlation, and test patterns before inventing transport
  behavior.
- Use Pest 5 with `describe()` and `it()`.
- Use Pest 5 TIA for the development loop and a no-TIA full-suite milestone.
- Run Mago format, lint, and analysis, Rector, and `git diff --check` before
  handoff.
