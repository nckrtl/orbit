---
name: merging-pull-requests
description: Use when asked to merge an approved Orbit PR or finish its cleanup.
---

# Merging Pull Requests

Merge the approved PR when the user has authorized it.

1. Confirm that the feature is complete, CI passes, blocking findings are resolved, and independent code and Incus review covers the current PR commit.
2. Confirm maintainer approval. Resolve a known correctness failure on main before merging unrelated work.
3. Merge the approved commit. Check and review any later changes or conflict resolutions first.
4. Verify the merge on GitHub and record the merge commit.
5. Preserve review evidence and clean up the feature's branch, clean worktree, and allocated resources. Follow the cleanup safeguards for retained Incus machines.

For cleanup after a merge, verify the merged PR and finish the remaining steps. The internal `bin/worktree-remove ISSUE` helper verifies the merge and cleans its worktree.

Report the merge commit and any cleanup still needed.
