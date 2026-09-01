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

Web traffic follows a separate path. It passes through the Router and, for
public sites, the Ingress before reaching the Node that runs the application.

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

A Node is a machine connected to Orbit. A Node can have one or more roles. For
example, an `app-dev` Node runs development applications, while a Router sends
traffic to the right application.

The Gateway manages Nodes over SSH. After setup, WireGuard provides the private
network used for those connections. Orbit manages the files and services needed
by each Node's assigned roles.

## Applications and traffic

Orbit groups related Nodes and applications in a Cluster. An App represents an
application and owns its repository, default branch, relative web root, and
shared settings. An AppInstance represents one place where that App is
developed or runs in production. Each AppInstance belongs to the Cluster of
its selected Node. A Route connects a hostname to an AppInstance.

A development AppInstance uses one independent Git clone at
*<apps-root>/<app-slug>/<instance-name>*. Orbit records this path before source
work and never moves it when the Node apps root changes. Creation has four
durable states:

    reserved -> checkout_prepared -> source_resolved -> active

Each retry verifies the evidence stored by the current state. Orbit selects an
existing remote branch with the AppInstance name. If that branch does not
exist, Orbit creates it from the exact fetched App default branch commit.
Source resolution records the selected branch and starting commit before the
AppInstance becomes active.

This development lifecycle owns source only. It does not install PHP, converge
an application runtime, publish Caddy configuration, create certificates,
change DNS, or create Routes. The Node app-dev role owns runtime prerequisites.
Later issues own runtime and publication behavior. See
[Applications](domains/applications.md).

A Cluster has one Router for private traffic. Public traffic first reaches the
Ingress, which accepts the connection and forwards it to the Router. Caddy on
the Node then sends the request to the application. You can read more about
this design in
[ADR 0009](decisions/0009-clustered-app-instance-routing.md) and
[ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

Legacy Instance and Workspace records remain available during staged
conversion. New instance commands use AppInstance. Route, runtime, and Ingress
work remains separate.

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
