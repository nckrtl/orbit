---
name: developing-orbit-features
description: Use when implementing or resuming a Ready Orbit Linear issue anywhere in the Orbit monorepo from a prepared feature worktree.
---

# Developing Orbit Features

Own Work and Compound until the pull request is ready for independent review.
The external orchestrator owns issue state, worktree and Incus lifecycle,
top-level role sessions, merge, and cleanup. The feature worker owns its
internal implementation subagents.

## Repository scope

This root-owned skill is the implementation workflow for the repository root
and every project below it, including `apps/cli`, `apps/gateway`, and
`packages/php-sdk`. Invoke it once per issue from the monorepo worktree root,
even when the issue changes only one contained project.

Root and project-local `AGENTS.md`, rules, and domain skills add constraints for
the files they cover. They do not replace this skill or its handoff contract.

## Required input

Require the Linear issue contract, prepared clean worktree, branch, and any
existing pull request or review comments. Confirm that:

- the issue was Ready when claimed, or remains active with an unchanged
  contract when work resumes;
- its outcome, scope, acceptance criteria, components, ADR links, and proof
  venue are complete;
- every linked ADR is already on `main`; and
- an Incus proof venue names a registered profile and exact checkout roles.

Return `blocked` without changing code when a required input or gate is absent.

## Orchestrate for throughput

Act as the implementation orchestrator. Own decomposition, integration,
cross-project consistency, final verification, Compound, the pull request, and
the handoff. Delegate bounded implementation and focused tests whenever they
can run independently.

Keep every available collaboration slot filled while useful independent work
exists. Spawn implementation subagents with model `gpt-5.6-luna` and
`reasoning_effort: low`. The UI calls this combination Luna Light; `low` is the
programmatic effort value. Use a `default` agent and `fork_turns: none` so the
model override takes effect, then include all needed context in its task. Keep
agent spawning and task routing centralized in the implementation orchestrator.

Before spawning, map acceptance criteria and components into the smallest
independent work items. Prefer vertical slices in which one subagent owns both
behavior and its focused tests. Establish shared contracts first when one task
depends on another, then start every unblocked task in parallel. Do not wait
for one subagent before starting another independent task. Do not serialize
independent component work, research, review corrections, or verification.

Give each subagent:

- the issue contract and its assigned acceptance criteria;
- the prepared worktree and exact project path;
- exclusive file or module ownership;
- the root and nearest project guidance and the domain skills it must use;
- the focused checks it must run; and
- notice that other agents share the worktree, so it must preserve their edits
  and must not commit, push, update the pull request, or revert unrelated work.

Require each subagent to return changed paths, commands and results, unresolved
risks, and blockers. While subagents run, do unassigned orchestration or
integration work. Review each result before accepting it. Refill a free slot
immediately with the next unblocked implementation or correction task.

The orchestrator may edit code for small integration seams, tightly coupled
work that cannot be split safely, or a task whose delegation overhead exceeds
its implementation time. Keep meaningful independent implementation in Luna
Light subagents.

## Work

1. Read the root and nearest project `AGENTS.md` files, relevant rules, and
   required local skills. Complete each changed project's guidance bootstrap
   before editing its files.
2. Inspect the existing implementation and tests, build the dependency graph,
   and dispatch all unblocked work. On review resumption, assign every
   unresolved comment in the same worktree and pull request.
3. Implement only the issue scope. Use test-driven development for behavior.
   Integrate subagent changes continuously and check cross-project contracts.
4. Map each acceptance criterion to focused proof. Run independent focused and
   per-project checks in parallel when they do not mutate shared files or
   contend for the same service. After integration is stable, run each changed
   project's `composer check` and root `bin/test`. Record commands, results,
   test and assertion counts, and the candidate commit SHA.
5. Compound only reusable learning into the correct existing ADR, reference,
   solution, or rule. Do not introduce a required ADR in the feature pull
   request. Give a specific reason when no durable update is useful.
6. Create or update the pull request from its template. Wait for current-head
   CI. Dispatch independent failures in parallel and fix all failures before
   the review handoff.
7. On review resumption, after the requested changes are committed and pushed
   and current-head CI passes, post one pull request conversation comment for
   that candidate SHA:

   ```text
   Review feedback addressed in <full-sha>. Ready for re-review.
   ```

   Post it once per candidate SHA and record its URL in the handoff.

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
review_comment_url: URL|null
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

Set `review_comment_url` to the review-response comment URL after review
resumption. Use `null` for the initial review handoff and when no comment was
posted before a blocked handoff.

Do not report the feature as complete. The development cycle ends after merge
and external cleanup.
