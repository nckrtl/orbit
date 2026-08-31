---
name: reviewing-pull-requests
description: Use when reviewing an Orbit pull request.
---

# Reviewing Pull Requests

Approve only what you re-proved yourself. Judge the candidate against the
unchanged Linear issue, governing ADRs, existing repository invariants, and the
approved `.orbit/plan.md`; do not expand the feature contract during review.

## Steps

1. **Read.** Read the Linear issue, pull request, approved preflight, and diff.
   The diff must stay inside the issue's components and preflight boundaries; a
   feature diff never touches `apps/e2e` or `bin/e2e-*`.
2. **Check out.** In the PR's worktree: `git merge main` (the proof must cover
   `main` plus this change).
3. **Re-prove.** For `Proof: incus`: `bin/e2e-topology release <ISSUE>` if a
   topology is live, then `bin/e2e-topology prove <ISSUE>`. Must be `proved`.
   For a harness issue (it changes `apps/e2e` or `bin/e2e-*` outside
   `apps/e2e/tests/Feature` and `apps/e2e/tests/Unit`): `bin/e2e-live <sha>`
   must pass. For automated-only issues: CI green.
4. **Read the code.** Check correctness, acceptance coverage, regressions,
   conventions from `AGENTS.md`, and adherence to the approved boundaries.
   Collect all blocking findings before posting. Every requested change must
   cite the issue criterion, ADR, existing invariant/test, or repository rule
   it violates. A genuinely new requirement becomes separate Linear work and
   is not a blocker for this PR.
5. **Approve.** When there are no blocking findings, post a review whose body is
   exactly `Approved.` Keep the proved topology alive for the merge agent; do
   not release it.

## Rules

- Any new commit after approval needs a new re-prove and fresh reviewer pass.
- Do not drip known findings across rounds.
- Do not merge; do not touch the standby.
