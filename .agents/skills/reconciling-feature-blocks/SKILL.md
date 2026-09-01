---
name: reconciling-feature-blocks
description: Use when independently analyzing an Orbit plan-review block.
---

# Reconciling Feature Blocks

Analyze one `BLOCK` from an independent plan review. Find the smallest safe,
elegant, contract-preserving resolution. Do not implement, mutate external
systems, approve product decisions, or weaken the accepted outcome.

## Inputs

Read current sources:

- the issue or equivalent written contract;
- governing product ADRs on current `origin/main`;
- `.orbit/plan.md`, including every reviewer finding and recommendation;
- named code, test, proof, and harness boundaries; and
- nearby invariants needed to judge impact.

Stop if the review omitted known blocking findings or its evidence cannot be
verified.

## Reconcile the block

For every finding:

1. Verify that the claimed blocker exists on current repository state.
2. Identify the outcome, criterion, ADR, or invariant the finding protects.
3. Compare the recommendation with simpler, narrower, more elegant, and less
   invasive alternatives.
4. Select the smallest resolution preserving product intent and proof strength.
5. State exactly what behavior changes and what remains unchanged.
6. Map the proposal to exact plan, Linear, relation, code-boundary, or proof
   changes and name the evidence needed to validate it.

A narrow internal scope correction may change implementation, harness, test, or
proof mechanics when product behavior and acceptance strength remain unchanged.
For example, replacing an uncatchable timeout `SIGKILL` path with `TERM`, a
bounded cleanup grace period, and a final `SIGKILL` is technical when broader
timeout policy and product behavior stay unchanged.

Never make a finding disappear by deleting an acceptance criterion, weakening
proof, broadening product behavior, or describing an unapproved product choice
as implementation detail.

## Verdict

Update only `Reconciliation verdict` and `## Reconciliation notes` in
`.orbit/plan.md`.

### `TECHNICAL_RESOLUTION`

Use when the smallest safe resolution stays within delegated technical
authority. Include:

- **Blocker:** verified technical incompatibility;
- **Contract anchor:** protected outcome, criterion, ADR, or invariant;
- **Smallest resolution:** exact proposed change;
- **Alternatives rejected:** why broader or weaker options are inferior;
- **Behavior changed:** internal behavior that differs;
- **Behavior unchanged:** product-visible and policy behavior preserved;
- **Artifact changes:** exact plan, issue, relation, boundary, or proof edits;
- **Validation evidence:** evidence required to assess the proposal.

This verdict may propose a narrow Linear clarification or component expansion
needed to make an accepted outcome feasible. It does not itself authorize any
external mutation.

### `HUMAN_DECISION_REQUIRED`

Use only when existing authority cannot choose without product judgment:
product-visible behavior, conflicting requirements, weakened outcomes,
ownership, migration, compatibility, security, privacy, data integrity,
rollback policy, material irreversible risk, or architectural direction not
governed by an accepted ADR.

State the one decision, smallest viable options, impact of each, and why no
existing contract answers it. Internal mechanics, implementation complexity,
or a narrow technical correction are not by themselves human decisions.

## Boundaries

- Do not edit product code, tests, proof files, Git history, Linear, or GitHub.
- Do not negotiate with the reviewer that produced the finding.
- Do not treat agreement between agents as authority to change product intent.
- Do not invent a new requirement; separate genuinely new work in Linear.

## Verification

Before returning a verdict, verify that every finding is addressed, accepted
criteria remain intact, behavior impact is explicit, and the proposal can be
assessed from authoritative artifacts rather than this recommendation alone.
