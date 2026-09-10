# ADR 0058: Separate Incus requirements from delivery flow

In the context of selectable feature delivery, facing a proof-named issue label and topology allocation for automated-only work, we decided for an Incus requirement independent of delivery flow and against letting the label select proof, to keep verification proportional to acceptance, accepting that issue classification determines whether machine behavior is exercised.

## Status

Accepted on 2026-09-10. Extends [ADR 0051](0051-select-discovery-only-feature-delivery.md). Supersedes ADR 0051 for mandatory discovery topology use by automated-only issues.

## Context

Issue labels identify acceptance that depends on a real operating system, service manager, privilege boundary, network, certificate, filesystem ownership, or multiple machines. The proof prefix confuses that requirement with the separately selected delivery flow. Automated-only changes do not need disposable machines to run their acceptance checks.

## Decision

- An issue contract must identify whether its acceptance requires Incus.
- An Incus requirement must not select or change the issue's delivery flow.
- The implementer must use a discovery topology after preflight and independent plan review when acceptance requires Incus.
- The implementer must use a separate proof topology when acceptance requires Incus and the selected flow is proof.
- Automated-only issues must not require a topology solely because a delivery flow is selected.

## Rejected alternatives

- Let the Incus label select proof: rejected because it overrides the separate flow choice and restores isolated proof to ordinary discovery delivery.
- Require discovery for every issue: rejected because checks that need no real operating system still allocate machines.
- Remove the Incus requirement from issues: rejected because reviewers would have no explicit signal that local tests need machine observations.

## Consequences

- The same Incus requirement supports discovery-only delivery and explicitly selected proof delivery.
- Issues verified by local tests can finish without creating or removing machines.
- An incorrectly omitted Incus requirement can leave machine behavior untested, so planning and review must check the classification against acceptance.

## Affects

- Components: none
- ADRs: extends [ADR 0051](0051-select-discovery-only-feature-delivery.md); supersedes ADR 0051 for mandatory discovery topology use by automated-only issues
- Detail: [docs/reference/implementation-loop.md](../reference/implementation-loop.md)
- Verify: issue label and flow review; `composer docs-lint`
