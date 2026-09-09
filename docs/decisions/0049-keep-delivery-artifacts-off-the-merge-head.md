# ADR 0049: Keep delivery artifacts off the merge head

In the context of independent feature review, facing a second approval and continuous integration run caused by deleting the implementation workspace, we decided for separate Git references bound to the candidate and against committing delivery artifacts on the feature branch, to merge the reviewed candidate without another content change, accepting separate artifact retrieval and retention.

## Status

Accepted on 2026-09-10. Extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md). Supersedes [ADR 0022](0022-track-the-issue-workspace-and-delete-it-before-merge.md) for branch storage, removal, and second approval.

## Context

The delivery skills require a review of the workspace head, a commit that removes the workspace, and a second review. The removal also starts another continuous integration run and prevents integrating main without rebuilding that sequence. Plans and fixtures need durable review evidence but do not belong in the product tree.

## Decision

- The implementation loop must store plans, review records, proof plans, and fixtures on Git references separate from feature and main branches.
- The implementation loop must bind each submitted artifact snapshot to one exact candidate commit and retain that snapshot for review and proof replay.
- Reviewers must bind approval to the exact candidate and artifact snapshot they inspected.
- The orchestrator must merge the approved candidate without an artifact removal commit.
- The proof harness must read committed artifact inputs and reject missing or changed bindings.
- The orchestrator must require fresh approval and continuous integration for a changed candidate.

## Rejected alternatives

- Skip checks on a removal commit: rejected because the merged commit would still differ from the independently reviewed candidate.
- Store plans only in a local ignored directory: rejected because another host cannot retrieve the exact reviewed inputs.

## Consequences

- Artifact cleanup does not create another candidate or repeat its review and continuous integration.
- Artifact references need explicit publication, retrieval, and retention.
- Existing feature work must publish its artifacts and remove them from the candidate before its next review.

## Affects

- Components: apps/e2e
- ADRs: extends [ADR 0015](0015-retain-incus-proof-by-recorded-input-equivalence.md); supersedes [ADR 0022](0022-track-the-issue-workspace-and-delete-it-before-merge.md) for branch storage, removal, and second approval
- Detail: docs/reference/implementation-loop.md
- Verify: artifact publication and proof input tests; `composer docs-lint`
