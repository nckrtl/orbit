# ADR 0014: Reconcile technical preflight blocks before escalation

## Status

Accepted on 2026-08-31. This ADR extends ADR 0007 and ADR 0011 and supersedes
their direct preflight `BLOCK` to Linear `Blocked` transition.

## Context

An independent preflight reviewer must stop implementation when the prepared
plan cannot satisfy the issue contract. The existing workflow immediately
parks every `BLOCK` for Nick or Anna to resolve. That treats an agent's
technical inability to find a path as proof that a human product decision is
required.

ORB-7 exposed the distinction. Fixture cleanup traps could not run after the
existing timeout path delivered `SIGKILL`. The smallest safe resolution was an
internal harness change: deliver `TERM`, allow a bounded cleanup grace period,
and send `SIGKILL` only if the fixture remains alive. This changed technical
means but preserved product behavior, proof strength, and the accepted outcome.
Nick should not be required to approve that class of implementation decision.

Orbit needs an independent role that tests a reviewer block, searches for a
smaller contract-preserving resolution, and recognizes when existing authority
ends. The role must not let two agents negotiate away product intent or proof
requirements merely to keep delivery moving.

## Decision

A preflight reviewer still records `PASS`, `FIX`, or `BLOCK`. `BLOCK` stops
implementation but does not immediately move the Linear issue out of
`In Progress`. Tom exits the reviewer and starts one fresh reconciler in the
same retained worktree and Herdr workspace.

The reconciler independently reads the issue, accepted ADRs, plan, reviewer
findings, relevant repository state, and proof boundaries. It verifies the
block and chooses the smallest safe, elegant, and contract-preserving
resolution. It records one of two verdicts in `.orbit/plan.md`:

- `TECHNICAL_RESOLUTION`: existing product intent and proof strength are
  preserved. The reconciler may propose a narrow internal scope correction,
  including changes to harness behavior, test mechanics, implementation
  boundaries, sequencing, or proof technique, when the original mechanism
  cannot satisfy the accepted outcome. It must state exact artifact changes,
  behavior changed, behavior unchanged, rejected alternatives, and fresh-review
  evidence.
- `HUMAN_DECISION_REQUIRED`: existing authority cannot choose without changing
  product-visible behavior, conflicting requirements, an accepted outcome,
  ownership, migration, compatibility, security, privacy, data integrity,
  rollback policy, material irreversible risk, or an architectural direction
  not governed by an accepted ADR. Any new, amended, or superseding ADR remains
  subject to Nick's exact-text approval.

The reconciler does not implement, edit Linear or GitHub, alter Git history, or
return `PASS`. It does not resume or negotiate with the reviewer that returned
`BLOCK`.

For `TECHNICAL_RESOLUTION`:

1. If only the ephemeral plan must change, Tom starts a fresh correction
   planner.
2. If Linear or a relation requires a durable mutation, Tom routes the exact
   proposal to Anna. Anna verifies that it remains within delegated
   technical authority, applies and reads back the change, and signals Tom to
   continue. Nick is not involved for internal technical or harness choices
   that preserve product behavior and the accepted outcome.
3. Tom starts a wholly fresh planner and a wholly fresh preflight reviewer.
4. The fresh reviewer's `PASS` is agreement with the applied resolution and
   admits implementation.

For `HUMAN_DECISION_REQUIRED`, Tom moves the issue to `Blocked`, parks its
assets, and routes the smallest bounded decision to Anna or Nick. Linear
`Blocked` therefore means human judgment is genuinely required, not merely
that the first technical proposal failed. Anna may prepare an ADR proposal, but
Nick must approve its exact text before it becomes accepted.

A later technical `BLOCK` is active preflight non-convergence. Tom diagnoses it
with Anna and may run another fresh reconciliation round when evidence or the
blocker changed. He does not misrepresent technical non-convergence as a
Nick-owned product decision. Every role remains fresh; no reviewer or
reconciler conversation is resumed across rounds.

Tom remains a routing and lifecycle coordinator. He does not judge the
reconciler proposal, edit the issue contract or relations, or perform the
resolution himself.

## Consequences

- Simple technical blockers continue without interrupting Nick.
- Product intent remains protected by explicit authority boundaries and a
  wholly fresh final preflight review.
- The repository gains a dedicated reconciler skill whose examples and
  boundaries can improve from observed blocks.
- Internal harness semantics may change without human approval when the change
  is narrow, reversible, preserves product behavior, and does not weaken proof.
- Linear `Blocked` becomes a stronger signal that human judgment is actually
  needed.
- Technical non-convergence remains visible as active recovery and may consume
  an execution slot until Anna and Tom restore convergence.
