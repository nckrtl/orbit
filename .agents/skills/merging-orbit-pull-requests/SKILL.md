---
name: merging-orbit-pull-requests
description: Use when performing the final merge gate for an approved Orbit pull request.
---

# Merging Orbit Pull Requests

Verify the exact approved candidate and merge it only when every gate passes.
The external orchestrator owns issue state, worktree cleanup, post-merge absence
verification, and all production deployment work. Task-owned live proof
resources must already be absent before this merge gate starts.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
the `post_proof` reviewer handoff, proof and mutation evidence, task-resource
ownership record, cleanup and absence evidence, Compound disposition, and
worktree path. Return `blocked` without merging when an input or gate is missing.

## Verify and merge

1. Confirm that the issue was eligible when claimed, remains active, and has an
   unchanged contract. Confirm that the pull request targets `main`, is
   mergeable, and still points to the candidate SHA.
2. Confirm that every required check passed for that SHA and that no newer or
   pending result invalidates it.
3. Confirm the reviewer handoff has `review_phase: post_proof` and `status:
   approved` for that SHA. For live proof, also confirm the retained
   `pre_rollout` review event has `status: rollout_approved` for the same SHA.
   Rollout approval is never merge approval. Final approval is either a formal
   GitHub approval from a review account different from the pull request author,
   or, when the review account is the same as the pull request author, a
   comment-type
   review from that account whose body is exactly `Approved.`. Reject any
   other comment, or a formal approval attempt that GitHub did not accept, as
   approval evidence. Any commit after approval requires a new review.
   Unresolved actionable review comments block the merge.
4. Verify the Linear scope and acceptance criteria against the diff and focused
   proof. Every linked or otherwise governing ADR must already be on `main`; an
   ADR introduced by the feature pull request blocks the merge.
5. Confirm that automated or live proof matches the issue venue and candidate
   SHA. Live proof must identify exact nodes from `orbit node:list --json`,
   including IDs, names, and roles; the Orbit CLI, Gateway API, or direct SSH
   method; and a pinned host-key fingerprint for each SSH path. Require
   proof-time checkout paths plus candidate and deployed full SHAs. Require a
   per-mutation record of the fresh node-list request or snapshot, intended
   mutation, task-owned resources, pre-state, recovery, result, and cleanup.
6. Perform read-only absence and drift verification immediately before merge.
   Confirm that every resource in the ownership record, including each
   task-owned recovery artifact, is absent. Confirm that shared live nodes and
   every pre-existing resource still match the recorded baseline. If a task
   resource remains, ownership is uncertain, evidence is stale, an unexpected
   resource exists, or shared or pre-existing state drifted, return `blocked`.
   Never delete, reset, adopt, or otherwise mutate live state to make this gate
   pass. The merge verifier performs no cleanup.
7. Confirm a useful Compound update in the correct durable location, or a
   specific reason why the work produced no durable learning.
8. Re-read the pull request head and all gates immediately before merging. If
   they are unchanged and pass, create a merge commit. Use the hosting service's
   merge-commit method, equivalent to `gh pr merge --merge`.

Do not close the Linear issue, clean or mutate live resources, remove the
worktree, or perform a production release. After a successful merge, signal the
external orchestrator. It verifies resource absence again, removes the
worktree, then closes the issue. Production release is a separate cycle.

## Handoff

Return this YAML block:

```yaml
role: merge-verifier
status: merged|blocked
issue: NCK-123|null
pull_request: URL|null
candidate_sha: full-sha|null
merge_sha: full-sha|null
gates:
  issue: pass|fail|not-assessed
  candidate: pass|fail|not-assessed
  mergeability: pass|fail|not-assessed
  checks: pass|fail|not-assessed
  approval: pass|fail|not-assessed
  comments: pass|fail|not-assessed
  acceptance: pass|fail|not-assessed
  adrs: pass|fail|not-assessed
  proof: pass|fail|not-assessed
  task_owned_cleanup: pass|fail|not-assessed
  live_drift: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  final_check: pass|fail|not-assessed
blockers: []
cleanup:
  action: none
  task_owned_resources: text|null
  absence_verification: text|null
  live_drift: text|null
  worktree: path|null
  order: verify_absence_then_worktree
external_issue_action: close_after_absence_and_worktree|none
```

Use `merged` only after the hosting service confirms the merge and returns the
merge commit SHA. `cleanup.action` is always `none`; the merge verifier never
cleans live resources. Use `close_after_absence_and_worktree` only with
`status: merged`. A blocked handoff uses `none` for the external issue action.
