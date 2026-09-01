---
name: merging-pull-requests
description: Use when merging an approved Orbit pull request.
---

# Merging Pull Requests

Merge one approved pull request and clean up repository-owned development
resources.

## Steps

1. **Verify the candidate.** The pull request targets `main`, CI is green for
   the current head, an `Approved.` review applies to that head, and no
   actionable review comments remain. For `Proof: incus`, require an active
   proved topology whose recorded candidate equals `main` plus the current head.
   If `main` moved, stop until current-head proof exists.
2. **Merge.** Run `gh pr merge <n> --merge` and verify the merge commit on
   `origin/main`.
3. **Promote or refresh.** For Incus proof, run
   `bin/e2e-standby promote <ISSUE>`. If promotion is invalid because the proof
   plan mutates state or `main` differs, run `bin/e2e-standby refresh` instead.
   For a harness change, refresh the primary standby with the merge SHA.
4. **Clean repository resources.** Run
   `bin/worktree-remove <ISSUE> <slug>`, then verify topology, worktree, and local
   branch cleanup.

Do not report success from command exit status alone; verify GitHub, `origin/main`,
standby identity, and cleanup state directly.
