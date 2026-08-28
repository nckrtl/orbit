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
- Proof venue: automated or live
- Live nodes (if applicable): exact IDs, names, and roles from `orbit node:list --json`
- Live access method (if applicable): Orbit CLI, Gateway API, or pinned direct SSH
- Checkout identity (if applicable): candidate and deployed paths/commit SHAs
- Recovery evidence (if applicable): verified recovery points before mutation
- Pre-mutation cleanup baseline (if applicable): task-owned resources and shared nodes
- Post-mutation cleanup evidence (if applicable): task-owned resources removed; shared nodes intact
- Evidence:

## Compound

- Decision or reference updates: none
- Reusable solution notes: none
- Why no durable documentation is needed, if none:

## Cleanup

- [ ] Cleanup evidence is recorded for task-owned resources; shared live nodes remain intact
- [ ] No credentials, runtime state, or generated artifacts are committed
