## Linear issue

- Issue:
- Outcome:

## Decision context

- ADR: none

All required ADRs must already exist on `main`.

## Verification

- [ ] Focused tests pass
- [ ] Full affected project suites pass without TIA
- [ ] Project quality checks pass
- [ ] Current-head CI passes before any candidate rollout or live mutation
- Proof venue: automated or live
- Live nodes (if applicable): exact IDs, names, and roles from
  `orbit node:list --json`
- Live access method (if applicable): Orbit CLI, Gateway API, or pinned direct
  SSH
- Pre-rollout review (live only): review ID, candidate SHA, `rollout_approved`
- Checkout identity (if applicable): candidate and deployed paths/commit SHAs
- Ownership baseline (if applicable): task-owned, shared, and pre-existing
  resources
- Mutation evidence (if applicable): for each mutation, fresh node-list request
  or snapshot, exact node, candidate SHA, mutation, task-owned resources,
  pre-state, recovery, result, and cleanup
- Recovery evidence (if applicable): verified recovery action and owned recovery artifacts
- Post-proof absence evidence (if applicable): task-owned resources absent;
  shared and pre-existing state unchanged
- Final review: review ID, candidate SHA, `post_proof` and `approved`
- Evidence:

## Compound

- Decision or reference updates: none
- Reusable solution notes: none
- Why no durable documentation is needed, if none:

## Absence and drift

- [ ] Every task-owned proof and recovery resource is verified absent before
  final review
- [ ] Ownership and shared-state drift checks pass without merge-verifier cleanup
- [ ] No credentials, runtime state, or generated artifacts are committed
