---
name: developing-orbit-features
description: Use when implementing or resuming work on a Ready Orbit Linear issue in a prepared feature worktree.
---

# Developing Orbit Features

Own Work and Compound until the pull request is ready for independent review.
The external orchestrator owns issue state, worktree and Incus lifecycle, agent
sessions, merge, and cleanup.

## Required input

Require the Linear issue contract, prepared clean worktree, branch, and any
existing pull request or review comments. Confirm that:

- the issue is Ready and its outcome, scope, acceptance criteria, components,
  ADR links, and proof venue are complete;
- every linked ADR is already on `main`; and
- an Incus proof venue names a registered profile and exact checkout roles.

Return `blocked` without changing code when a required input or gate is absent.

## Work

1. Read the root and nearest project `AGENTS.md` files and relevant rules.
2. Inspect the existing implementation and tests. On review resumption, address
   every unresolved comment in the same worktree and pull request.
3. Implement only the issue scope. Use test-driven development for behavior.
4. Map each acceptance criterion to focused proof. Run each changed project's
   `composer check` and root `bin/test`. Record commands, results, test and
   assertion counts, and the candidate commit SHA.
5. Compound only reusable learning into the correct existing ADR, reference,
   solution, or rule. Do not introduce a required ADR in the feature pull
   request. Give a specific reason when no durable update is useful.
6. Create or update the pull request from its template. Wait for current-head
   CI. Fix failures before the review handoff.

Do not approve, merge, close the issue, remove the worktree, or release Incus.

## Handoff

Return this YAML block. Use `review_ready` only when the pull request and its
current-head CI are ready for independent review.

```yaml
role: feature-worker
status: review_ready|blocked
issue: NCK-123|null
pull_request: URL|null
branch: branch-name
head_sha: full-sha
components: []
proof:
  venue: automated|incus
  acceptance:
    - criterion: text
      evidence: text
  checks:
    - command: text
      result: text
  ci_url: URL|null
  ci_sha: full-sha|null
compound:
  disposition: updated|not-needed|not-assessed
  paths: []
  reason: text
blockers: []
```

Do not report the feature as complete. The development cycle ends after merge
and external cleanup.
