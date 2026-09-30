---
name: merging-pull-requests
description: Use when asked to merge an approved Orbit PR or finish its cleanup.
---

# Merging Pull Requests

Merge the approved PR when the user has authorized it.

1. Confirm that the feature is complete, CI passes, blocking findings are resolved, and independent code and Incus review covers the current PR commit.
2. Confirm maintainer authorization. For an Orbit task PR, delegated final review and merge supplies authorization for that named work. Follow the [final review workflow](../../../docs/reference/implementation-loop.md#final-review-of-an-orbit-task-pull-request): require a formal GitHub `APPROVED` review from the designated final reviewer for the current head SHA, with verification results, limitations, evidence links, and the verdict in its body. Verify reviewer identity, review state, and `commit_id` from GitHub's records. A plain comment, dismissed review, approval for another head, outstanding requested changes from the final reviewer, or unreadable review data stops the merge. Publish the review only when final review is authorized. Resolve a known correctness failure on main before merging unrelated work.
3. For an Orbit task PR, use the maintainer's GitHub CLI profile. Confirm `Required checks` succeeded on the reviewed head, re-read the head, and run `gh pr merge <pr-url> --merge --match-head-commit <reviewed-sha>`. A missing, unreadable, pending, or failed required check stops the merge. A changed head or conflict resolution needs another review and formal approval. The account has admin bypass, so check these gates yourself. Do not enable auto-merge or use `--admin` to skip the workflow. For other authorized PRs, merge the approved commit after checking and reviewing any later changes.
4. Verify the merge on GitHub and record the merge commit.
5. Preserve review evidence and clean up the feature's branch, clean worktree, and allocated resources. Follow the cleanup safeguards for retained Incus machines.

For cleanup after a merge, verify the merged PR and finish the remaining steps. The internal `bin/worktree-remove ISSUE` helper verifies the merge and cleans its worktree.

Report the merge commit and any cleanup still needed.

The Tasks scheduler completes a merged task on its next tick. This workflow adds no `orbit tasks:merge` command and changes no ruleset or App permission.
