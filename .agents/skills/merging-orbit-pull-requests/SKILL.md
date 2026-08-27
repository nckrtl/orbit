---
name: merging-orbit-pull-requests
description: Use when performing the final merge gate for an approved Orbit pull request.
---

# Merging Orbit Pull Requests

Verify the exact approved candidate and merge it only when every gate passes.
The external orchestrator owns issue state, Incus and worktree cleanup, and all
deployment work.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
reviewer handoff, proof evidence, Compound disposition, worktree path, and any
Incus topology identifier. Return `blocked` without merging when an input or gate
is missing.

## Verify and merge

1. Confirm that the issue was eligible when claimed, remains active, and has an
   unchanged contract. Confirm that the pull request targets `main`, is
   mergeable, and still points to the candidate SHA.
2. Confirm that every required check passed for that SHA and that no newer or
   pending result invalidates it.
3. Confirm independent approval of that SHA. Any commit after approval requires
   a new review. Unresolved actionable review comments block the merge.
4. Verify the Linear scope and acceptance criteria against the diff and focused
   proof. A required ADR must already be on `main`; an ADR introduced by the
   feature pull request blocks the merge.
5. Confirm that automated or Incus proof matches the issue venue and candidate
   SHA. Incus proof also requires the registered profile and exact checkout-role
   evidence specified by the issue.
6. Confirm a useful Compound update in the correct durable location, or a
   specific reason why the work produced no durable learning.
7. Re-read the pull request head and all gates immediately before merging. If
   they are unchanged and pass, create a merge commit. Use the hosting service's
   merge-commit method, equivalent to `gh pr merge --merge`.

Do not close the Linear issue, release Incus, remove the worktree, or deploy.
After a successful merge, signal the external orchestrator. It cleans Incus
before the worktree, then closes the issue. Deployment is a separate cycle.

## Handoff

Return this YAML block:

```yaml
role: merge-verifier
status: merged|blocked
issue: ORB-123|null
pull_request: URL|null
candidate_sha: full-sha|null
merge_sha: full-sha|null
gates:
  intake: pass|fail|not-assessed
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
  incus_topology: id|null
  worktree: path|null
  order: incus_then_worktree
external_issue_action: close_after_cleanup|none
```

Use `merged` only after the hosting service confirms the merge and returns the
merge commit SHA. Use `cleanup.action: cleanup` and
`external_issue_action: close_after_cleanup` only with `status: merged`. A
blocked handoff must use `none` for both actions.
