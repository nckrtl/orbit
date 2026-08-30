---
name: merging-orbit-pull-requests
description: Use when performing the final merge gate for an approved Orbit pull request.
---

# Merging Orbit Pull Requests

Verify the exact approved candidate and merge it only when every gate passes.
The external project manager owns issue state, proof-topology release,
prepared-state refresh, worktree removal, issue closure, and all production
deployment work. The merge verifier performs no cleanup and no topology
mutation.

## Required input

Require the Linear issue contract, pull request URL, candidate commit SHA,
the reviewer handoff, the worker handoff with its proof record and
post-deployment actions, Compound disposition, and worktree path. Return
`blocked` without merging when an input or gate is missing.

## Verify and merge

1. Confirm that the issue was eligible when claimed, remains active, and has an
   unchanged contract. Confirm that the pull request targets `main`, is
   mergeable, and its current head equals the candidate SHA.
2. Confirm passing current-head CI for that SHA and that no newer or pending
   result invalidates it. A pending or failed run blocks the merge.
3. Confirm the reviewer handoff has `status: approved` for that SHA. Approval
   is either a formal GitHub approval from a review account different from the
   pull request author, or, when the review account is the same as the pull
   request author, a comment-type review from that account whose body is
   exactly `Approved.`. Reject any other comment, or a formal approval attempt
   that GitHub did not accept, as approval evidence. Any commit after approval
   requires a new review. Unresolved actionable review comments block the
   merge.
4. Verify the Linear scope and acceptance criteria against the diff and proof.
   Every linked or otherwise governing ADR must already be on `main`; an ADR
   introduced by the feature pull request blocks the merge.
5. For `Proof: incus`, read `bin/e2e-topology status ISSUE ATTEMPT --json`.
   Require an active proof topology whose proof record has status `proved`
   and whose candidate commit equals the current pull-request head. Require
   observed results for every acceptance check in that record. A released,
   diagnosis, or stale attempt blocks the merge. Do not release, diagnose,
   sync, or exec.
6. Confirm every `post_deployment_actions` entry has `target`, `operation`,
   `reason`, `recovery`, and `verification`.
7. Confirm a useful Compound update in the correct durable location, or a
   specific reason why the work produced no durable learning.
8. Re-read the pull request head and all gates immediately before merging. If
   they are unchanged and pass, create a merge commit. Use the hosting service's
   merge-commit method, equivalent to `gh pr merge --merge`.

Do not close the Linear issue, release or mutate any topology, remove the
worktree, or perform a production release. After a successful merge, signal
the project manager. It then, in this order:

1. releases the proof topology with `bin/e2e-topology release ISSUE ATTEMPT
   --json` and verifies its exact absence;
2. computes `bin/e2e-standby fingerprint --main-sha=SHA` for merged `main` and
   runs `bin/e2e-standby refresh --main-sha=SHA` only when the fingerprint
   changed;
3. removes the worktree with `bin/worktree-remove`; and
4. closes the Linear issue.

A refresh failure leaves proof absent and keeps the worktree and issue open
for maintenance triage. It does not revert merged code. Production release is
a separate cycle.

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
  proof: pass|fail|not-applicable|not-assessed
  post_deployment_actions: pass|fail|not-assessed
  compound: pass|fail|not-assessed
  final_check: pass|fail|not-assessed
blockers: []
cleanup:
  action: none
  proof_attempt: id|null
  worktree: path|null
  order: release_proof_then_refresh_then_worktree_then_issue
external_issue_action: close_after_release_refresh_and_worktree|none
standby:
  result: unchanged|promoted|failed|not-assessed
```

Use `merged` only after the hosting service confirms the merge and returns the
merge commit SHA. `cleanup.action` is always `none`; the merge verifier never
cleans topologies. Use `close_after_release_refresh_and_worktree` only with
`status: merged`. The project manager changes its own result to
`merged_refresh_blocked` when standby refresh fails. A blocked merge handoff
uses `none` for the external issue action.
