# ADR 0011: Define clustered production ingress and app-prod placement

## Status

Accepted on 2026-08-31. This ADR extends
[ADR 0009](0009-clustered-app-instance-routing.md) and supersedes only its
production-specific decisions that make every AppInstance an Orbit-owned Git
clone under the Node apps root and make the production Router own public
ingress. ADR 0009's app-dev clone, private Router, Route, Cluster, network,
DNS, TLS, and source-safety decisions continue where compatible.

## Context

ADR 0009 gives every AppInstance one independent Git clone and lets a
production Cluster use a dedicated ingress Router. That is appropriate for
development source ownership, but it combines three production concerns:

- public listener, certificate, and edge policy;
- backend selection for a Route; and
- the workload runtime and its deployment content.

Production workloads can be deployed by an operator or an external deployment
system. Orbit must not claim ownership of their branch, commit, clone, release,
or deployment lifecycle. The public endpoint also must not reveal which Node
hosts a workload, and moving a workload must not require changing public DNS
or public certificate ownership.

Orbit therefore needs a public edge role separate from the Cluster Router and
a production AppInstance placement model separate from app-dev source clones.

## Decision

### Separate Ingress, Router, and workload ownership

A Cluster has zero or one active **Ingress** role assignment. A public Route
requires exactly one active Ingress in its Cluster before Orbit can mark its
public publication active.

The roles own distinct concerns:

- **Ingress** owns public HTTP and HTTPS listeners, public TLS, public edge
  policy, and forwarding the original Route hostname to the Cluster Router.
- **Router** owns Route target selection and the backend pool. It does not own
  public certificates or public DNS-provider state.
- **Workload Node Caddy** owns the private HTTPS site that serves one or more
  AppInstances on that Node.
- **AppInstance runtime** owns the application process behind the workload
  Node's Caddy site.

The public request path is public DNS to Ingress, Ingress to Router, Router to
workload Node Caddy, and workload Caddy to the AppInstance runtime. Private
Routes can continue through the Router without an Ingress.

Ingress can co-locate with Router, app-prod, or both. It cannot co-locate with
app-dev. When adjacent roles share a Node, Orbit composes their Caddy
configuration and selects the local next hop directly. It never proxies back
into the same HTTPS listener or creates a self-proxy loop.

Ingress remains unaware of AppInstance placement and Route backend pools. A
workload move or a Router backend change therefore does not change the public
Ingress identity.

### Keep private hops inside the Cluster trust boundary

Ingress-to-Router and Router-to-workload traffic uses HTTPS with Orbit-CA
certificates. Router-to-workload address selection remains LAN-first with
WireGuard fallback only when no LAN address is configured, as defined by ADR
0009.

Node Caddy continues to listen on `0.0.0.0:443`. Role-owned firewall rules,
not listener binding, define which public, LAN, and WireGuard paths can reach
each site. A workload Node is not public merely because it runs Caddy.

Public TLS is Route and Ingress state. It is not AppInstance state. The public
endpoint is also not a Node's `public_ssh_host`, `wireguard_ip`, or `lan_ip`.
Public DNS-provider and external-load-balancer automation remain deferred until
Orbit introduces an explicit public-endpoint model.

### Treat app-prod as operator-deployed placement

An app-prod AppInstance identifies one App runtime placement on one app-prod
Node. A given App can have at most one AppInstance on a given app-prod Node.

Orbit creates one dedicated production system user for the App on that Node
and uses the user's home, for example `/home/abc`, as the placement base. The
effective web root is:

```text
/home/<app-user>/<app_instance.root ?? app.root>
```

App and AppInstance roots are relative. Orbit rejects absolute paths and
parent traversal. Validation permits unresolved symlink components used by
operator deployment layouts, such as `current/public`, while retaining
containment and ownership checks for the resolved placement.

Orbit owns the AppInstance identity, placement, runtime association, Route
targets, and safe removal of state it created. It does not own or record a
production branch, commit, clone, release, deployment command, migration,
dependency installation, release directory, or symlink switch. Deployment
systems and operators remain responsible for placing application content in
the dedicated home.

The Node apps root, independent Git clone, selected branch, and starting
commit decisions from ADR 0009 apply only to app-dev AppInstances.

### Preserve Route independence and defer balancing policy

A Route retains one-to-many targets. Each target references an AppInstance in
the Route's App and Cluster, and one Route can have at most one target on a
given Node. This prevents duplicate eligibility for the same workload Node
without tying the Route to Node identity directly.

The data model can contain multiple production targets, but a separate
implementation decision defines balancing, health weighting, stickiness,
failover, and target-draining policy. This ADR does not select one.

Ingress forwards the original hostname to Router and never receives the
backend pool. Public DNS and certificates therefore remain stable while the
Router changes target selection.

## Consequences

- Public exposure, backend selection, and workload placement have one clear
  owner each.
- Development keeps Orbit-owned independent clones while production accepts
  operator-deployed application content.
- An Ingress, Router, and app-prod workload can share one Node for a small
  Cluster without changing the logical request path.
- Production can later add multiple Ingress Nodes, external load balancers,
  DNS automation, or balancing policy without changing AppInstance ownership.
- Orbit can validate placement and runtime safety but cannot prove or roll
  back application content it does not deploy.
- Public publication remains unavailable when a Cluster has no active
  Ingress, while private Router traffic remains independent.
