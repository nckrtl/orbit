# Feature plan

Plan format: 1
Issue: ORB-345
Flow: discovery
Review verdict: PASS

## Outcome

Orbit can register an already-running named Herdr session as externally managed, publish its receive-only observer after protocol verification, and issue observation grants without controlling the service lifecycle.

## Code boundaries

In:
- Gateway adoption route, persistence mode, health, Doctor, restart, and removal behavior.
- PHP SDK request and response transport.
- Hidden Herdr CLI command and command-surface inventory.
- Herdr session operator reference and focused automated coverage.

Out:
- Starting, stopping, restarting, replacing, or upgrading an adopted Herdr service.
- Taking ownership of an existing systemd unit or creating an Orbit Process for it.
- Changing the existing observation grant, origin, expiry, pane, terminal, or replay policy.
- Commander UI or task-placement changes.

## Documentation

Update `docs/reference/herdr-sessions.md` with adoption, external ownership, protocol compatibility, removal, restart, API, CLI, health, and failure-code behavior. Correct the managed-only Process ownership statements in `docs/architecture.md` and `docs/reference/app-processes-and-schedules.md`. Update the PHP SDK package contract and README for the added public operation and returned management mode.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Adoption performs no Process lifecycle operation | Gateway action and API | Gateway feature test asserts no Process and no converge/start/stop/restart calls |
| Duplicate adoption is idempotent | Gateway action and API | Gateway feature test repeats the same request and asserts one record and one publication |
| Tool, Node-access, user, name, and ownership conflicts fail closed | Gateway admission and HTTP boundary | Adoption feature tests cover missing, failed, and wrong-Node Tool intent, directed-access denial, user mismatch, invalid names, managed/adopted collisions, and Process-name collision with no runtime mutation |
| Protocol 20 is retained without publication or grants and recovers after upgrade | Gateway inspector and observer contract | Gateway adoption tests assert the retained failed record, denied grant without a nonce, no publication, then successful republish on protocol 22 |
| Protocol 22 publishes and uses the existing grant contract | Gateway observer and grant actions | An adopted-session test issues a scoped grant; existing origin, pane, terminal, expiry, and replay tests prove the shared policy |
| Restart and destroy never terminate an external service | Gateway lifecycle actions | Gateway feature test removes Tool intent, injects an inconsistent Process link, and asserts restart refusal and no Process removal |
| Existing Herdr rows remain managed through migration and rollback | Gateway database migration | Focused migration test adds a legacy row, applies the default, compares all retained state and links, rolls back, and compares again |
| SDK, CLI, and docs expose the contract | SDK, CLI, maintained docs | SDK/CLI focused tests, changed-project checks, and docs lint |
| Beast session survives the controlled Herdr upgrade | Live Node operation after merge | Record pane identities before handoff, perform the supported live handoff, and compare identities afterward |

## Implementation order

1. Add the external management mode and migration while preserving managed defaults.
2. Add the inspected, idempotent adoption action and route with directed Node access.
3. Make health, Doctor, restart, and removal behavior ownership-aware.
4. Add typed SDK and hidden CLI surfaces.
5. Add focused tests and documentation, run project checks and the clean candidate gate.
6. After plan approval, acquire ORB-345 discovery and rehearse the supervision transition, live handoff, external adoption, observer publication, and grant issuance while comparing every pane identity before and after.
7. Independently review and merge the exact candidate, deploy Orbit, then separately roll out and validate the live Beast `commander-tasks` handoff and observer.

## Must preserve

- Existing Herdr panes and their processes must stay alive throughout the upgrade and adoption.
- Managed session create, restart, removal, and observation grant behavior remains unchanged.
- Adoption requires installed managed Herdr Tool intent on the exact managed Node and its managed Unix user.
- External records never authorize Orbit to mutate the external service or any linked Process.
- The receive-only observer retains the existing one-time grant and origin policy.

## Open questions

none

## Deviations

- Implementation started inline before the plan was recorded because the user explicitly requested immediate end-to-end execution. The candidate still receives an independent exact-head review before merge.

## Review findings
