---
name: reviewing-pull-requests
description: Use when reviewing an Orbit pull request.
---

# Reviewing Orbit Pull Requests

Approve only what you re-proved yourself.

## Steps

1. **Read.** The Linear issue and the pull request. The diff must stay inside
   the issue's components; a feature diff never touches `apps/e2e` or
   `bin/e2e-*`.
2. **Check out.** In the PR's worktree: `git merge main` (the proof must cover
   `main` plus this change).
3. **Re-prove.** For `Proof: incus`: `bin/e2e-topology release <ISSUE>` if a
   topology is live, then `bin/e2e-topology prove <ISSUE>`. Must be `proved`.
   For a harness issue (it changes `apps/e2e` or `bin/e2e-*` outside
   `apps/e2e/tests/Feature` and `apps/e2e/tests/Unit`): `bin/e2e-live <sha>`
   must pass. For automated-only issues: CI green.
4. **Read the code.** Correctness, tests that test the criteria, conventions
   from `AGENTS.md`. Ask for changes when something is wrong; say exactly what
   and where.
5. **Approve.** Post a review whose body is exactly `Approved.` Keep the proved
   topology alive for the merge agent; do not release it.

## Rules

- Any new commit after approval needs a new re-prove and approval.
- Do not merge; do not touch the standby.
