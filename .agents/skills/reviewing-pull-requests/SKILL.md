---
name: reviewing-pull-requests
description: Use when independently reviewing an Orbit pull request.
---

# Reviewing Pull Requests

Review one Orbit pull request against its issue, governing product ADRs,
repository invariants, tests, and proof. Do not expand the requested contract.

## Steps

1. **Read.** Read the issue, pull request, diff, relevant ADRs, nearest
   `AGENTS.md`, and any supplied plan. Product feature diffs do not touch
   `apps/e2e` or `bin/e2e-*`.
2. **Update the checkout.** Merge current `main` into the pull-request worktree.
3. **Re-prove.** For `Proof: incus`, release any non-proof attempt and run
   `bin/e2e-topology prove <ISSUE>`. Require status `proved` for the exact
   current head. For a harness issue, run `bin/e2e-live <sha>`. For
   automated-only changes, require green CI and relevant local checks.
4. **Review the code.** Check correctness, acceptance coverage, regressions,
   repository conventions, and proof completeness. Collect every blocking
   finding in one pass. Each requested change must cite an issue criterion,
   ADR, existing invariant or test, or repository rule. A genuinely new
   requirement is separate work, not a blocker for this pull request.
5. **Report.** Post all blocking findings with concrete evidence. When there are
   none, post a review whose body is exactly `Approved.`

## Rules

- A new commit invalidates approval and exact-commit proof.
- Do not drip known findings across rounds.
- Do not merge, promote, release a proved topology, or modify the standby.
