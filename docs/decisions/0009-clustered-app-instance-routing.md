# ADR 0009: Adopt clustered AppInstance routing

## Status

Accepted on 2026-08-31. This ADR supersedes
[ADR 0008](0008-typed-app-dev-node-storage-settings.md) only where ADR 0008
defines the runnable Instance and child Workspace model, a separate worktree
root, and instance or workspace checkout-path derivation. ADR 0008 remains
unchanged. Its applicable closed typed-settings, raw-value, derived-default,
immutable recorded-path, validation, preparation, overlap, and source-safe
removal contracts continue to govern the single apps root and AppInstance
checkouts defined here.

## Context

Orbit currently models a runnable Instance and its child Workspaces. The base
checkout is a Git repository, but each Workspace is a Git worktree attached to
that repository. This parent relationship makes source identity, placement,
removal, and routing depend on two product concepts with different lifecycle
rules. It also makes a Workspace less independent than the workload that it
represents.

Orbit also exposes application traffic from the Node that hosts a workload.
That model does not provide one stable ingress identity when workloads occupy
different Nodes. It does not close the ownership of hostnames, backend
selection, internal DNS, TLS, or the choice between LAN and WireGuard paths.

Orbit needs one native grouping and routing boundary. This ADR calls that
boundary a Cluster. The term is compatible with future placement and routing
work, but it describes an Orbit domain concept. It does not commit Orbit to
Kubernetes or add containers, images, registries, Pods, or cluster
orchestration.

The first implementation must keep placement explicit. It must also preserve
safe source handling while existing Instances and Workspaces convert to one
model. Traffic can change only after the replacement source, runtime, Route,
and Router state is ready and verified.

## Decision

### Close domain ownership and identity

The following owners and invariants form one closed model:

- An **App** owns its required `repository_url`, `main_branch`, and relative
  web `root`. It owns its AppInstances and Routes. The repository and branch
  fields are the only source inputs for new AppInstance checkouts.
- An **AppInstance** belongs to exactly one App and exactly one Node. Its
  identity is its App and unique instance name. It owns a nullable relative
  `root` override, selected branch, exact starting commit, immutable recorded
  checkout path, and lifecycle state. Its effective web root is
  `app_instance.root ?? app.root`. It has no AppInstance parent, Workspace
  parent, or ancestry relation.
- A **Cluster** has a unique name and owns its optional normalized,
  single-label development `tld`, lifecycle state, Node memberships, Routes,
  and one active Router role assignment. A non-null TLD identifies only one
  Cluster. A Node belongs to exactly one Cluster in the completed model. Nodes
  cannot span Clusters.
- A **Node** owns its roles, bare `wireguard_ip`, nullable bare `lan_ip`, and
  one typed apps-root setting. It owns the runtime and Caddy projections for
  AppInstances placed on it. WireGuard addresses identify Nodes globally. A
  non-null LAN address is unique inside its Cluster but can repeat in another
  Cluster. An active AppInstance must use an active Node with a compatible
  application role in its Cluster.
- A **Route** belongs to exactly one App and one Cluster. It owns one hostname,
  publication intent, lifecycle state, and its route targets. Its normalized
  hostname identifies one Route globally.
- A **route target** belongs to exactly one Route and references exactly one
  AppInstance. The target AppInstance must belong to the Route's App and must
  be on a Node in the Route's Cluster. A target never stores an independent
  Node choice; it derives the Node from the AppInstance.
- A **Router role assignment** belongs to exactly one Cluster and one Node in
  that Cluster. Each active Cluster has exactly one active Router assignment.
  A Router is a Node role, not a separate workload or source owner.

The Route-to-target association is one-to-many so later production routing can
use more than one backend without changing ownership. The first implementation
converges at most one active target and defines no load-balancing policy. A
Route can retain its identity and publication intent with no target; it then
serves a deterministic unavailable response.

Workspace ends as a durable product concept. Each legacy Instance and each
legacy Workspace converts directly to a sibling AppInstance under the former
parent App. Conversion does not retain an Instance-to-Workspace or
AppInstance-to-AppInstance relation.

### Use independent AppInstance clones

Every new AppInstance uses an independent Git clone of its App's stored
`repository_url`. AppInstance source never uses Git worktrees or shared
worktree administration.

Orbit fetches the repository before branch selection. If
`origin/<instance-name>` exists, Orbit creates the local instance branch from
that remote branch. Otherwise, it creates the instance branch from the exact
fetched `origin/<app.main_branch>` commit. Orbit records the selected branch
and exact starting commit before the AppInstance becomes active. A later
remote default-branch or branch-head change does not rewrite either value.

