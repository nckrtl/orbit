# ADR 0017: Make Cluster placement optional

## Status

Accepted on 2026-09-03.

This decision supersedes [ADR 0009](0009-clustered-app-instance-routing.md)
where it requires every completed-model Node, AppInstance, and Route to belong
to a Cluster, requires every application request to traverse a Cluster Router,
and uses only a Cluster TLD for generated AppInstance hostnames. It extends
[ADR 0011](0011-clustered-production-ingress-and-app-prod-placement.md) with a
standalone app-prod path and extends
[ADR 0016](0016-reconcile-app-identity-and-source-default-updates.md) with
Node-to-Cluster routing-scope reconciliation. Their remaining source,
placement, routing, ingress, and safety boundaries stay in force.

## Context

ADR 0009 makes a Cluster the mandatory grouping and routing boundary for every
AppInstance. That gives a multi-Node installation one stable Router and TLD,
but it also makes Cluster creation, Router assignment, and activation
prerequisites for the first workload on one Node. A deployment cannot begin
with one useful application Node and adopt clustered routing later.

Orbit already stores a nullable Node Cluster membership and a Node-level
development TLD. Existing Instances and Workspaces use the Node TLD and serve
traffic directly from their Node. Requiring their replacements to join an
active Cluster before creation would turn an optional expansion boundary into
a mandatory bootstrap boundary and would force role-less operator clients and
otherwise independent Nodes into a grouping they may not need.

Orbit needs standalone Nodes and Clusters to be two valid operating modes.
Joining a Cluster must preserve the Node's independent identity and fallback
TLD while making an active Cluster with a TLD authoritative for routing. An
active Cluster without a TLD must not add a Router hop that has no shared
namespace to own. The transition must not produce two active owners for one TLD
or publish partially reconciled traffic.

## Decision

### Keep Cluster membership optional

A Node belongs to zero or one Cluster. A Node without a Cluster is a
**standalone Node**. Standalone is a durable supported placement, not a
transitional error state.

An AppInstance belongs to exactly one App and exactly one Node. It never accepts
or stores an independently selected Cluster. Its current routing scope derives
from its Node: an active Cluster with a non-null TLD when the Node is a member of
one, otherwise the Node itself.

AppInstance creation always requires an active Node with the compatible
application role. A standalone AppInstance does not require a Cluster or
Router. An AppInstance in a Cluster-routed scope additionally requires that
Cluster's applicable active routing roles. An inactive Cluster membership or
an active Cluster without a TLD does not make the AppInstance unavailable or
replace its direct Node routing scope.

Role-less operator clients and Nodes without application workloads may remain
standalone indefinitely. Cluster membership does not grant a role, and leaving
a Cluster does not remove one. Existing role eligibility, operating-system,
access, and convergence rules continue independently.

### Retain the Node TLD and give the active Cluster precedence

A Node retains its nullable Node TLD while it belongs to a Cluster. Cluster
membership never deletes or rewrites that value. A Cluster also retains its
nullable Cluster TLD.

The effective development TLD for an AppInstance is:

```text
active Node Cluster TLD ?? Node TLD
```

A non-null TLD on the Node's active Cluster therefore overrides the Node TLD.
When the Node is standalone, its Cluster is inactive, or the active Cluster has
no TLD, the Node TLD remains effective. When neither scope supplies a TLD,
Orbit generates no hostname and requires an explicit Route hostname for
publication.

When the Node is standalone, its Cluster is inactive, or its active Cluster has
no TLD, the effective Node TLD resolves directly to that Node. When the Cluster
is active and has a TLD, that shared TLD resolves to the active Router. The
Router matches the complete Route hostname and forwards to that Route's
AppInstance target on the owning Node; it does not select a backend from the
TLD alone.

Node TLDs remain unique among Nodes. Cluster TLDs remain unique among Clusters.
Cross-scope equality is permitted while a Cluster is inactive because the
Cluster owns no active TLD projection. Orbit may create or update such an
inactive Cluster without changing the Node or its traffic.

Before activating a Cluster that has a TLD, assigning a TLD to an active
Cluster, or changing the TLD of an active Cluster, Orbit finds every Node with
the proposed Cluster TLD. The change is allowed only when each such Node is a
member of that Cluster. A non-member Node with that TLD blocks the change
without changing either resource or any routing projection. A member Node may
retain the same TLD as its active Cluster because both values describe one
effective routing scope.

While a Cluster with a TLD is active, Orbit rejects creating or updating a
non-member Node with that Cluster's TLD. Deactivation removes the cross-scope
restriction but does not weaken the separate Node-to-Node or
Cluster-to-Cluster uniqueness rules.

An active Cluster without a TLD does not require an active Router. Activating a
Cluster that has a TLD or assigning a TLD to an active Cluster requires exactly
one active Router before traffic publication. Removing the TLD from an active
Cluster reconciles traffic back to direct Node routing before the Router becomes
optional.

### Give every Route exactly one routing scope

