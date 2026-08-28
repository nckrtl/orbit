---
name: merging-orbit-pull-requests
description: Use when performing the final merge gate for an approved Orbit pull request.
---

# Merging Orbit Pull Requests

Verify the exact approved candidate and merge it only when every gate passes.
The external orchestrator owns issue state, task-owned resource and worktree
cleanup, and all deployment work.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
reviewer handoff, proof evidence, Compound disposition, and worktree path.
Return `blocked` without merging when an input or gate is missing.

## Verify and merge

1. Confirm that the issue was eligible when claimed, remains active, and has an
   unchanged contract. Confirm that the pull request targets `main`, is
   mergeable, and still points to the candidate SHA.
2. Confirm that every required check passed for that SHA and that no newer or
   pending result invalidates it.
3. Confirm independent approval of that SHA: either a formal GitHub approval
   from a review account different from the pull request author, or, when the
   review account is the same as the pull request author, a comment-type
   review from that account whose body is exactly `Approved.`. Reject any
   other comment, or a formal approval attempt that GitHub did not accept, as
   approval evidence. Any commit after approval requires a new review.
   Unresolved actionable review comments block the merge.
4. Verify the Linear scope and acceptance criteria against the diff and focused
   proof. A required ADR must already be on `main`; an ADR introduced by the
   feature pull request blocks the merge.
5. Confirm that automated or live proof matches the issue venue and candidate
   SHA. Live proof must identify exact nodes from `orbit node:list --json`,
   including IDs, names, and roles; the Orbit CLI, Gateway API, or direct SSH
   method; and a pinned host-key fingerprint for each SSH path. Require
   proof-time checkout paths plus candidate and deployed full SHAs and
   pre-state, post-state, recovery, and cleanup evidence. Treat verified
   task-owned cleanup as a required gate. Confirm that no pre-existing state
   was deleted or adopted and that shared live nodes remain intact.
6. Confirm a useful Compound update in the correct durable location, or a
   specific reason why the work produced no durable learning.
7. Re-read the pull request head and all gates immediately before merging. If
   they are unchanged and pass, create a merge commit. Use the hosting service's
   merge-commit method, equivalent to `gh pr merge --merge`.

Do not close the Linear issue, clean task-owned resources, remove the worktree,
or perform a production release. Shared live nodes are never removed. After a
successful merge, signal the external orchestrator. It cleans task-owned
resources before the worktree, then closes the issue. Production release is a
separate cycle.

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
  compound: pass|fail|not-assessed
  final_check: pass|fail|not-assessed
blockers: []
cleanup:
  action: cleanup|none
  task_owned_resources: text|null
  worktree: path|null
  order: resources_then_worktree
external_issue_action: close_after_cleanup|none
```

Use `merged` only after the hosting service confirms the merge and returns the
merge commit SHA. Use `cleanup.action: cleanup` and
`external_issue_action: close_after_cleanup` only with `status: merged`. A
blocked handoff must use `none` for both actions.
