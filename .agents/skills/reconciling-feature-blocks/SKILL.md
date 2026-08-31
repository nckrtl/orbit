---
name: reconciling-feature-blocks
description: Use when resolving an Orbit preflight BLOCK before escalation.
---

# Reconciling Feature Blocks

Resolve one independent preflight review `BLOCK` before implementation. Find
the smallest safe, elegant, and contract-preserving technical resolution. Do
not implement, approve product decisions, or weaken the accepted outcome.

## Inputs

Read all of the following from current sources:

- the Linear issue, still in `In Progress`;
- governing ADRs on current `origin/main`;
- `.orbit/plan.md`, including every reviewer finding and recommendation;
- the named code, test, proof, and harness boundaries; and
- nearby invariants needed to judge impact.

Stop if the review did not collect all known blocking findings or if the
supporting evidence cannot be verified.

## Reconcile the block

For every finding:

1. Verify that the claimed blocker is real on current repository state.
2. Identify the accepted product outcome, acceptance criterion, or invariant
   that the blocker protects.
3. Compare the reviewer's recommendation with simpler, narrower, more elegant,
   or less invasive alternatives.
4. Select the smallest resolution that preserves product intent and proof
   strength. Technical means may change when the original mechanism cannot
   satisfy the accepted outcome.
5. State exactly what behavior changes and what behavior remains unchanged.
6. Map the proposal to exact plan, Linear, dependency, code-boundary, or proof
   changes and the evidence a fresh reviewer must inspect.

A narrow internal scope correction is technical even when it changes harness
or test mechanics. For example, replacing an uncatchable timeout `SIGKILL`
path with `TERM`, a bounded cleanup grace period, and a final `SIGKILL` is a
technical resolution when product behavior, the accepted proof outcome, and
broader timeout policy remain unchanged.

Never make a finding disappear by deleting an acceptance criterion, weakening
proof, broadening a feature's product behavior, or describing an unapproved
product choice as implementation detail.

## Verdict

Update only `Reconciliation round`, `Reconciliation verdict`, and
`Reconciler recommendation` in `.orbit/plan.md`.

### `TECHNICAL_RESOLUTION`

Use this when the smallest safe resolution preserves product intent and stays
within delegated technical authority. The recommendation must include:

- **Blocker:** the verified technical incompatibility;
- **Contract anchor:** the outcome, criterion, ADR, or invariant preserved;
- **Smallest resolution:** the exact proposed change;
- **Alternatives rejected:** why broader or weaker options are inferior;
- **Behavior changed:** internal behavior that will differ;
- **Behavior unchanged:** product-visible and policy behavior preserved;
- **Artifact changes:** exact plan, Linear, relation, boundary, or proof edits;
- **Fresh-review proof:** evidence required before `PASS`.

This verdict may propose a narrow Linear scope clarification or internal
component expansion when it is required to make the already accepted outcome
feasible. It does not itself authorize Tom to edit Linear.

### `HUMAN_DECISION_REQUIRED`

Use this only when existing authority cannot choose without product judgment.
Examples include changing product-visible behavior, selecting between
conflicting requirements, weakening an accepted outcome, changing ownership,
migration, compatibility, security, privacy, data-integrity, or rollback
policy, accepting material irreversible risk, or adopting an architectural
direction not governed by an accepted ADR. Any new, amended, or superseding ADR
requires Nick's exact-text approval and therefore uses this verdict.

State the one decision, the smallest viable options, the impact of each, and
why no existing contract answers it. Internal harness behavior, test mechanics,
implementation complexity, or a narrow technical scope correction are not by
themselves human decisions.

Increase `Reconciliation round` by one. Never return `PASS`; only a fresh
preflight reviewer may admit implementation.

## Handoff

- For `TECHNICAL_RESOLUTION` requiring only plan correction, Tom starts a fresh
  correction planner and then a fresh reviewer.
- For `TECHNICAL_RESOLUTION` requiring a durable Linear or relation mutation,
  Tom routes the exact proposal to Anna. Anna verifies the delegated authority
  boundary, applies and reads back approved changes, then signals Tom to start
  a wholly fresh planner and reviewer.
- The fresh reviewer's `PASS` is agreement with the applied resolution.
- For `HUMAN_DECISION_REQUIRED`, Tom moves the issue to `Blocked`, parks its
  assets, and routes the bounded decision to Anna or Nick.
- Every later reviewer `BLOCK` starts another fresh reconciler. If the same
  technical finding repeats without changed evidence, record preflight
  non-convergence and require Tom to route it to Anna while the issue remains
  active. Tom does not diagnose or resolve the technical finding himself and
  does not mislabel it as a Nick-owned decision.

## Boundaries

- Do not edit product code, tests, proof files, Git history, Linear, or GitHub.
- Do not resume or negotiate with the reviewer that returned `BLOCK`.
- Do not treat agreement between agents as authority to change product intent.
- Do not invent a new requirement; separate genuinely new work in Linear.
- Preserve one issue lifecycle, worktree, branch, plan, and Herdr workspace.

## Verification

Before returning a verdict, verify that every reviewer finding is addressed,
the proposal preserves every accepted criterion, behavior impact is explicit,
and the next fresh reviewer can prove the resolution from authoritative
artifacts rather than trusting this recommendation.
