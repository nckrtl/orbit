# ADR 0007: Use the nine-step feature flow

## Status

Accepted on 2026-08-30. Amended on 2026-08-31 to add a lightweight reviewed
implementation preflight inside step 2. Supersedes
[ADR 0006](0006-topology-led-feature-development.md) and the development-flow
parts of [ADR 0005](0005-rolling-incus-development-topology.md). ADR 0005's
rolling standby and ADR 0002's production-separation principle remain in
force.

## Context

ADR 0006 delivered disposable topologies but wrapped them in ceremony: a
fourteen-step flow, YAML handoffs, gate checklists, evidence archives under
`~/.local/state`, a rolling acceptance suite, and proof plans committed inside
the harness. A dozen issues through that flow showed the ceremony cost more
than the topologies and blurred who owns what. The requirement is the smallest
flow that still proves every change on a fresh machine.

The first nine-step feature runs also showed two narrower problems. First, the
repository called the admission state Ready even though the NCK Linear workflow
has no Ready status; `Todo` is therefore the canonical Ready-equivalent and may
contain only complete contracts. Second, implementation
could begin before code boundaries and focused proof were independently
checked. Missing assumptions then surfaced during PR review, causing repeated
implementation and review rounds. The correction must catch that uncertainty
before coding without restoring the removed state machinery.

## Decision

A change with `Proof: incus` follows nine steps, written in full in
[development-workflow](../reference/development-workflow.md). Automated-only
changes run steps 1, 2, 5, 7, 8 (CI green instead of a proof), and 9 without
promote. Harness changes are covered under Ownership.

1. Linear issue in `Todo`, Orbit's canonical Ready-equivalent because the team
   has no separate Ready status (`Proof: incus` when a real machine is needed).
2. Worktree from `main` plus one gitignored `.orbit/plan.md`. A fresh planner
   maps criteria to code boundaries, implementation order, invariants, and
   focused proof. A fresh independent reviewer records `PASS`, `FIX`, or
   `BLOCK`. One `FIX` correction cycle is allowed; implementation starts only
   on `PASS`.
3. Fresh topology from the standby snapshot, worktree mounted.
4. One implementer gets it right on the topology (`shell`, edit, run), following
   the approved order. Bounded subagents are optional; per-increment agents and
   commits are not required.
5. Codify with tests; commit.
6. Merge `main`; prove the commit on a fresh topology with
   `proofs/<ISSUE>.json`.
7. Short pull request.
8. A fresh reviewer merges `main`, re-proves, reports all blocking findings in
   one pass, and approves. Findings must be defects against the issue, ADR,
   existing invariants/tests, or repository rules.
   New requirements become separate work.
9. Merge; the reviewer's topology becomes the standby generation
   (`bin/e2e-standby promote`, fallback `refresh`); worktree removed; issue
   closed.

Where things live:

- Implementation preflight: `<worktree>/.orbit/plan.md`, gitignored and deleted
  with the worktree.
- Proof plans: `proofs/<ISSUE>.json` (+ `proofs/<ISSUE>/` fixtures), committed
  with the PR.
- Per-issue harness state: `<worktree>/.e2e/`, deleted with the worktree.
- Promoted standby generation: `<primary>/.e2e/standby/`, until the next
  promote.

Ownership:

- Tom owns role dispatch and lifecycle transitions; there is no nested feature orchestrator.
- The same implementer owns the feature and requested-change corrections.
- Feature branches never modify the harness (`apps/e2e`, `bin/e2e-*`, except
  `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`).
- Harness changes are their own issues; their proof is `bin/e2e-live <sha>`,
  one end-to-end run of the feature flow against a standby built from the
  candidate.

Still withdrawn: YAML handoffs, gate checklists, journals, evidence archives,
release receipts, the capacity ledger, the rolling acceptance suite,
`Composition` lines, post-deployment action records, per-increment state files,
mandatory per-increment commits, review-import tooling, and generated run archives.

## Consequences

- One short plan review may prevent multiple expensive code-review rounds.
- The preflight is temporary implementation context, not a second product
  contract; Linear and merged ADRs remain authoritative.
- Review is a re-proof plus bounded contract review, not a source of new scope.
- Any commit after approval needs a new re-proof and fresh reviewer.
- Merges run one PR at a time from gates to promote.
- The skills in `.agents/skills/` and `WorkflowContractTest` pin this flow.
