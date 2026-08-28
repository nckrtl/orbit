# ADR 0002: Adopt a rolling Incus development topology

## Status

Accepted on 2026-08-28. The architecture is accepted, but the
`gateway_app-dev_app-prod` profile is not registered for issue use yet. It
becomes available only after the harness passes live acceptance and the
topology registry is updated.

## Context

Orbit needs a repeatable live-infrastructure venue for features whose proof
requires more than automated checks. The existing development-loop decision
treats Incus as diagnostic-only and permits a shared registered topology. That
does not provide isolated feature state, exact worktree proof, or a safe way to
keep prepared infrastructure current without frequent cold rebuilds.

The topology also needs a clear source of truth. Prepared VM snapshots can
accelerate acquisition, but they must not replace the merged repository or the
feature worktree. Refresh and cleanup must fail closed when resources or state
do not match their recorded identities.

This decision records the approved rolling Incus development topology design
dated 2026-08-28. The ADR stands alone as the repository decision.

## Decision

### Use one complete profile

Incus-backed development uses only `gateway_app-dev_app-prod`, with ordered
roles `gateway`, `app-dev`, and `app-prod`. Each feature receives disposable,
issue-specific Gateway, app-dev, and app-prod VMs on an isolated network.
Persistent standby VMs use the same three roles and remain stopped outside
refresh or recovery. The standby and feature namespaces are distinct from
Orbit-old resources. The canonical standby identities are
`orbit-e2e-standby-gateway`, `orbit-e2e-standby-app-dev`, and
`orbit-e2e-standby-app-prod`, on network `orbit-e2e-standby`. Each generation
uses coordinated snapshots named `main-<generation-id>` on all three VMs.

### Treat snapshots as an acceleration cache

The promoted standby generation is coordinated by an atomic manifest that
records exact VM, snapshot, base-image, source, and verification identities.
The manifest and exact Incus objects are authoritative for standby state. Host
Git remains authoritative for source. Acquisition and every iteration
synchronize the exact worktree into VM-local repositories on Gateway and
app-dev; the host worktree is never mounted into a VM. Final proof requires a
clean worktree, exact candidate SHA and tree, and clean guest checkouts at that
candidate.

### Refresh only when prepared state changes

`apps/e2e` computes a canonical prepared-state fingerprint from an explicit,
reviewed input allowlist. The merged SHA is source identity, not a fingerprint
input. A matching fingerprint produces a no-op and must not start or verify
standby VMs. A changed fingerprint refreshes the promoted generation by
restoring, converging, verifying, stopping, and snapshotting the three VMs in
role order. Normal refreshes start from the promoted generation, retain the
preceding generation, and use the generic base image only for initial
construction, an explicit cold epoch change, corruption, or disaster recovery.

### Keep promotion and recovery deterministic

Promotion occurs only after all candidate snapshots and machine-readable
readiness and smoke gates pass. A partial candidate is never promoted. A
failed refresh leaves the old generation promoted, stops and restores the
standby VMs, retains failure evidence, and blocks new Incus-backed acquisition
while the promoted fingerprint is stale. If rollback fails, the standby is
marked corrupt and requires explicit cold recovery. Agents may perform a
targeted migration within the exact topology and journal boundary, but they
cannot waive a failed gate or broaden resource ownership.

### Retire Orbit-old exactly and reversibly first

The old acquisition entry points stop before retirement. Replacement is proved
with a standby refresh and a complete acquire, dirty sync, proof, and release
cycle. Retirement inventories exact identities, freezes creation, quarantines
reviewed resources for seven days, and then deletes only the unchanged
quarantine manifest’s exact resources. It preserves the `orbit-e2e` ZFS pool,
generic base image, new topology resources, unrelated Incus resources, and
audit evidence. Age, names, prefixes, and broad queries never authorize
deletion.

This supersedes only the diagnostic-only Incus rule in the approved monorepo
development-loop design dated 2026-08-27, which is held in project planning
records outside this repository. Automated-only work remains independent of
Incus; all other workflow ownership, review, merge, and deployment boundaries
remain unchanged.

## Consequences

- Incus-backed issues have one predictable topology and isolated feature
  resources, while automated-only issues need no Incus resources.
- Prepared changes can roll forward without routine cold rebuilds, and
  unchanged merges leave stopped standby VMs untouched.
- Exact manifests, worktree synchronization, deterministic proof, and
  generation retention make source and rollback state auditable.
- A stale or failed standby blocks only new Incus-backed acquisition. It does
  not block automated development or roll back merged source.
- The repository must maintain the Incus-only `apps/e2e` harness, its state and
  evidence contracts, and the exact legacy-retirement workflow.
- Live Incus acceptance and internal HTTPS remain development concerns;
  public ACME and production deployment stay in the separate operations cycle.
