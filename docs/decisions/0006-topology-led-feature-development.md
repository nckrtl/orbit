# ADR 0006: Use topology-led disposable feature development

## Status

Superseded on 2026-08-30 by [ADR 0007](0007-nine-step-feature-flow.md). Kept as
the record of the topology-led decision and its ceremony.

Amended on 2026-08-30 (before supersession): proof plans moved to
`proofs/<ISSUE>.json`; harness state moved to `<worktree>/.e2e/` and
`<primary>/.e2e/`; the reviewer re-proves and the merge agent promotes the
reviewer's topology; YAML handoffs, gate checklists, evidence archives, and the
rolling acceptance suite were withdrawn.

This decision supersedes the shared-live feature-development boundary of
[ADR 0002](0002-candidate-deployment-proof-boundary.md): its shared-live
rollout, pre-rollout review, live mutation, restored-pre-state, and post-proof
review requirements no longer govern development proof. ADR 0002's ownership
and production-separation principles remain in force and are restated below.
[ADR 0005](0005-rolling-incus-development-topology.md) provides the prepared
topology that this decision builds on.

## Context

ADR 0002 proved candidate code on registered shared live nodes. That venue
forced a serial rollout, two review events, a recorded pre-state for every
mutation, and a restore step before final approval. Each rule existed because
the nodes were shared and the proof state could outlive the feature.

ADR 0005 replaced that venue for Incus-backed work. It gives each feature a
disposable, issue-specific `gateway_app-dev_app-prod` topology on an isolated
network, acquired from a prepared standby generation. Live acceptance of that
harness on 2026-08-29 showed that a fresh three-node topology is cheap. A
disposable topology also has no pre-state to restore and no shared owner to
protect. The ADR 0002 rules that guard shared state therefore add cost without
adding safety.

Two needs remain. The agent must learn what the code must become by changing a
live topology freely. The reviewer and merge verifier must trust that proof
shows the exact candidate and nothing else. One topology cannot serve both
needs: discovery state must not leak into proof.

## Decision

### Discover on one disposable topology

Every feature that needs Incus proof uses two separate disposable attempts on
the profile from ADR 0005. Each attempt has its own attempt ID and its own
exact Incus resources. The purpose is `discovery` or `proof`. Only one attempt
is active for an issue at a time.

Discovery is intentionally flexible. The implementation agent changes code and
task-owned guest state until the topology shows the required behavior. It can
run exact commands on the topology's nodes and use subagents at any point.

Discovery mounts the feature worktree. The harness attaches the worktree to
the checkout roles (`gateway` and `app-dev`) with an Incus virtiofs disk
device, so every host edit is live in both guests without a transfer step. The
device is part of the attempt inventory, and exact release removes it. Proof
never mounts host state; it synchronizes the exact candidate commit from Git.
This amends the ADR 0005 rule that the host worktree is never mounted into a
VM: that rule now applies to proof only.

Discovery passes when the topology demonstrates the required behavior, the
agent understands every required state change, repository behavior owns all
product state, and every remaining manual action has one disposition:
repository behavior, declared proof setup, a `post_deployment_actions` entry,
or diagnostic-only work. Discovery output is not proof evidence.

The agent then hardens the candidate: implementation, tests, affected project
checks, root checks, quality checks, and useful Compound work. The clean
candidate commit is the code freeze.

The discovery topology is released and its exact absence is verified before
proof starts. This verified cleanup is a hard gate.

### Prove the exact commit on a fresh topology

Proof is one harness-owned operation. The agent does not drive each step, and
later worktree edits cannot affect it. The harness:

1. creates a new proof topology with its own attempt ID and Incus resources;
2. synchronizes the exact candidate commit from Git;
3. verifies clean guest checkout identity at that commit;
4. runs repository convergence;
5. runs the declared proof setup;
6. runs the acceptance checks; and
7. records the result.

The proof record binds the attempt ID, candidate commit, topology recipe,
declared proof actions, the observed result of every acceptance check, and a
status of `proved` or `diagnosis`.

The harness can retry one transport failure that occurs before clean guest
checkout identity is verified. Any later failure, or a second transport
failure, moves the topology to `diagnosis`. A diagnosis topology can be
inspected and changed. It cannot become proved. It must be released before
another proof attempt starts.

### Keep successful proof immutable through merge

A proved topology stays immutable through review and merge. The harness
rejects sync, exec, and state changes on a proved attempt. Moving proved to
diagnosis is one-way. Releasing a proved topology before merge makes its proof
unusable. The TTL reaper does not release a proved topology while its pull
request is open.

A corrective commit changes the pull-request head, so the prior proof is stale
automatically. No separate invalidation state or command is required. The
agent releases the old topology and completes fresh proof for the corrected
candidate.

The merge verifier requires an active proof topology whose record has status
`proved` and whose candidate commit equals the current pull-request head, with
observed results for every acceptance check. It performs no topology mutation
or cleanup.

### Review while CI runs

The agent opens a normal pull request only after proof succeeds. Orbit does
not use draft pull requests. CI and independent review start immediately and
run in parallel. The reviewer can approve while CI is pending. The merge
verifier still requires passing current-head CI, approval for the current
candidate, no unresolved actionable findings, a complete Compound disposition,
and complete post-deployment actions.

ADR 0002's two review events are replaced by one review anchored to the
candidate commit. The reviewer verifies the Linear contract, candidate,
topology recipe, proof record, acceptance mapping, manual-action dispositions,
Compound result, and existing review comments. Review is read-only for the
proof topology.

### Clean proof before prepared-state refresh

After merge, the external project manager releases the proof topology and
verifies exact absence. Only then does it refresh the default prepared
topology when the prepared-state fingerprint from ADR 0005 shows a change,
remove the worktree, and close the Linear issue. If refresh fails, merged code
stays merged and the proof topology stays absent; the worktree and issue stay
open for maintenance triage.

Cleanup uses the exact resource inventory recorded when the attempt was
created. Each resource records its Orbit owner, issue, and attempt. Cleanup
revalidates live ownership, removes only those exact resources, and verifies
absence. It never deletes by prefix, glob, age, broad query, or unresolved
variable. A cleanup failure blocks the next topology. The TTL reaper is only a
fallback for abandoned discovery or diagnosis attempts.

The ownership and production-separation principles of ADR 0002 stay exact:
every proof resource names its task owner; shared and pre-existing resources
are never adopted as task-owned state; the merge verifier is read-only for
live resources; and production release, `post_deployment_actions`, and
post-deploy verification remain a separate operations process. Production
never reuses a disposable proof topology.

## Consequences

- Discovery is fast and free-form because a disposable topology has no shared
  pre-state to protect or restore.
- Proof is trustworthy because it runs the exact commit on a physically fresh
  topology that discovery state cannot reach.
- One review event replaces ADR 0002's pre-rollout and post-proof reviews, and
  review no longer waits for CI.
- A stale proof is detected by the pull-request head alone; there is no
  separate invalidation state.
- Exact cleanup and verified absence gate every transition: discovery to
  proof, diagnosis to retry, and merge to prepared-state refresh.
- The repository must maintain attempt-scoped inventories, proof records, and
  cleanup receipts in the `apps/e2e` harness, and must update the issue,
  worker, reviewer, and merge contracts in a dependent change after this
  decision is on `main`. Until then, those contracts keep their current text.
- Automated-only work stays independent of Incus. Automated proof remains
  mandatory and implicit; Linear adds `Proof: incus` and a `Composition` line
  only when Incus proof applies.
