---
name: reviewing-pull-requests
description: Use when a fresh reviewer independently reviews an Orbit PR head.
---

# Reviewing Pull Requests

As a fresh reviewer, review one current pushed head against its issue, governing
ADRs, repository invariants, tests, and proof. Do not expand the requested
contract and do not edit code.

## Steps

1. **Read.** Read the issue, pull request, exact current pushed head, diff,
   relevant ADRs, nearest `AGENTS.md`, and supplied Feature plan. Product feature
   diffs do not touch `apps/e2e` or `bin/e2e-*`.
2. **Update the checkout.** Merge current `main` into the PR worktree.
3. **Re-prove.** For `Proof: incus`, release any non-proof attempt and run
   `bin/e2e-topology prove <ISSUE>`. Require `proved` for the exact current head.
   For a harness issue run `bin/e2e-live <sha>`. For automated-only changes,
   require green CI and relevant local checks.
4. **Review the code.** Check correctness, acceptance coverage, regressions,
   repository conventions, and proof completeness. Collect every blocking
   finding in one pass. Each finding must cite an issue criterion, ADR, existing
   invariant/test, or repository rule. A new requirement is separate work.
5. **Report.** Post all blocking findings with evidence. When none exist, post a
   review whose body is exactly `Approved.`

## Rules

- A new commit invalidates approval and exact-commit proof; it requires another
  fresh reviewer session.
- Do not drip known findings across rounds.
- Do not reuse a prior reviewer conversation for a new pushed head.
- Do not merge, promote, release a proved topology, or modify the standby.
