# ADR 0002: Define the candidate deployment and proof cleanup boundary

## Status

Accepted on 2026-08-28.

## Context

Orbit feature development can deploy candidate code to registered live nodes
and create temporary resources to prove operating-system, service, network,
ownership, and multi-node behavior. Those nodes are shared infrastructure. A
stale candidate, stale topology snapshot, ambiguous resource owner, or deferred
cleanup can affect state that the feature does not own.

Automated gates alone cannot prove live behavior, but live proof must not start
before an independent reviewer has inspected the exact gated candidate and its
rollout intent. Merge approval also cannot rely on the earlier review because
live proof can expose defects, change the candidate, or leave resources behind.

The development workflow therefore needs durable boundaries between gated
implementation, candidate rollout, live proof, cleanup, final approval, merge,
and production release.

## Decision

### Review the exact candidate before rollout

Before any candidate deployment or rollout, the feature worker must have a
clean pushed candidate with focused checks, full affected-project suites, root
checks, and current-head CI complete. A separate reviewer must then review that
exact commit and its rollout intent. This pre-rollout review authorizes live
proof only; it is not final merge approval.

Every reviewer handoff includes these fields:

```yaml
review_phase: pre_rollout|post_proof
status: rollout_approved|approved|changes_requested|blocked
```

Only a successful `pre_rollout` review returns `rollout_approved`. Only a
successful `post_proof` review returns `approved`. A failed review at either
phase returns `changes_requested` or `blocked`. The merge verifier accepts only
the `post_proof` handoff with `status: approved` as merge evidence.

Any commit after the pre-rollout review invalidates it. The new candidate must
pass the full gates and receive another independent pre-rollout review before
it reaches a live node.

### Revalidate immediately before every live mutation

Immediately before every rollout or other live mutation, the feature worker
must run and inspect `orbit node:list --json`. It must confirm the selected node
IDs, names, roles, active state, access method, and applicable ownership
baseline. Topology, identity, access, ownership, or applicability drift blocks
the mutation.

Read-only live inspection may occur before the pre-rollout review. Candidate
deployment, proof fixtures, direct database edits, access changes, role
changes, package operations, and every other live mutation require the review
and a fresh topology revalidation.

Live mutations run serially. Each mutation names its task-owned resources,
records the pre-state, has a verified recovery action, and verifies checkout
identity and service health before the next mutation starts. Shared nodes and
pre-existing resources are never adopted as task-owned state.

The feature-worker handoff and pull-request evidence contain the durable
mutation record. Each entry identifies the candidate commit, node, fresh
`node:list` request or snapshot, intended mutation, task-owned resources,
pre-state, recovery action, result, and cleanup or verified absence. The
reviewer and merge verifier consume this same record; prose from another
channel is not ownership evidence.

### Restore and clean before final approval

After proof, the feature worker restores the documented pre-state and removes
every task-owned live proof resource. Cleanup must identify each resource from
the pre-mutation ownership record and verify its absence. Ambiguous ownership,
an unexpected resource, a missing pre-existing resource, or any other baseline
drift fails closed. The worker must not delete, reset, or adopt state to make
the cleanup check pass.

A separate reviewer then performs the final review against the exact candidate,
acceptance evidence, recovery evidence, restored pre-state, and cleanup
evidence. Only this post-proof review can grant final merge approval. The same
reviewer may perform both review stages, but each stage is a separate review
event anchored to the candidate commit and recorded in the reviewer handoff.

### Verify absence at merge

The merge verifier independently confirms that the approved candidate and all
gates remain unchanged. It also verifies that every recorded task-owned live
proof resource is absent and that shared and pre-existing state still matches
the ownership baseline. Any remaining resource or ownership drift blocks the
merge.

The merge verifier does not mutate or clean live resources. No post-merge live
resource cleanup belongs to the repository development cycle. After merge, the
external orchestrator verifies absence again, removes the worktree, and closes
the issue. Production release and post-deploy verification remain a separate
operations process.

### Migrate the workflow contracts after this decision lands

This decision amends the staged review and cleanup contracts in
`.agents/skills/developing-orbit-features/SKILL.md`,
`.agents/skills/reviewing-orbit-pull-requests/SKILL.md`, and
`.agents/skills/merging-orbit-pull-requests/SKILL.md`. Those contracts must be
updated together in a dependent change after this ADR is on `main`. Until they
conform to this decision, they cannot authorize a candidate rollout, final
approval, or merge under this workflow.

## Consequences

- Live rollout starts only from a clean pushed candidate with current-head
  gates and an independent pre-rollout review.
- Every live mutation uses a fresh registered-topology snapshot instead of a
  session-level assumption.
- Serial rollout, explicit ownership, and verified recovery limit blast radius
  and make proof reproducible.
- Live proof resources and candidate mutations cannot be deferred to
  post-merge cleanup.
- Final approval covers the completed proof and restored live state, not only
  the pre-rollout code review.
- The merge gate is read-only for live resources and blocks on absence or
  ownership uncertainty.
- Production release remains independent from temporary candidate proof.
