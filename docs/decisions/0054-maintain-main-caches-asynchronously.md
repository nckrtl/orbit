# ADR 0054: Maintain main caches asynchronously

In the context of independently reviewed feature candidates, facing worktree startup delays from main cache refreshes, we decided for asynchronous monorepo maintenance and against waiting for cache freshness before development or landing, to shorten the delivery loop, accepting delayed discovery of integration failures and occasional repair work.

## Status

Accepted on 2026-09-10. Extends [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md). Supersedes [ADR 0053](0053-use-local-review-checks-for-feature-landing.md) for refreshing main test baselines before worktree creation.

## Context

Worktree creation waits for main test recording even when compatible successful publications exist. Quality caches can disappear with their donor worktrees. The repository owner accepts asynchronous landing and assigns one maintenance owner across the monorepo to recover failed maintenance and integration checks.

## Decision

- Worktree creation must seed compatible successful main caches without waiting for a newer publication.
- The orchestrator must request background cache maintenance after a verified merge without making cache freshness a merge or cleanup gate.
- Repository maintenance must retain pending requests across worker interruption and serialize refreshes across all Composer projects.
- Repository maintenance may combine pending requests when it checks the newest main and preserves unprocessed work.
- Main baseline maintenance owns reusable Pint and PHPStan result publications as well as test dependency graphs.
- Repository maintenance must publish each tool's portable cache only after its check succeeds on clean main and must preserve its previous successful publication on failure.
- One maintenance owner owns recovery across the monorepo; routine cache refreshes use repository scripts.
- The orchestrator must hold unrelated feature merges after an observed correctness-check failure on main until a reviewed repair or revert is verified on main.
- Repair agents must change source in isolated worktrees and return repairs for independent review.

## Rejected alternatives

- Wait for every cache refresh before creating a worktree or landing another feature: rejected because it serializes delivery behind maintenance work.
- Assign a maintenance agent to every project or merge: rejected because overlapping owners can compete for the same checkout and compute resources.
- Treat every warming failure as a source regression: rejected because cache transport and environment failures can leave existing publications usable.

## Consequences

- New worktrees can start with compatible earlier main caches while maintenance catches up.
- Another feature can merge before a previous integration failure is detected.
- Background maintenance needs durable requests, observable failures, and recovery after missed closeout or worker interruption.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md); supersedes [ADR 0053](0053-use-local-review-checks-for-feature-landing.md) for refreshing main test baselines before worktree creation
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: maintenance queue, publication and worktree tests; `composer docs-lint`
