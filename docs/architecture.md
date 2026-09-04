# Architecture

Orbit is built around one Gateway. You use the CLI to ask the Gateway to make
changes, and the Gateway coordinates the machines managed by Orbit. These
machines are called Nodes.

This is how a command reaches a Node:

```text
Human or AI agent
        ↓
       CLI
        ↓
      HTTP
        ↓
     Gateway
        ↓
       SSH
        ↓
  Managed Nodes
```

Web traffic follows a separate path. A Node-scoped Route can reach its workload Node directly. A Cluster-scoped Route passes through the Router, and public traffic first passes through the Ingress. Active Cluster membership selects Cluster scope independently from whether the Node or Cluster supplied the hostname suffix.

## CLI

The CLI lives in `apps/cli`. It is the main way to use Orbit from a terminal.
It shows clear output to humans and can return structured output for scripts
and agents.

The CLI sends HTTP requests to the Gateway.

## Gateway

The Gateway lives in `apps/gateway`. It stores Orbit's data, authorizes actions,
and coordinates changes on Nodes. Because an Orbit setup has one active
Gateway, you always have one place to see the machines and applications managed
by Orbit.

The Gateway stores its data in SQLite. Nodes hold the files and run the services
needed to apply those settings.

## Nodes and roles

A Node is a machine connected to Orbit. It can remain standalone or belong to
one optional Cluster, and it can have one or more roles. For example, an
`app-dev` Node runs development applications, while a Router sends clustered
traffic to the right application.

The Gateway manages Nodes over SSH. After setup, WireGuard provides the private
network used for those connections. Orbit manages the files and services needed
by each Node's assigned roles.

## Applications and traffic

Orbit can group related Nodes in a Cluster, but a Cluster is not required. An App represents an application and owns its repository, default branch, relative web root, shared settings, and Routes. An AppInstance represents one place on a Node where that App is developed or runs in production. Cluster placement is derived from the Node rather than selected or stored on the AppInstance. A Route stores one hostname, one direct Node or active Cluster routing scope, publication and lifecycle intent, and ordered AppInstance targets in that App and scope. The public API configures at most one target.

A managed-clone development AppInstance uses one independent Git clone at
*<apps-root>/<app-slug>/<instance-name>*. Orbit records this path before source
work and never moves it when the Node apps root changes. Creation has four
durable states:

```text
reserved -> checkout_prepared -> source_resolved -> active
```

Each retry verifies the evidence stored by the current state. Orbit selects an
existing remote branch with the AppInstance name. If that branch does not
exist, Orbit creates it from the exact fetched App default branch commit.
Source resolution records the selected branch and starting commit before the
AppInstance becomes active.

After the development source becomes active, the Gateway records its pending Route and target. An optional hostname creates an explicit Route; otherwise the Gateway generates the hostname from the Node TLD, with the active Cluster TLD as a fallback. Route scope comes from active Cluster membership even when the hostname uses the Node TLD. Route creation does not activate traffic or store convergence failure metadata. Runtime prerequisites belong to the Node's app-dev role. See [Applications](domains/applications.md) and [Routes](reference/routes.md).

A Node keeps its own optional TLD when it joins a Cluster. The Node TLD remains the first choice for generated names. An active Cluster TLD is the fallback when the Node has no TLD. Active Cluster membership changes each affected Route to Cluster scope whether or not the Cluster has a TLD. A Cluster that owns a Route requires one active Router. Public traffic always enters through Ingress before Router and workload Caddy.

The Gateway validates and updates every affected generated hostname and scope in one database operation before a Node or Cluster TLD, state, membership, attach, or detach change becomes authoritative. Traffic convergence owns the related DNS, TLS, Caddy, firewall, health, request-path, activation, and failure-metadata work. [ADR 0023](decisions/0023-separate-hostname-selection-from-cluster-routing.md) defines the separation of naming and routing.

Legacy Instance and Workspace records remain available during the [staged conversion and verified cutover defined by ADR 0009](decisions/0009-clustered-app-instance-routing.md#convert-legacy-resources-in-stages). New instance commands use AppInstance. Creating or changing a Route does not change a legacy hostname or certificate field. Route projection, runtime, conversion, and Ingress work remain separate.

## Doctor

`orbit doctor` compares what the Gateway expects with what is actually on a
Node. It reports problems without changing the machine. This behavior is
described in [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).

## Testing on real Linux machines

Automated tests cover most Orbit behavior. When a change depends on Linux,
systemd, file permissions, networking, or several machines, it is also tested
in a fresh Incus environment. [ADR 0006](decisions/0006-topology-led-feature-development.md)
explains why Orbit uses this approach.

## Documentation tools

The `apps/docs` project checks the Markdown files in `docs/` and builds the
index used by `composer docs-context`. It runs during development and does not
provide a website or production service. The approach is explained in
[ADR 0014](decisions/0014-maintain-verified-documentation-context.md).

The `apps/e2e` project creates the temporary Incus machines used for these
tests. Keeping it separate from the product code makes the test environment
easier to trust.
