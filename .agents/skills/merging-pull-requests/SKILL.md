---
name: merging-pull-requests
description: Use to close out an Orbit pull request after the external orchestrator merges its exact approved head.
---

# Closing Out Pull Requests

Close out one independently approved pull request after the external orchestrator merges it. This task does not review, approve, or merge.

1. **Verify the candidate.** Require one independent `Approved.` review bound to the exact head and published artifact SHA, current main included in that head, green CI for it, no actionable findings, and no later commit. The candidate carries no `.loop/` entries. The artifact commit has the candidate as its only parent and adds only `.loop/` paths. A changed head needs fresh approval, CI, and an evidence decision. Never create an artifact removal commit.
2. **Verify the external merge.** Require authoritative read-only GitHub state to report that this head merged into main. Fetch `origin/main`. Require the recorded merge commit to have the approved head as its exact second parent and the same tree. Missing or mismatched lineage is a stop.
3. **Verify acceptance evidence.** For `proof:incus`, inspect the captured result, topology identity, input manifest, exact plan and fixtures, and every declared action's zero exit. Require exact or equivalent evidence for the approved head and included main. When the report selects candidate convergence, require its successful result bound to that head and report. The successful VMs may already be released. Never substitute snapshot refresh for missing acceptance proof.
4. **Refresh the snapshot.** Locate the primary checkout with `git worktree list --porcelain`. If its clean main is an ancestor of fetched `origin/main`, advance it with `git -C <primary> merge --ff-only origin/main`. With detached HEAD equal to origin/main, update local main with `git -C <primary> branch -f main origin/main`. Read HEAD, main, and origin/main back. Any other mismatch is a stop. For Incus issues, run `bin/e2e-topology-snapshot refresh --main-sha=<current origin/main>`. Require that main contains the verified merge. A failed refresh retains captured evidence and requires retry; it does not undo the merge. Record proved attempt, artifact SHA, accepted head, merge commit, and resulting generation in the closeout handoff. Follow any extra issue-specific harness proof contract.
5. **Clean resources.** If successful proof or discovery leases remain, capture and release proof with `bin/e2e-topology release <ISSUE> --proof --capture`, then release discovery. Release any candidate-convergence attempt with `--candidate`. Run `bin/worktree-remove <ISSUE> <slug>`. Verify the snapshot identity, absence of issue resources and local feature branch, and absence of `.loop/` from main. Keep artifact refs and the primary checkout's evidence archive. Return documentation findings for separate handling.

Run mutations as bounded commands and verify their resulting state. GitHub evidence is read-only. Approval, current-main integration, complete acceptance proof, merge lineage, snapshot convergence, and cleanup remain required. See ADRs 0049 and 0050.
