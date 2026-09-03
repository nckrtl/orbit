# ADR 0005: Adopt a rolling Incus development topology

## Status

Accepted on 2026-08-29. The `gateway_app-dev_app-prod` profile passed live
acceptance on 2026-08-29 (cold topology snapshot build, rolling refresh,
acquire, dirty and clean sync, verify, prove, and release with verified
absence). It is registered in `docs/reference/incus-topologies.md`.

Amended on 2026-08-29 by
[ADR 0006](0006-topology-led-feature-development.md). The rule "the host
worktree is never mounted into a VM" now reads "proof never mounts host state;
discovery mounts the feature worktree". Discovery attaches the worktree to the
checkout roles with an Incus virtiofs disk device; proof still synchronizes
the exact candidate commit from Git. ADR 0006 also supersedes the
ADR 0002 boundary that the last paragraph of the Decision section preserves.
The decision below uses the current topology snapshot terminology.

Corrected on 2026-09-03 to match the harness: the feature-network hash covers
the issue ID and the attempt ID. The original text named the issue ID alone,
which would place the discovery and proof topologies of one issue on the same
bridge.

## Context

Orbit benefits from a repeatable, live-like venue for feature experiments and
multi-node verification. Incus is optional development infrastructure. It does
not replace the registered live-node proof flow and does not gate issue
readiness, review, or merge.

Disposable topology state must be isolated from other work. Exact feature
source must reach each selected checkout without a host mount. A prepared
topology must stay current without a costly cold rebuild after each merge.

Prepared VM snapshots can accelerate acquisition, but they must not replace
the merged repository or the feature worktree. Refresh and cleanup must fail
closed when resources or state do not match their recorded identities.

## Decision

### Use one complete profile

Incus-backed development uses only `gateway_app-dev_app-prod`, with ordered
nodes `gateway`, `app-dev`, and `app-prod`. The Gateway node has the `gateway`
and `vpn` roles. Each feature receives disposable, issue-specific Gateway,
app-dev, and app-prod VMs on an isolated network.
Persistent topology snapshot VMs use the same three-node layout. They remain
stopped outside refresh or recovery. The topology snapshot and feature
namespaces are distinct from Orbit-old resources. The canonical topology
snapshot identities are
`orbit-e2e-topology-snapshot-gateway`, `orbit-e2e-topology-snapshot-app-dev`, and
`orbit-e2e-topology-snapshot-app-prod`, on network `oe-topo-snap`. Feature
networks use `oe-<hash>`, where `<hash>` is the first 12 lowercase hexadecimal
characters of the SHA-256 digest of the issue ID and the attempt ID joined by a
colon. Deriving the name from both keeps the discovery and proof topologies of
one issue on separate bridges. This keeps every managed bridge within Linux's
15-character interface-name limit while readable VM names retain the full issue
ID. The issue identity and node role also derive deterministic MAC, IPv4, and
machine identities. Isolated networks let
concurrent topologies reuse role-local conventions without a conflict. Each
generation uses coordinated snapshots named `main-<generation-id>` on all
three VMs.

### Treat snapshots as an acceleration cache

An atomic manifest coordinates the promoted topology snapshot generation. It
records exact VM, snapshot, base-image, source, and verification identities.
The manifest and exact Incus objects are authoritative for topology snapshot
state. Host Git remains authoritative for source. Acquisition and every
iteration synchronize the exact worktree into VM-local repositories on Gateway
and app-dev; the host worktree is never mounted into a VM. Final topology proof
requires a clean worktree, exact candidate SHA and tree, and clean guest
checkouts at that candidate.

### Refresh only when prepared state changes

`apps/e2e` computes a canonical prepared-state fingerprint from an explicit,
reviewed input allowlist. The merged SHA is source identity, not a fingerprint
input. A matching fingerprint produces a no-op and must not start or verify the
topology snapshot VMs. A changed fingerprint refreshes the promoted generation.
It restores, starts, stops, and snapshots the three VMs in coordinated parallel
batches, with convergence and verification between those lifecycle phases.
Normal refreshes start from the promoted generation, retain the preceding
generation, and use the generic base image only for initial construction. The
`--allow-cold` refresh option permits only that initial construction and never
replaces a promoted generation. An explicit cold epoch change, corruption, or
disaster recovery requires a separate reviewed disaster-recovery procedure
before Incus mutation.

### Keep promotion and recovery deterministic

Promotion occurs only after all candidate snapshots and machine-readable
readiness and smoke gates pass. A partial candidate is never promoted. A
failed refresh leaves the old generation promoted, stops and restores the
topology snapshot VMs, retains failure evidence, and blocks new Incus-backed
acquisition while the promoted fingerprint is stale. If rollback fails, the
topology snapshot is marked corrupt and requires explicit cold recovery. Agents
may perform a targeted migration within the exact topology and journal
boundary, but they cannot waive a failed gate or broaden resource ownership.

### Retire Orbit-old exactly and reversibly first

The old acquisition entry points stop before retirement. Replacement is proved
with a topology snapshot refresh and a complete acquire, dirty sync, proof, and
release cycle. Retirement inventories exact identities, freezes creation,
quarantines reviewed resources for seven days, and then deletes only the
unchanged quarantine manifest's exact resources. It preserves the `orbit-e2e`
ZFS pool, generic base image, new topology resources, unrelated Incus
resources, and audit evidence. Age, names, prefixes, and broad queries never
authorize deletion.

This decision does not change the candidate deployment and live-proof boundary
defined by ADR 0002. Automated-only work remains independent of Incus. All
workflow ownership, review, merge, and production release boundaries remain
unchanged.

## Consequences

- Incus provides one predictable, isolated development topology, while agents
  can develop and prove features without it.
- Prepared changes can roll forward without routine cold rebuilds, and
  unchanged merges leave stopped topology snapshot VMs untouched.
- Exact manifests, worktree synchronization, deterministic proof, and
  generation retention make source and rollback state auditable.
- A stale or failed topology snapshot blocks only new Incus-backed acquisition.
  It does not block automated development or roll back merged source.
- The repository must maintain the Incus-only `apps/e2e` harness, its state and
  evidence contracts, and the exact legacy-retirement workflow.
- Live Incus acceptance and internal HTTPS remain development concerns;
  public ACME and production release stay in the separate operations cycle.
