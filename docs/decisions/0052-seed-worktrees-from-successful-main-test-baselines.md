# ADR 0052: Seed worktrees from successful main test baselines

In the context of feature worktrees with separate Composer projects, facing repeated test dependency recording in each new worktree, we decided for published main baselines and private worktree copies and against sharing writable caches or promoting feature caches, to reduce local test time, accepting background maintenance and occasional recording runs.

## Status

Accepted on 2026-09-10. Extends [ADR 0051](0051-select-discovery-only-feature-delivery.md).

## Context

Test impact analysis needs a recorded dependency graph before it can select affected tests. Separate worktree caches protect concurrent features but repeat that recording. A feature graph can contain unmerged code, failed tests, or working edits, so it cannot establish the reusable main baseline.

## Decision

- Main baseline maintenance owns the reusable test dependency graph for each Composer project.
- Maintenance must publish a baseline only after a successful test run on a clean checkout of main, with its commit and tooling compatibility recorded.
- Worktree setup must seed an absent private cache from a compatible published main ancestor when one exists.
- Feature worktrees must keep cache writes private and must not promote their mutable graphs into the main baseline.
- Closeout must queue serialized baseline maintenance after a merge without making its completion a merge or cleanup gate.
- A failed refresh must preserve the last successful publication.
- Test impact analysis must not replace focused acceptance tests or full continuous integration suites.

## Rejected alternatives

- Share one writable graph across worktrees: rejected because concurrent features can overwrite each other's dependencies and results.
- Promote the completed feature graph: rejected because its contents do not prove the merged main state.
- Record every new worktree from scratch: rejected because unchanged projects repeat the full recording run.

## Consequences

- New worktrees can select affected tests using previously recorded main dependencies.
- Maintenance needs a separate checkout, installed dependencies, and a coverage driver.
- Missing or incompatible publications still require recording, and a failed refresh can leave a project without a reusable baseline.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0051](0051-select-discovery-only-feature-delivery.md)
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: TIA cache lifecycle and worktree cleanup tests; `composer docs-lint`
