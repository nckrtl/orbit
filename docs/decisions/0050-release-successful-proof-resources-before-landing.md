# ADR 0050: Release successful proof resources before landing

In the context of concurrent feature delivery on one Incus host, facing proof virtual machines held throughout review and merge, we decided for captured acceptance evidence and early resource release with snapshot refresh from merged main and against retaining successful virtual machines until promotion, to free capacity during landing, accepting that snapshot convergence remains a separate closeout operation.

## Status

Accepted on 2026-09-10. Extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md). Supersedes [ADR 0006](0006-topology-led-feature-development.md) and [ADR 0035](0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md) for successful proof resource retention and snapshot closeout.

## Context

Each successful proof holds an isolated topology while independent review and continuous integration run. The equivalence evaluator requires a live lease although it compares recorded Git inputs and construction evidence. Refresh already converges the shared snapshot from merged main for mutating proofs.

## Decision

- The implementation loop must retain premerge Incus acceptance proof for issues that require it.
- The harness must capture the successful proof result, topology identity, complete zero-exit action evidence, and input manifest before releasing proof resources.
- The implementation loop must release successful proof and idle discovery resources before handing a candidate to review.
- The harness must evaluate retained evidence independently of the released virtual machines and reject incomplete or mismatched evidence.
- The implementation loop must retain failed proof resources for explicit diagnosis until the operator releases them.
- Closeout must refresh the shared topology snapshot from current main containing the verified merge and record the proof, accepted candidate, merge, and resulting generation.
- Closeout must retain captured evidence when snapshot refresh fails and report the failed operation for retry.

## Rejected alternatives

- Share mutable discovery across features: rejected because one feature can change another feature's development state.
- Move acceptance proof after merge: rejected because an acceptance failure would reach main before review can reject it.
- Retain successful virtual machines until promotion: rejected because landing time would continue consuming feature capacity.

## Consequences

- Review can continue without successful issue virtual machines occupying the host.
- Released proof environments cannot support interactive inspection; a new isolated run is required.
- Snapshot refresh uses merged code and does not promote the feature's proof topology.
- Evidence retention and snapshot maintenance have separate lifetimes.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md); supersedes [ADR 0006](0006-topology-led-feature-development.md) and [ADR 0035](0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot.md) for successful proof resource retention and closeout
- Detail: [docs/reference/proof-plans.md](../reference/proof-plans.md)
- Verify: retained evidence and topology release tests; `composer docs-lint`
