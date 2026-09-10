# ADR 0053: Use local review checks for feature landing

In the context of feature review with reusable main test baselines, facing repeated full GitHub test runs after local verification, we decided for a local quality gate that reviewers run across all projects and against mandatory GitHub checks, to reduce merge delay, accepting reliance on the review host and test impact analysis.

## Status

Accepted on 2026-09-10. Extends [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md). Supersedes [ADR 0049](0049-keep-delivery-artifacts-off-the-merge-head.md), [ADR 0051](0051-select-discovery-only-feature-delivery.md) and [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md) for mandatory continuous integration checks.

## Context

GitHub repeats full suites even when local test impact analysis can reuse main's recorded dependencies. The repository owner disabled the GitHub workflow and selected independent local review as the delivery gate. New worktrees need current main and compatible test baselines before feature work starts.

## Decision

- The independent reviewer must run the local quality gate across every Composer project on the exact clean candidate before approval.
- The local gate must run each project's quality checks and affected tests, and must reject a failed check or a candidate that changes during the run.
- The reviewer must retain the tested candidate, per-project results, and gate receipt with the review assessment.
- Feature delivery must retain focused acceptance tests and independent code review.
- Merge must require the local gate and review for the exact candidate and must not require GitHub checks.
- Worktree creation must fetch main, fast-forward the clean primary checkout, and refresh main test baselines before seeding the new worktree.
- The orchestrator must preserve unrelated primary edits and must not start a new feature from stale or dirty primary main.

## Rejected alternatives

- Require both local checks and full GitHub suites: rejected because it retains duplicate verification and merge delay.
- Approve after focused developer tests alone: rejected because other Composer projects would have no review-stage quality check.
- Reuse an earlier candidate's approval: rejected because its tests do not assess the submitted changes.

## Consequences

- A successful local review can proceed directly to merge without waiting for GitHub test jobs.
- Review depends on local dependencies, environment, and the experimental test impact analysis runner; it provides less environmental diversity than hosted continuous integration.
- Cold or incompatible baselines can still require a full local recording run, and main baseline refresh can delay the next worktree's setup.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md); supersedes [ADR 0049](0049-keep-delivery-artifacts-off-the-merge-head.md), [ADR 0051](0051-select-discovery-only-feature-delivery.md) and [ADR 0052](0052-seed-worktrees-from-successful-main-test-baselines.md) for mandatory continuous integration checks
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: local review gate and current-main worktree tests; `composer docs-lint`
