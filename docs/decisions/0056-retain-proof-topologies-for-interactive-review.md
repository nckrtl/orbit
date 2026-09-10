# ADR 0056: Retain proof topologies for interactive review

In the context of issues that select proof delivery, facing reviewers who need to inspect and exercise the proved system, we decided for retained machines with interactive review and immutable captured evidence and against releasing successful machines before review or forbidding reviewer commands, to support direct acceptance assessment, accepting longer resource retention and live state that diverges from the recorded proof run.

## Status

Accepted on 2026-09-10. Supersedes [ADR 0006](0006-topology-led-feature-development.md), [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md), and [ADR 0040](0040-extend-issue-proof-with-one-app-prod-node.md) for prohibiting changes to successful proof machines during review; ADR 0015 also changes for evidence reuse after a code or configuration fix. Supersedes [ADR 0050](0050-release-successful-proof-resources-before-landing.md) for releasing successful proof before review. Retains [ADR 0051](0051-select-discovery-only-feature-delivery.md) for flow selection and discovery exemptions, and ADRs 0040 and 0050 for snapshot closeout and topology boundaries.

## Context

ADR 0050 releases successful proof machines before review, while ADR 0040 retains extended proof until snapshot refresh after merge. Retention alone does not permit inspection because ADRs 0006 and 0015 freeze successful proof state and the harness refuses reviewer commands. The repository owner requires reviewers to access the proof machines and permits tests that change application or machine state.

## Decision

- The implementation loop must apply this decision only when the `proof` flow is enabled for the specific issue implementation.
- The harness must capture the successful proof result, complete action evidence, topology identity, and input manifest before granting interactive reviewer access.
- The implementation loop must retain every Node of the successful proof topology throughout review of its candidate, including a declared additional production Node.
- The harness must give reviewers interactive access to the retained proof machines when the issue selects proof delivery.
- Reviewers may run commands and feature tests that change application or machine state during inspection of the retained proof topology.
- The harness must preserve the captured proof evidence as an immutable record of the original run after reviewer actions change live state.
- Reviewers must record their actions and findings separately from the captured proof, bound to the issue, reviewed candidate, and inspected attempt.
- Reviewers must withhold approval when a required review check fails, even when the original proof passed.
- The implementer must complete fresh proof after any code or configuration fix before seeking approval.
- The implementer must make each such fix reproducible from the candidate and its declared proof inputs instead of relying on an edit left on the inspected machine.
- The harness must not use reviewer-modified live state as unchanged proof input or as a promoted shared snapshot.
- Closeout must release the retained successful proof topology after the verified merge and required snapshot refresh succeed.
- The implementation loop may release an obsolete attempt before closeout when replacing it with fresh proof or explicitly abandoning the issue, while preserving its captured evidence and review findings.

## Rejected alternatives

- Release successful proof before review: rejected because reviewers cannot inspect the system that produced the evidence.
- Retain machines but prohibit all reviewer commands: rejected because retention would not provide the requested interactive access.
- Replace the captured proof with reviewer observations: rejected because exploratory state changes do not reproduce the original acceptance run.
- Approve a fix left only on an inspected machine: rejected because the submitted candidate and reproducible proof inputs would not contain that fix.

## Consequences

- Reviewers can inspect and exercise both standard and extended proof topologies.
- Proof machines consume host capacity through review, merge, and snapshot refresh; a failed refresh delays their release.
- Captured proof and inspected live state have distinct meanings, and review records identify which state produced each finding.
- Failed proof retains its explicit diagnosis and cleanup lifecycle under ADR 0050.
- Evidence equivalence outside the required fresh proof for fixes retains ADR 0015's scope; release still does not erase captured evidence.
- Discovery delivery gains no proof-retention or snapshot-closeout requirement from this decision.
- A dedicated harness change must coordinate capture, reviewer access, evidence evaluation, and cleanup before this review lifecycle is available.

## Affects

- Components: apps/e2e
- ADRs: supersedes [ADR 0006](0006-topology-led-feature-development.md), [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md), and [ADR 0040](0040-extend-issue-proof-with-one-app-prod-node.md) for proof-machine immutability during review; supersedes ADR 0015 for evidence reuse after fixes and [ADR 0050](0050-release-successful-proof-resources-before-landing.md) for release timing; retains [ADR 0051](0051-select-discovery-only-feature-delivery.md) for flow scope
- Detail: [Proof plans](../reference/proof-plans.md); [Incus topologies](../reference/incus-topologies.md); [Implementation loop](../reference/implementation-loop.md)
- Verify: harness tests for capture before inspection, retained standard and extended topology access, separate review evidence, fresh proof after fixes, and closeout cleanup; `composer docs-lint`
