---
title: "ADR 0076: Deliver features through complete pull requests"
sidebarTitle: "0076 Deliver features through complete pull requests"
description: "Proposed; accepted through merge. Supersedes ADR 0010 and the earlier proof, evidence, and merge-gate decisions."
---

# ADR 0076: Deliver features through complete pull requests

In the context of Orbit feature contributions, facing different procedures for contributors and internal automation, we decided for one complete pull request containing implementation, tests, documentation, and architectural decisions, and against mandatory plan approval and separate ADR merges, to make review cover the delivered feature, accepting that contributors may implement a proposal the maintainer rejects.

## Status

Proposed. Supersedes [ADR 0010](/decisions/0010-record-decisions-before-implementation-issues) for prior ADR acceptance and contribution prerequisites; [ADR 0002](/decisions/0002-candidate-deployment-proof-boundary), [ADR 0006](/decisions/0006-topology-led-feature-development), [ADR 0015](/decisions/0015-retain-incus-proof-by-recorded-input-equivalence), [ADR 0035](/decisions/0035-close-out-mutating-proofs-by-refreshing-the-topology-snapshot), [ADR 0040](/decisions/0040-extend-issue-proof-with-one-app-prod-node), [ADR 0050](/decisions/0050-release-successful-proof-resources-before-landing), [ADR 0056](/decisions/0056-retain-proof-topologies-for-interactive-review), [ADR 0022](/decisions/0022-track-the-issue-workspace-and-delete-it-before-merge), [ADR 0049](/decisions/0049-keep-delivery-artifacts-off-the-merge-head), [ADR 0051](/decisions/0051-select-discovery-only-feature-delivery), [ADR 0053](/decisions/0053-use-local-review-checks-for-feature-landing), [ADR 0058](/decisions/0058-separate-incus-requirements-from-delivery-flow), and [ADR 0059](/decisions/0059-make-the-builder-own-the-candidate-quality-gate) for feature preparation, evidence, and merge gates.

## Context

The maintainer needs to review complete features with their architectural rationale and actual behavior. Orbit owns final Incus reproduction during review. Internal task decomposition helps Commander execute work. Automated checks and independent machine reproduction provide different evidence.

## Decision

- Contributors must check affected architecture, draft significant decision changes, and write intended documentation before implementation, then keep those materials aligned with delivered behavior.
- Contributors may implement against proposed ADRs in the feature branch.
- The maintainer owns acceptance of proposed decisions through approval and merge of the PR containing them; editorial corrections may update an accepted record, while substantive changes must preserve history through a superseding record.
- Contributors must submit complete features for maintainer review, including implementation, tests, documentation, and applicable ADR changes.
- Contributors may use draft PRs as working spaces during development.
- Commander owns optional internal plans, task decomposition, assignments, and progress outside the repository.
- CI must run documentation validation, project quality checks, and automated tests; merge must require passing CI for the reviewed change.
- Orbit's independent feature review must assess architecture, implementation, tests, and documentation and reproduce affected acceptance behavior on Incus.
- Orbit owns code review and Incus reproduction after a contributor submits the completed feature.
- Reviewers must record the reviewed revision, environment, verification actions, observed results, and limitations in the PR or linked evidence.
- Merge must require resolved blocking findings, independent code and Incus review, and the maintainer's approval for the final revision.
- Reviewers must use an authorized Incus environment and preserve resource ownership and cleanup safeguards.

## Rejected alternatives

- Require a reviewed plan in every PR: rejected because the completed implementation, documentation, and decisions already describe the feature.
- Merge ADRs before implementation: rejected because it separates decision review from the implementation that demonstrates its consequences.
- Require contributors to run Incus before submission: rejected because contributors may not have the environment and Orbit owns final machine review.
- Accept an agent's review instead of maintainer approval: rejected because the maintainer owns whether a feature belongs in Orbit.

## Consequences

- External contributors and Commander use the same review and merge standard.
- A contributor can spend implementation effort on a direction that the maintainer rejects.
- Maintainer review covers complete features instead of unfinished proposals.
- Repository role guides, CI, and worktree preparation must follow this contribution contract.
- Retained proof resources keep their ownership and cleanup safeguards.

## Affects

- Components: apps/docs, apps/e2e
- ADRs: supersedes ADRs 0002, 0006, 0010, 0015, 0022, 0035, 0040, 0049, 0050, 0051, 0053, 0056, 0058, and 0059 for feature delivery; preserves resource ownership and cleanup requirements of ADRs 0015, 0035, 0050, and 0056 for retained proof resources
- Detail: [Feature delivery](/reference/implementation-loop)
- Verify: `composer docs-lint`; worktree creation tests; CI project checks; independent PR review