The target Node's single effective apps root derives a new checkout path as
`<apps-root>/<app-slug>/<instance-name>`. Orbit records that path on the
AppInstance and never recalculates it for an existing checkout. An apps-root
setting change applies only to later checkouts and does not move, rewrite, or
delete existing source.

Creation validates the exact path against every Orbit-managed checkout. It
then prepares and verifies a real independent repository at that path before
activation. Retries with the same App, name, Node, root, branch, commit, and
path resume the same AppInstance. Conflicting identity fails without changing
the existing record or source.

Removal refuses first. Orbit uses the immutable recorded path and applies ADR
0008's protected-path, directory-boundary, symlink, canonical-path, repository
identity, containment, overlap, and exact-source rules. It also refuses dirty
source or unpublished commits by default. An explicit destructive option may
discard such source, but it cannot waive path or repository-identity safety.
Orbit deletes only the exact clone and an allowed empty grouping directory. It
never deletes the apps root, an unexpected sibling, or unrecognized content.

### Keep placement and runtime on Nodes

An operator selects the Node when it creates or moves an AppInstance. The
first implementation does not schedule, score, reserve capacity, queue
placement, migrate workloads automatically, or rebalance a Cluster.

PHP and other application-runtime prerequisites belong to the Node's
application role. An AppInstance can require a compatible role, but it does
not select, install, or own a private PHP or application runtime.

### Generate development Routes and accept explicit Routes

A Cluster can have one optional normalized development TLD. The TLD is used
only to generate default Routes. For an AppInstance whose name equals
`app.main_branch`, the default Route is the unprefixed
`<app-slug>.<cluster-tld>`. For any other AppInstance, it is
`<instance-name>.<app-slug>.<cluster-tld>`.

A Cluster without a TLD generates no hostname. Its AppInstances need explicit
Route hostnames for publication. This is also the production form: production
hostnames are explicit and do not require a shared suffix. Orbit validates an
explicit hostname without treating it as proof of public-DNS ownership.

Generated and explicit Routes use the same App, Cluster, target, and lifecycle
invariants. A default name does not weaken target validation. A Route target
must always share both the Route's App and Cluster.

### Route through one Cluster Router

Each active Cluster has exactly one active Router role assignment. The Router
role can co-locate with workload roles. A development Router can host
AppInstances, while a production Cluster can use a dedicated ingress Router.

Orbit manages one composed Caddy service per Node. Router-owned ingress sites
and workload-owned sites are Caddy fragments in that service; they do not
create separate listeners. Caddy listens on `0.0.0.0:443`. If the Router Node
also hosts the target AppInstance, its Route reaches the local runtime directly
and does not proxy back into its own HTTPS listener.

For a target on another Node, Router Caddy proxies to that Node's Caddy over
HTTPS on port 443. It preserves the original Route hostname as both the HTTP
`Host` value and the TLS server name. Orbit issues separate route-scoped
Orbit-CA leaf certificates and private keys to the Router and workload Node.
The Router validates the workload Node's leaf. Sharing a Cluster or a Route
does not share private keys.

The route-target model remains one-to-many, but v1 selects no balancing,
health-weighting, or failover policy. A later decision must define how a
Router chooses among simultaneous targets.

### Separate control-plane and application paths

Every Node records `wireguard_ip`. It is the Node's stable control-plane
identity and management-reachability address. A Node can also record a
`lan_ip` for Cluster-local application traffic. WireGuard endpoint
configuration stays separate from the bare `wireguard_ip`.

For Router-to-workload application traffic, Orbit uses `lan_ip` when it is
configured. It uses `wireguard_ip` only when `lan_ip` is absent. A configured
but unreachable LAN address is a failed convergence or health state. Orbit
does not silently fall back to WireGuard, because that would hide invalid
operator intent and make the active path ambiguous.

The Node role owns network exposure. The one Caddy listener remains
`0.0.0.0:443`; firewall rules decide which interfaces and peers can reach it.
Workload Nodes allow required HTTPS over their WireGuard and configured LAN
paths, including authorized Router ingress, but deny unintended public
workload ingress by default. Router Nodes open only the development or
production ingress required by their Router role. Control-plane WireGuard
access does not imply public application access, and a public Router role does
not expose every workload Node publicly.

### Publish DNS as a routing projection

For a Cluster with a TLD, Gateway DNS publishes one wildcard for that TLD to
the active Router's `wireguard_ip`. Default Route hostnames use that wildcard.
Replacing the Router updates this one projection without changing Apps,
AppInstances, Routes, or target placement.

