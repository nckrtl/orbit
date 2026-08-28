---
name: developing-orbit-features
description: Use when implementing or resuming a Ready Orbit Linear issue anywhere in the Orbit monorepo from a prepared feature worktree.
---

# Developing Orbit Features

Own Work, Compound, and task-owned proof-resource cleanup through both staged
review handoffs. The external orchestrator owns issue state, worktree lifecycle,
top-level role sessions, merge, and post-merge absence verification. The feature
worker owns its internal implementation subagents.

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
- every linked or otherwise governing ADR is already on `main`; and
- a `live` proof venue names exact applicable nodes, access method, and defined
  checkout identity evidence.

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
3. For live proof, use `orbit node:list --json` for read-only preflight. Inspect
   the gap with Orbit CLI, Gateway API, or pinned direct SSH. Record the intended
   nodes, ownership baseline, recovery plan, task-owned resources, and rollout
   intent. Identify whether each recovery artifact is task-owned or pre-existing.
   Do not deploy a candidate or perform any other live mutation yet.
   Inspect `/home/nckrtl/orbit-old` for applicable prior implementation before
   codifying the behavior.
4. Implement only the issue scope. Use test-driven development for behavior.
   Integrate subagent changes continuously and check cross-project contracts.
5. Compound only reusable learning into the correct existing ADR, reference,
   solution, or rule. Do not introduce a required ADR in the feature pull
   request. Give a specific reason when no durable update is useful.
6. Map each acceptance criterion to focused proof. Run independent focused and
   per-project checks in parallel when they do not mutate shared files or
   contend for the same service. Run each changed project's `composer check`
   and root `bin/test`. Record commands, results, test and assertion counts, and
   the candidate commit SHA. Commit and push the clean candidate, create or
   update the pull request from its template, and wait for current-head CI.
   Dispatch independent failures in parallel and fix all failures before a
   review handoff.
7. For live proof, request an independent `pre_rollout` review only after step 6
   passes. Do not roll out or mutate live state until the reviewer returns
   `rollout_approved` for the exact candidate SHA. Any later commit invalidates
   that review and requires step 6 plus a new pre-rollout review.
8. Immediately before every rollout command and every other live mutation, run
   and inspect `orbit node:list --json`. Select the exact node by numeric ID and
   compare its name, roles, active state, access method, and ownership baseline
   with the issue and proof record. A prior listing cannot authorize a later
   mutation. Abort without mutation on any identity, topology, access,
   applicability, or ownership drift. Mutate one node at a time. For every
   mutation, record the fresh node-list request or snapshot, intended change,
   task-owned resources, pre-state, recovery action, result, and cleanup. Verify
   checkout identity and service health before the next mutation.
9. After live proof, restore the documented pre-state, remove every task-owned
   proof resource, including task-owned recovery artifacts, and verify absence
   against the ownership record. Do not remove a shared or pre-existing recovery
   artifact. Fail closed on uncertain ownership, a remaining task resource, or
   any shared or pre-existing state drift. Update the pull request evidence,
   then request an independent `post_proof` review of the exact candidate.
   Automated proof goes
   directly to `post_proof` review after step 6. Only `approved` is final merge
   approval. Any code commit after either review returns to step 6; live proof
   then also requires a new pre-rollout review and repeated affected proof.
10. On review resumption, after the requested changes are committed and pushed
   and current-head CI passes, post one pull request conversation comment for
   that candidate SHA:

   ```text
   Review feedback addressed in <full-sha>. Ready for re-review.
   ```

   Post it once per candidate SHA and record its URL in the handoff.

Do not approve, merge, close the issue, remove the worktree, or mutate or
remove shared live topology resources. Remove only recorded task-owned proof
resources during step 9.

## Handoff

Return this YAML block. Use `review_ready` only when the pull request and its
current-head CI are ready for independent review.

```yaml
role: feature-worker
status: review_ready|blocked
review_phase: pre_rollout|post_proof|null
issue: NCK-123|null
pull_request: URL|null
branch: branch-name
head_sha: full-sha
review_comment_url: URL|null
components: []
proof:
  venue: automated|live
  topology: none|registered-live
  nodes:
    - id: number
      name: text
      roles: []
      access: [orbit|gateway|ssh]
      ssh_host_key_fingerprint: SHA256:text|null
      candidate_checkout_path: path|null
      candidate_sha: full-sha|null
      deployed_checkout_path: path|null
      deployed_sha: full-sha|null
      pre_state: text
      recovery: text
      post_state: text
      cleanup: text
  rollout: serial|not-applicable
  mutations:
    - candidate_sha: full-sha
      node_id: number
      node_list_request_id: text
      mutation: text
      task_owned_resources: text
      pre_state: text
      recovery: text
      result: text
      cleanup: text
  task_owned_cleanup:
    resources: text
    absence_verification: text
    live_drift: none|text
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

Set `review_phase` to the review event requested by this handoff. Use `null`
only when blocked before a review phase can be requested. Set
`review_comment_url` to the review-response comment URL after review resumption.
Use `null` for the initial review handoff and when no comment was posted before
a blocked handoff. Use an empty `mutations` list for automated proof and before
live rollout. A blocked handoff must not assert identity from a stale topology
listing; use an empty `nodes` list when current identity cannot be verified and
record the missing revalidation in `blockers`.

Do not report the feature as complete. The development cycle ends after merge
and external cleanup.
