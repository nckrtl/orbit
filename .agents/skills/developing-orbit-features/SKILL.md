---
name: developing-orbit-features
description: Use when implementing or resuming a Ready Orbit Linear issue anywhere in the Orbit monorepo from a prepared feature worktree.
---

# Developing Orbit Features

Own Work, Compound, discovery, candidate proof, the pull request, and review
corrections. The external project manager owns issue state, the worktree,
topology creation and release requests, merge, post-merge cleanup, and issue
closure. The feature worker owns its internal implementation subagents. The
flow is governed by
[ADR 0006](../../../docs/decisions/0006-topology-led-feature-development.md).

## Repository scope

This root-owned skill is the implementation workflow for the repository root
and every project below it, including `apps/cli`, `apps/gateway`,
`packages/php-sdk`, and `apps/e2e`. Invoke it once per issue from the monorepo
worktree root, even when the issue changes only one contained project.

Root and project-local `AGENTS.md`, rules, and domain skills add constraints for
the files they cover. They do not replace this skill or its handoff contract.

## Required input

Require the Linear issue contract, prepared clean worktree, branch, and any
existing pull request or review comments. Confirm that:

- the issue was Ready when claimed, or remains active with an unchanged
  contract when work resumes;
- its outcome, scope, acceptance criteria, components, and ADR links are
  complete;
- every linked or otherwise governing ADR is already on `main`; and
- a `Proof: incus` issue names a supported `Composition`, or adding that
  support is in scope.

Return `blocked` without changing code when a required input or gate is absent.

## Orchestrate for throughput

Act as the implementation orchestrator. Own decomposition, integration,
cross-project consistency, final verification, Compound, the pull request, and
the handoff. Delegate bounded implementation and focused tests whenever they
can run independently. The worker can use subagents during discovery and
hardening.

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
for one subagent before starting another independent task.

Give each subagent:

- the issue contract and its assigned acceptance criteria;
- the prepared worktree and exact project path;
- exclusive file or module ownership;
- the root and nearest project guidance and the domain skills it must use;
- the focused checks it must run;
- the active discovery attempt ID when it may run commands on the topology; and
- notice that other agents share the worktree, so it must preserve their edits
  and must not commit, push, update the pull request, or revert unrelated work.

Require each subagent to return changed paths, commands and results, unresolved
risks, and blockers. Review each result before accepting it. Refill a free slot
immediately with the next unblocked implementation or correction task.

The orchestrator may edit code for small integration seams, tightly coupled
work that cannot be split safely, or a task whose delegation overhead exceeds
its implementation time.

## Flow

Follow these steps in this order. Steps 3 to 9 apply to `Proof: incus` issues.
Automated-only work skips them and continues at step 10 with
`venue: automated`.

1. **Prepare issue.** The issue author prepares the Linear issue with
   `.agents/skills/creating-orbit-issues`.
2. **Claim issue.** The project manager claims the Ready issue, creates the
   clean worktree, and starts this worker. Read the root and nearest project
   `AGENTS.md` files, relevant rules, and required local skills. Complete each
   changed project's guidance bootstrap before editing its files. Inspect
   `/home/nckrtl/orbit-old` for applicable prior implementation before
   codifying behavior.
3. **Select recipe.** Map the Linear `Composition` to repository-supported
   Gateway node types and the `apps/e2e` recipe. The supported recipe is
   `gateway_app-dev_app-prod`. An unsupported requirement blocks normal
   feature work; return `blocked`.
4. **Create discovery.** Request a discovery attempt from the project manager.
   It runs `bin/e2e-topology acquire ISSUE WORKTREE --json` and returns the
   attempt ID. Discovery mounts the worktree read-write at `/home/orbit/orbit`
   on `gateway` and `app-dev`, so every host edit is live in both guests.
   Guests never run composer in discovery; host `bin/bootstrap` owns vendor.
5. **Learn desired state.** Change code and task-owned guest state until the
   topology shows the required behavior. Run exact commands with
   `bin/e2e-topology exec ISSUE ATTEMPT ROLE --argv='["program","arg"]' --json`
   or, when the command needs stdin, `--argv-file=PATH` where the file holds
   `{"argv":[...],"stdin":null}`; the two options are mutually exclusive. The
   vector runs as the orbit user through `env`, so `argv[0]` must resolve on
   the guest `PATH` or be an absolute path; the Orbit CLI is not on that
   `PATH`. Example:

   ```bash
   bin/e2e-topology exec NCK-82 ATTEMPT app-dev \
     --argv='["/home/orbit/orbit/apps/cli/orbit","doctor","--json"]' --json
   ```

   Use `bin/e2e-topology verify ISSUE ATTEMPT --json` and
   `bin/e2e-topology status ISSUE --json` to inspect. Discovery output is not
   proof evidence.
