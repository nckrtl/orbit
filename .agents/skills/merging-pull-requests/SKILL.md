---
name: merging-pull-requests
description: Use when merging an approved Orbit pull request and cleaning up.
---

# Merging Orbit Pull Requests

## Steps

1. **Gates.** PR targets `main`, CI green on the head, an `Approved.` review on
   the head, no open review comments. For `Proof: incus`, the reviewer's
   topology is alive and proved on `main` + head (`bin/e2e-topology status
   <ISSUE>`). If `main` moved since that proof, ask the reviewer to re-prove.
2. **Merge.** `gh pr merge <n> --merge`.
3. **Promote.** For `Proof: incus`: `bin/e2e-standby promote <ISSUE>` snapshots
   the reviewer's topology as the new standby generation. If it refuses (plan
   marked `mutates`, or `main` differs), run `bin/e2e-standby refresh` instead.
   For a harness issue: `bin/e2e-standby refresh --main-sha=<merge sha>` on
   the primary checkout (`bin/e2e-live` promoted into the validation clone's
   standby, not the primary's).
4. **Clean up.** `bin/worktree-remove <ISSUE> <slug>` (releases the topology,
   deletes the worktree and its `.e2e/`). Close the Linear issue.

One PR at a time from step 1 to step 3.
