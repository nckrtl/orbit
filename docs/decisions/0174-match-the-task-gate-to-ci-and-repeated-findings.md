---
title: "ADR 0174: Match the task gate to CI and repeated findings"
sidebarTitle: "0174 Match the task gate to CI and repeated findings"
description: "Proposed. Extend the task gate beyond the original PHP projects so touched web and Pi server changes receive the checks CI runs, and turn three repeated review findings into deterministic checks."
---

# ADR 0174: Match the task gate to CI and repeated findings

Orbit's task gate runs the checks that CI runs for each project a change touches, adds a changed-test fallback, and rejects three repeated review findings before a handoff reaches review.

## Status

Proposed.

This amends [ADR 0125](/reference/tasks#project-check) for the checks performed by `composer check` and the `bin/review-check` gate. The handoff, detached-process, tree-integrity, and scheduler rules in ADR 0125 stay unchanged.

## Context

`bin/review-check` knows `apps/cli`, `apps/docs`, `apps/gateway`, `apps/e2e`, and `packages/php-sdk`. GitHub Actions also checks `apps/web`, `apps/pi-server`, `packages/agent-annotation`, and `apps/agent`. A change can therefore pass the local task gate while failing a required CI job that the gate did not run.

A classification of the last 80 `changes_requested` reviews, with 131 findings on 2026-09-27, found that 8% were deterministic-checkable and another 8% were process findings that a gate would have prevented. Six comments (finding ids 157, 348, 512, 520, 522, and 526) repeated web checks that the gate did not run. Three other recurring findings are deterministic: `strtotime()` in PHP projects (464b), inline `@var`, `@phpstan-var`, or `@psalm-var` overrides inside method bodies (422), and tests that call `Classification::fake(...)` without following it with `preventStrayClassifications()` (162).

The gate must provide CI parity where it is useful at handoff without moving expensive cross-compilation into every task workspace. It must also make a changed test visible when test-impact analysis selects no tests, rather than allowing a new or edited test to go unexecuted.

## Decision

The task gate always runs the PHP profile for all five Composer projects, adds the web and Pi server profiles when their paths change, keeps Rust release builds in CI, and rejects the three deterministic review findings.

### Project parity

`bin/review-check` always runs `composer validate --strict`, `composer check`, and `composer test:affected` in all five PHP projects (`apps/cli`, `apps/docs`, `apps/gateway`, `apps/e2e`, and `packages/php-sdk`) for every candidate, matching main's CI coverage. Changed paths add checks; they do not narrow this PHP baseline. A candidate with no runnable checks fails rather than passing vacuously.

When a change touches `apps/web`, the gate runs `bun run check` and `bun run build` from `.github/workflows/ci.yml`, plus a schema freshness check that compares temporary generator output with the submitted schema, matching CI's `bun run types && git diff --exit-code src/api/schema.d.ts` without changing the working tree. The generated type command runs from `apps/web`. Because a handoff may contain unstaged changes, its equivalent is `generated=$(mktemp) && trap 'rm -f "$generated"' EXIT && ./node_modules/.bin/openapi-typescript ../../docs/openapi.json -o "$generated" && cmp -- "$generated" src/api/schema.d.ts`. This compares temporary generator output with the submitted working-tree schema: matching uncommitted changes pass, stale output fails, and the handoff does not stage, commit, or change the submitted tree. The same handoff-safe type check runs when a change touches `docs/openapi.json`, because that file is the input to the generated web API types.

When a change touches `apps/pi-server`, the gate runs that CI job's commands from the project directory: `bun install --frozen-lockfile`, `bun run check` (the package script runs `vp check`), `bun run test`, and `bun run build`.

The Rust agent remains a CI check. Its job installs `cross` and runs `cargo fmt --all -- --check`, `cargo clippy --locked --all-targets -- -D warnings`, `cargo test --locked`, and two release `cross build --locked --release` commands for `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl`. Cross-compilation is not cheap enough to add to every handoff gate. The CI job for agent annotation also remains covered by CI rather than this project gate.

A required tool is a gate prerequisite, not an optional project. If a selected check cannot find a named tool, it fails with that tool's name; it never skips the check silently. This applies to tools such as `bun`, `git`, `cargo`, and `cross`. Dependency installation failures are also gate failures and retain the command that failed.

### Changed tests

After `test:affected`, the gate compares the changed PHP test files with the files Pest selected. Every changed Pest test file that was not selected runs by its path, with test-impact analysis disabled for that run. The gate first runs Pest with `--list-tests` for that path. If Pest does not discover the changed test file, the gate fails instead of reporting a passing empty run. A changed test therefore either appears in the affected selection or has an explicit path run that proves Pest discovered it.

### Finding checks

The gate adds these checks to every PHP project:

| Finding | Check | Tool |
| --- | --- | --- |
| 464b: `strtotime()` in PHP project code | Reject calls to `strtotime()` and use Carbon parsing instead | `phpstan-disallowed-calls` |
| 422: inline `@var`, `@phpstan-var`, or `@psalm-var` override inside a method body | Reject the override; fix the declared or inferred type at its source | A PHPStan rule |
| 162: `Classification::fake(...)` without `preventStrayClassifications()` | Reject a test unless the fake setup is followed by `preventStrayClassifications()` | A dedicated script in `bin/` |

The static checks run as part of the gate's PHP quality checks, and the classification script runs against the changed tests. A finding fails the gate with its file and line so the implementer can fix it before review.

## Rejected alternatives

- Keep the gate limited to its five original Composer projects: rejected because web and Pi server changes can pass handoff while required CI jobs fail, and repeated review comments show the cost of that gap.
- Run every CI job for every task: rejected because project selection keeps unrelated work fast and Rust cross-compilation is expensive.
- Leave changed-test selection as a warning: rejected because an edited test that Pest does not discover gives false confidence.
- Ask the reviewer to remember the three findings: rejected because each is deterministic and can fail before reviewer time is spent.
- Skip a selected check when its tool is absent: rejected because a green gate must mean the check ran, not that the workspace happened to lack its tool.

## Consequences

- A task touching web, Pi server, or the OpenAPI schema receives deterministic checks that match the selected CI commands before review.
- The task gate reports missing tools and failed generated-file diffs instead of silently narrowing coverage.
- Rust agent release builds remain a required CI responsibility and can still fail after a task gate passes.
- Changed Pest files cannot disappear behind test-impact analysis.
- PHP projects gain three reusable checks, but existing code and tests may need cleanup before the first gate using the pack passes.
- The gate takes longer for JavaScript changes because it installs dependencies and runs project tests and builds where the selected profile requires them.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, apps/web, packages/php-sdk
- ADRs: [ADR 0125](/reference/tasks#project-check)
- Detail: [Tasks](/reference/tasks), [CI workflow](https://github.com/nckrtl/orbit/blob/main/.github/workflows/ci.yml), and `bin/review-check`
- Verify: `composer check`, `composer docs-lint`, `composer docs-build`, changed-Pest-file tests with `--list-tests`, the web and Pi server command profiles, missing-tool failures, the generated schema diff, and each of the three finding checks
