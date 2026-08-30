# ADR 0007: Use the nine-step feature flow

## Status

Accepted on 2026-08-30. Supersedes
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

## Decision

A change with `Proof: incus` follows nine steps, written in full in
[development-workflow](../reference/development-workflow.md). Automated-only
changes run steps 1, 2, 5, 7, 8 (CI green instead of a proof), and 9 without
promote. Harness changes are covered under Ownership.

1. Linear issue (`Proof: incus` when a real machine is needed).
2. Worktree from `main`.
3. Fresh topology from the standby snapshot, worktree mounted.
4. Get it right on the topology (`shell`, edit, run).
5. Codify with tests; commit.
6. Merge `main`; prove the commit on a fresh topology with
   `proofs/<ISSUE>.json`.
7. Short pull request.
8. Reviewer merges `main`, re-proves, approves, keeps the topology alive.
9. Merge; the reviewer's topology becomes the standby generation
   (`bin/e2e-standby promote`, fallback `refresh`); worktree removed; issue
   closed.

Where things live:

- Proof plans: `proofs/<ISSUE>.json` (+ `proofs/<ISSUE>/` fixtures),
  committed with the PR.
- Per-issue harness state: `<worktree>/.e2e/`, deleted with the worktree.
- Promoted standby generation: `<primary>/.e2e/standby/`, until the next
  promote.

Ownership:

- Feature branches never modify the harness (`apps/e2e`, `bin/e2e-*`,
  except `apps/e2e/tests/Feature/**` and `apps/e2e/tests/Unit/**`).
- Harness changes are their own issues; their proof is `bin/e2e-live <sha>`,
  one end-to-end run of the feature flow against a standby built from the
  candidate.

Withdrawn: YAML handoffs, gate checklists, journals, evidence archives, release
receipts, the capacity ledger, the rolling acceptance suite, `Composition`
lines, post-deployment action records, and the "project manager" role.

## Consequences

- A feature costs one acquire (about 20 s), one prove (about a minute with
  convergence), and one release; nothing accumulates on the host.
- Review is a re-proof, not a checklist. Any commit after approval needs a
  new re-proof.
- Merges run one PR at a time from gates to promote.
- The skills in `.agents/skills/` and `WorkflowContractTest` pin this flow.
