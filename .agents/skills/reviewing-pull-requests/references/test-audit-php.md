# PHP discovery and validation

## Establish the environment

Orbit has five Composer projects: `apps/cli`, `apps/gateway`, `apps/docs`, `packages/php-sdk`, and `apps/e2e`. Read each project's manifests, lockfile, Pest bootstrap, PHPUnit configuration, and guidance. Confirm versions from the lockfile and installed packages. The repository uses a Pest fork; do not infer supported flags from upstream documentation alone.

Run `composer guidance:check` where required. If dependencies are missing, install from the lockfile before interpreting failures. Do not overwrite modified guidance to fix an absent `vendor/` directory. Keep environment failures separate from test failures.

Inventory declarations, expanded datasets, helpers, fixtures, architecture tests, excluded groups, and separate PHPUnit configurations. Use runner discovery once dependencies are available; filename or regex counts are only an initial inventory. In E2E, ordinary unit and feature tests verify the harness. Cold and snapshot scenarios use separate configurations and exercise infrastructure.

## Find candidates

Start with `rg --files` and targeted `rg` searches, excluding vendor, generated caches, and dependencies. Search for reflection, file reads, source-string assertions, mock expectations, skips, and retired terms. Read matches in context. A search result is not a finding.

Use PHPStan or Larastan findings as supporting evidence, not a complete reachability graph. Inspect these indirect callers before marking PHP code dead:

- Container bindings, providers, aliases, contextual bindings, and callable arrays.
- HTTP routes, middleware, policies, command discovery, schedules, events, and listeners.
- Eloquent relations, accessors, casts, scopes, observers, factories, and serialized class names.
- Configuration, Composer autoload files, package discovery, reflection, generated registries, and external scripts.
- SDK consumers in CLI and other packages, published requests and DTOs, and public testing helpers.
- Historical migrations and compatibility paths that existing installations can still require.

Distinguish an unused class from an unreachable branch inside a used class. Trace the branch's inputs and preconditions. No test coverage does not imply no production use; only test callers do not imply an unnecessary API.

## Judge test layers

For Gateway, retain authentication, directed node access, ownership, validation wiring, persisted state, redaction, rollback, and error-envelope evidence at the boundaries that handle them. Do not introduce generic tenant, role, or permission assumptions from framework examples.

For CLI, distinguish SDK request construction, exit status, output streams, terminal behavior, and pipe behavior. Rendering assertions may be real public contracts. For SDK, Saloon fakes should exercise real requests and DTO mapping; exact verbs, paths, headers, omitted versus empty input, request IDs, TLS, and redaction remain meaningful contracts.

For Docs, separate generator and lint behavior from checks that freeze prose. Generated API or MCP schema checks can detect drift that a runtime endpoint test cannot. For E2E, preserve topology ownership, safe cleanup, process failures, and real integration behavior separately from harness unit tests.

Prefer datasets for cases sharing setup and assertions. Move duplicate validation matrices to their owner only when a retained request test still proves the wiring and error response. Do not consolidate superficially similar security failures that exercise different guards.

## Validate

Use `composer test:affected` and `composer check` in changed PHP projects. Follow project rules on filtered runs; Gateway guidance requires TIA development runs and forbids ad hoc path/filter selection. Required architecture gates and explicit fresh full runs have different purposes. Check `.github/workflows/ci.yml` and `bin/review-check` for those invocations rather than silently changing test scripts.

For a fresh baseline or final full run, confirm the installed runner supports the CI flags. Orbit CI uses `--tia --fresh` to discard the selection graph and execute every test in the selected configuration. Preserve plain output through `bin/pest-plain` and use the project's process limit. From a PHP project, a typical two-process run is:

```bash
../../bin/pest-plain vendor/bin/pest --parallel --processes=2 --tia --fresh --compact --colors=never
```

Run every relevant configuration and separately gated group; a default full run does not imply privileged or Incus scenarios executed. Record counts, failures, skips, duration, coverage-driver availability, configuration, and whether TIA selected or actually executed tests. PCOV or Xdebug is needed for TIA; do not follow generic performance advice to disable the driver while relying on TIA.

Run `git diff --check`. Follow the documentation skill for prose and generated context changes. Root `composer check` is Orbit's cross-project gate; report missing dependencies or environment limits without claiming a pass. Avoid installing new audit or mutation dependencies when existing tooling and a controlled mutation suffice.
