# ADR 0051: Select discovery-only feature delivery

In the context of feature delivery on a shared Incus host, facing repeated proof and base-integration work during review, we decided for selectable discovery-only delivery alongside the proof flow and against requiring retained proof for every feature, to shorten delivery, accepting that discovery-only delivery provides no isolated acceptance proof.

## Status

Accepted on 2026-09-10. Extends [ADR 0049](0049-keep-delivery-artifacts-off-the-merge-head.md). Supersedes [ADR 0006](0006-topology-led-feature-development.md), [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md), and [ADR 0050](0050-release-successful-proof-resources-before-landing.md) for proof, base freshness, and snapshot closeout requirements when discovery-only delivery is selected.

## Context

Captured proof releases successful virtual machines before landing but still requires an isolated run and evidence evaluation after base integration. The repository owner wants to suspend those gates while retaining independent planning and code review. A per-worktree choice allows the proof flow to remain available without changing an active feature when the repository default changes.

## Decision

- The repository owner owns the default delivery flow and may select discovery-only or proof delivery.
- Each feature must retain its selected flow with its candidate-bound delivery artifacts.
- Discovery-only delivery must use an isolated discovery topology as a development tool after preflight and preflight review.
- Discovery-only delivery must retain acceptance outcomes, focused local tests, changed-project checks, full continuous integration suites, and independent code review.
- Discovery-only delivery must not require a proof topology, proof plan, observed-input collection, evidence equivalence, candidate convergence, or snapshot promotion or refresh to land.
- Discovery-only review and merge must not require the candidate to include the latest main when Git can merge it without conflicts.
- The implementer must integrate main when merge conflicts prevent landing and submit resolution changes for review on the updated head.
- The reviewer must bind approval to the candidate, its artifacts, and its selected flow; an advance of main alone must not invalidate discovery-only approval.
- Closeout must verify the approved candidate is the merged feature parent and release the feature's discovery resources.
- The proof flow must retain its existing proof and base-integration requirements.

## Rejected alternatives

- Delete the proof flow: rejected because restoring isolated acceptance proof would require another implementation.
- Treat discovery as immutable proof: rejected because discovery mounts a changing worktree and retains mutable state.
- Retain strict base freshness for discovery-only merges: rejected because unrelated main changes would still restart candidate preparation and review.

## Consequences

- Features can land after code review without a second topology or proof-related checks when main advances.
- Discovery-only delivery can miss integration failures that an isolated acceptance run would detect.
- Snapshot maintenance is separate from discovery-only closeout and may still be needed when infrastructure requirements change.
- Existing issue proof venues map to development observations and tests in discovery-only delivery; their acceptance outcomes remain required.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0049](0049-keep-delivery-artifacts-off-the-merge-head.md); supersedes [ADR 0006](0006-topology-led-feature-development.md), [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md), and [ADR 0050](0050-release-successful-proof-resources-before-landing.md) for discovery-only delivery
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: flow selection and merge lineage tests; `composer docs-lint`