An explicit Route can request an exact internal DNS record. This supports
hostnames outside the Cluster TLD. Production public-DNS providers are a
future publication extension; this decision does not automate public DNS.

Local overrides remain separate from Gateway publication. An operator can set
a local wildcard TLD override to a Router LAN address and reach all Cluster
Routes through that Router. An exact-host override takes precedence and can
point one Route hostname directly at a workload Node's LAN address. Resetting
one override does not modify the other.

DNS publication is the last convergence projection. Orbit prepares and
verifies the workload runtime, workload Caddy, leaf certificates, Router
Caddy, firewall policy, and trusted health before it publishes DNS or marks a
Route active. A failed change rolls back its database lifecycle and runtime
projections without publishing a partially ready Route.

### Convert legacy resources in stages

Conversion is an explicit, resumable fleet operation with these ordered
boundaries:

1. **Preflight data.** Read legacy Instances, Workspaces, Nodes, source, and
   routes without mutation. Require Cluster membership, resolve AppInstance
   name collisions, and verify App repository, branch, commit, root, checkout
   cleanliness, origin, path identity, containment, and runtime prerequisites.
2. **Stage records.** Create inactive AppInstance records for legacy Instances
   and Workspaces. Both map directly to the former parent App and selected
   Node. Copy their branch, exact HEAD commit, effective root, and environment
   intent. Create no parent or ancestry relation.
3. **Stage source.** Create each normalized independent clone under
   `<apps-root>/<app-slug>/<instance-name>`, check out its recorded branch and
   commit, and verify its own `.git` directory. Never relabel a legacy Git
   worktree as a clone. Keep every legacy checkout and worktree unchanged.
4. **Stage runtime.** Prepare and verify Node-role prerequisites, the workload
   runtime, workload Caddy site, and workload certificate without changing
   traffic.
5. **Stage routing.** Create an explicit Route that preserves each legacy
   hostname, its App and Cluster ownership, and its AppInstance target. Prepare
   and verify the Router certificate, Caddy projection, firewall policy, and
   trusted end-to-end health without publishing the new traffic path.
6. **Cut over traffic.** Publish DNS or replace the active Route projection
   only after every prepared state is verified. Mark the new AppInstance and
   Route active as one resumable cutover. Do not remove legacy source or the
   known working runtime during this step.
7. **Roll back when required.** Before cutover, remove or retry only new staged
   state and leave legacy traffic active. If cutover fails, restore the legacy
   Route and runtime projections before deactivating replacement state. A
   rollback never deletes the legacy checkout or converts dirty or unpublished
   source by force.
8. **Clean up after success.** After trusted traffic succeeds, remove legacy
   runtime projections and delete only exact legacy checkouts or worktrees that
   pass the recorded-path, repository-identity, containment, clean-source, and
   safe-removal boundary. Then remove Workspace product surfaces and obsolete
   Instance fields. A failure leaves the resource resumable and never removes
   an unverified source.

The operation records durable progress for every phase and resource. A retry
continues from verified state and does not create duplicate AppInstances,
clones, Routes, certificates, or traffic projections. Development processes
using legacy source must be stopped before conversion; Orbit does not manage
those processes.

## Consequences

- AppInstance becomes the one runnable application concept. Independent clones
  cost more disk space than Git worktrees but remove shared repository and
  parent-lifecycle coupling.
- Cluster gives Orbit one native Node, routing, and DNS boundary without adding
  Kubernetes semantics or promising future orchestration behavior.
- Explicit Node placement keeps v1 deterministic. Automatic placement,
  migration, and rebalancing need later decisions and implementation.
- One Router gives a Cluster stable ingress while roles can still co-locate.
  The one-to-many target boundary permits later balancing, but v1 has no
  balancing policy.
- Original-hostname HTTPS and separate Orbit-CA leaves provide authenticated
  Router-to-workload traffic. This adds certificate and Caddy convergence that
  must succeed before DNS publication.
- LAN-first application traffic avoids unnecessary tunneling. A configured LAN
  failure is visible instead of being masked by fallback.
- One apps root makes new paths uniform. Recorded paths and ADR 0008's safety
  rules prevent a setting change or removal from moving or deleting existing
  source implicitly.
- Staged conversion temporarily duplicates records, source, runtime, and Route
  projections. That overlap is required for verification and rollback. Legacy
  worktrees remain until successful cutover and source-safe cleanup.
- Public DNS automation, Nodes in multiple Clusters, private AppInstance
  runtimes, automatic placement, Kubernetes behavior, development-process
  management, and multi-target load-balancing policy remain outside this
  decision.
