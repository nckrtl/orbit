# ADR 0035: Close out mutating proofs by refreshing the topology snapshot

In the context of merge closeout after an Incus proof whose plan declares `mutates: true`, facing a promoter that refuses the proved topology by design and a closeout contract that stops without a promotable proof, we decided for refreshing the topology snapshot from the merge commit and against a second candidate-convergence topology or a revert, to achieve unattended closeout that keeps the snapshot aligned with `main`, accepting that the snapshot after such a merge is converged from `main` rather than promoted from the proved topology.

## Status

Accepted on 2026-09-06. Extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md). Supersedes [ADR 0006](0006-topology-led-feature-development.md) for the closeout of a proof whose plan mutates reusable node state.

## Context

A proof plan that declares `mutates: true` changes reusable node state, so the proved topology cannot become the shared topology snapshot and `promote` refuses it. ORB-127 merged with such a plan on 2026-09-05; its closeout stopped, the merge reservation stayed held, the promoted snapshot stopped matching `main`, and the delivery loop idled until a person intervened. The harness already refreshes the snapshot in place from a `main` SHA under the same locks and lineage records that promotion uses, and the retained proof keeps the acceptance evidence for the merged candidate.

## Decision

- Closeout must run `bin/e2e-topology-snapshot refresh --main-sha=<merge commit>` when the retained proof plan of the merged candidate declares `mutates: true`, after the external merge is verified.
- Closeout must record the proved attempt, the accepted head, the merge commit, and the promoted generation in the closeout handoff before cleanup.
- Closeout must release the issue's discovery and proof topologies after the refresh promotes a generation.
- Closeout must not substitute a refresh for a missing, failed, or non-equivalent proof; ADR 0015's retention and equivalence rules stay in force.
- The harness owns the refresh lineage record; closeout never edits it.

## Rejected alternatives

- A second candidate-convergence topology promoted after the mutating proof: rejected because it adds harness code to reach the same converged state that `refresh` already produces from the promoted snapshot.
- Reverting and relanding the merged change: rejected because it repeats a full delivery lifecycle without defining a closeout for the next mutating proof.

## Consequences

- A merge whose proof mutates state closes out without a person and leaves a snapshot whose fingerprint matches `main`.
- The snapshot after such a merge carries no promoted proof lineage for the merged issue; the acceptance evidence lives only in the retained proof.
- The closeout step in `.agents/skills/merging-pull-requests/SKILL.md` and the reference pages for proof plans and the topology snapshot must state this path before the next mutating proof merges.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md); supersedes [ADR 0006](0006-topology-led-feature-development.md) for the closeout of a mutating proof
- Detail: [docs/reference/topology-snapshot.md](../reference/topology-snapshot.md)
- Verify: `bin/e2e-topology-snapshot status` reports the generation whose `main_sha` equals the merge commit after closeout