A Route belongs to exactly one App and exactly one current routing scope: one
direct Node or one active TLD-bearing Cluster. It never owns both. Its targets
still reference AppInstances rather than storing independent backend
selections.

A Node-scoped Route accepts targets only from its Node and reaches that Node's
workload Caddy directly. The Node owns its direct DNS, TLS, listener, firewall,
and health projections. A standalone public app-prod Route terminates on that
Node; it does not require a Router or Ingress.

A Cluster-scoped Route accepts targets only from Nodes in that Cluster and uses
the Router path from ADR 0009. A public Cluster-scoped Route additionally uses
the Ingress path from ADR 0011. Cluster routing must not expose workload
placement to clients.

A Route can retain zero targets. Its explicit Node or Cluster scope remains
stored so hostname ownership and publication intent survive maintenance or
replacement. Multi-Node target pools require Cluster scope; one standalone
Node is not a load-balancing boundary.

Generated development hostnames use the effective TLD and ADR 0009's main and
feature name shapes. Explicit hostnames do not change when routing scope
changes, but Orbit still reconciles their direct or clustered traffic path.

### Reconcile membership and Cluster lifecycle before traffic cutover

Attaching a Node to an inactive Cluster records membership but leaves the Node
scope, Node TLD, and direct traffic authoritative. Activating a Cluster without
a TLD also leaves every member's direct routing unchanged and requires no
Router.

Activating a Cluster that has a TLD, or assigning a TLD to an active Cluster,
is the routing cutover for all existing members. Orbit inventories their
AppInstances and Routes, validates TLD ownership and target compatibility,
prepares and verifies the Cluster DNS, certificates, Router, Ingress where
required, workload Caddy, firewall, and health projections, and only then
publishes Cluster-scoped Routes and generated Cluster-TLD hostnames.

When the Cluster TLD equals a member Node TLD, Gateway DNS changes that wildcard
from the member Node's address to the Router's address at publication. When the
Cluster TLD is different, Gateway DNS publishes the new Cluster wildcard to the
Router and removes the superseded member Node wildcard projections only after
the new Routes are healthy. Old generated Node-TLD hostnames do not remain as
aliases. The member Nodes retain their stored TLD values for reversal.

Attaching a Node to an active TLD-bearing Cluster performs the same
reconciliation for that Node before its membership becomes authoritative for
routing. Attaching to an active Cluster without a TLD leaves direct Node routing
unchanged. Detaching a Node from an active TLD-bearing Cluster performs the
inverse reconciliation: it verifies that every affected generated Route has a
usable Node TLD or that an explicit hostname exists, prepares direct Node
projections, and cuts traffic back to the Node before removing authoritative
Cluster membership. Detaching from an active Cluster without a TLD changes no
traffic projection.

Deactivating or removing the TLD from a TLD-bearing active Cluster reconciles
all member workloads back to Node scope before the Cluster stops being
authoritative. It refuses when any member cannot serve its required standalone
Routes safely. Deactivation, TLD removal, and detachment preserve each Node TLD,
AppInstance, explicit hostname, and source placement.

Generated hostname changes use ADR 0016's durable preflight, preparation,
publication, forward recovery, and cleanup boundary. Membership, Cluster-state,
Cluster-TLD, attachment, and detachment changes are idempotent and resumable. A
failure before publication leaves the old routing scope authoritative. Once a
new hostname or path is published, recovery proceeds forward and never exposes
both scopes as active owners.

### Preserve standalone resources during migration

Legacy Instance and Workspace conversion does not require Cluster membership.
A legacy workload in a direct Node scope converts to an AppInstance and
Node-scoped Route while preserving its hostname and direct traffic. A legacy
workload on a Node in an active TLD-bearing Cluster converts to a Cluster-scoped
Route through the existing staged migration boundary.

Operators may adopt Cluster routing before or after AppInstance conversion.
Neither sequence may relabel source, recompute an existing checkout path, or
mix legacy and replacement traffic before the new path is verified. Migration
records the chosen routing scope and remains resumable at every cutover.

## Consequences

- A useful one-Node Orbit deployment does not require a Cluster, Router, or
  Ingress.
- Nodes and AppInstances can adopt Cluster routing later without changing
  source ownership or placement identity.
- An active Cluster without a TLD can group Nodes without requiring a Router or
  adding a routing hop.
- Node TLDs remain stable fallback configuration while an active Cluster TLD
  controls clustered development names.
- Inactive Clusters can be configured ahead of a cutover, including with a TLD
  currently used by a future member Node.
- Active TLD projections cannot collide with a non-member Node.
- Standalone production exposes one workload Node directly; stable ingress
  identity, hidden placement, and multi-Node targets require an active
  TLD-bearing Cluster.
- Route persistence needs an explicit exclusive Node-or-Cluster scope.
- Cluster state, TLD, and membership changes become traffic reconciliation
  operations only when they enter or leave an active TLD-bearing routing scope.
