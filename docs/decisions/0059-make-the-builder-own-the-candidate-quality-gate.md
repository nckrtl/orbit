# ADR 0059: Make the Builder own the candidate quality gate

In the context of local feature delivery, facing deterministic quality failures that consume independent review rounds, we decided for a candidate quality gate that the Builder runs and the reviewer validates and against repeating the same gate during review, to shorten correction and landing time, accepting that review relies on the repository-produced receipt from the Builder's host.

## Status

Accepted on 2026-09-10. Extends [ADR 0053](0053-use-local-review-checks-for-feature-landing.md). Supersedes ADR 0053 for ownership of local quality gate execution.

## Context

[ADR 0053](0053-use-local-review-checks-for-feature-landing.md) moved the cross-project quality gate from GitHub to the independent reviewer. Failed affected tests now consume a reviewer dispatch before the Builder receives deterministic feedback. Running the same gate again during review would duplicate local work without adding environment diversity.

## Decision

- The Builder must run the local quality gate across every Composer project on the exact clean candidate before implementation handoff.
- The Builder must retain the tested candidate, per-project outcomes, and gate receipt with the implementation handoff.
- The orchestrator must reject a missing, failed, changed-tree, or stale Builder gate receipt before independent review.
- A corrected candidate must have its own successful Builder gate receipt before independent review.
- The independent reviewer must validate the Builder gate receipt against the submitted candidate.
- The independent reviewer must review the product changes, acceptance evidence, documentation impact, and required discovery or proof evidence before approval.
- The independent reviewer must not repeat the quality gate across projects solely to approve a candidate.
- The independent reviewer may run focused checks when they are needed to investigate a possible finding.
- Merge must require the successful Builder gate receipt and independent approval for the exact candidate.

## Rejected alternatives

- Keep the gate in independent review: rejected because deterministic failures consume a reviewer dispatch and correction round before reaching the Builder.
- Run the gate in both implementation and review: rejected because every successful candidate would repeat the same checks on local hosts without adding environment diversity.
- Approve after focused Builder tests alone: rejected because affected tests and quality checks in other Composer projects would not gate the candidate.

## Consequences

- Independent review starts only after the candidate has passed the repository-wide quality contract.
- Deterministic gate failures return directly to the Builder before a reviewer is created.
- Independent review no longer executes the repository-wide quality command and relies on the exact-candidate receipt produced on the Builder's host.
- The delivery controller, role guidance, and implementation-loop reference must change before this decision ships.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0053](0053-use-local-review-checks-for-feature-landing.md); supersedes ADR 0053 for ownership of local quality gate execution
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: agent-role contract tests and local-gate receipt tests; `composer docs-lint`