6. **Codify required state.** Every manual action from discovery becomes one
   of: repository behavior, proof setup in the proof plan, a
   `post_deployment_action`, or diagnostic-only work that is not needed later.
   Repository behavior owns all product state.
7. **Harden candidate.** Implement only the issue scope. Use test-driven
   development for behavior. Integrate subagent changes continuously and check
   cross-project contracts. Compound only reusable learning into the correct
   existing ADR, reference, solution, or rule; give a specific reason when no
   durable update is useful. Map each acceptance criterion to a focused
   acceptance check. Run each changed project's `composer check` and root
   `bin/test`. Commit the clean candidate. That commit is the code freeze.
   A diagnosis round that changes code moves the code freeze to the new
   commit; rerun the gates on it before the next proof. The proof plan and
   the fixtures under `apps/e2e/resources/proof/<issue>/` may change between
   rounds, because they are proof input rather than product state.
8. **Remove discovery.** Request discovery release from the project manager.
   It runs `bin/e2e-topology release ISSUE ATTEMPT --json`. Wait for verified
   absence before proof. Proof cannot start while a discovery attempt exists.
9. **Prove fresh.** Write the proof plan file:

   ```json
   {
     "setup": [{"id": "text", "node": "gateway", "argv": [], "timeout_seconds": 60}],
     "acceptance": [{"id": "text", "node": "app-dev", "argv": [], "timeout_seconds": 60}],
     "post_deployment_actions": [
       {"target": "text", "operation": "text", "reason": "text", "recovery": "text", "verification": "text"}
     ]
   }
   ```

   Put proof-only scripts and data in `apps/e2e/resources/proof/<issue>/` and
   commit them with the candidate; `prove` stages them root-owned at
   `/var/lib/orbit-e2e/proof/<name>` on every role, including `app-prod`, and
   the record lists the staged digest per role under `proof_fixtures`. Call
   the one-shot proof command for the exact candidate:
   `bin/e2e-topology prove ISSUE WORKTREE --candidate-sha=SHA --proof-plan-file=PATH --json`.
   The harness creates a fresh proof attempt, synchronizes the exact commit
   from Git, verifies clean guest checkout identity, stages the fixtures,
   converges, runs setup and acceptance, and records the proof. Proof never
   mounts the worktree. The output carries the record without its plan; a
   failed proof becomes `diagnosis` and the output ends with `failed_action`
   (`id`, `node`, `exit_code`, `stdout_tail`, `stderr_tail`). Inspect it,
   release it, and prove again after a fix. A proved attempt is immutable; do
   not sync, exec, or change it.
10. **Open a normal pull request.** Push the candidate and create the pull
    request from its template only after proof succeeds. Orbit does not use
    draft pull requests. CI and independent review start immediately and run
    in parallel. Return `review_ready`.
11. **Handle corrections.** On review resumption, assign every unresolved
    comment in the same worktree. Any new commit changes the pull-request head,
    so the prior proof is stale. Move the old proof to diagnosis with
    `bin/e2e-topology diagnose ISSUE ATTEMPT --json` only when it helps the
    investigation. Request release of the old topology, rerun the local gates
    from step 7, complete fresh proof from step 9, and then push the new
    candidate. After the push, post one pull request conversation comment for
    that candidate SHA and return `changes_addressed`:

    ```text
    Review feedback addressed in <full-sha>. Ready for re-review.
    ```

12. **Merge.** The merge verifier uses
    `.agents/skills/merging-orbit-pull-requests`.
13. **Clean development resources.** The project manager releases the proof
    topology, refreshes prepared state when needed, removes the worktree, and
    closes the issue.
14. **Deploy separately.** The production deployment process deploys the
    merged code and performs and verifies `post_deployment_actions`.

Do not approve, merge, close the issue, remove the worktree, release a proved
attempt while its pull request is open, or mutate standby or unrelated Incus
resources.

## Handoff

Return this YAML block. `review_ready` requires the normal pull request and
valid proof for `candidate_sha`. CI fields may remain `null` while CI runs.

```yaml
role: feature-worker
status: review_ready|changes_addressed|blocked
issue: NCK-123|null
pull_request: URL|null
candidate_sha: full-sha|null
proof:
  venue: automated|incus
  topology: gateway_app-dev_app-prod|null
  attempt_id: id|null
  status: proved|not-applicable
  acceptance:
    - criterion: text
      evidence: text
post_deployment_actions:
  - target: text
    operation: text
    reason: text
    recovery: text
    verification: text
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

Use `venue: automated`, `topology: null`, `attempt_id: null`, and
`status: not-applicable` for automated-only work. Use `changes_addressed`
after a correction is proved and pushed. Do not report the feature as
complete. The development cycle ends after merge and external cleanup.
