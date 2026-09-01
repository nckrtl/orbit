# Architecture

Orbit has one central Gateway, a CLI, and a group of managed machines called
Nodes. The Gateway remembers how the system should look, while each Node runs
the work assigned to it.

The main flow is:

```text
Person or coding agent
        ↓
       CLI
        ↓
     PHP SDK
        ↓
     Gateway
        ↓
  Managed Nodes
```

Application traffic follows a separate path. It goes through the Router and,
for public sites, the Ingress before reaching the Node that runs the
application.

## CLI

The CLI lives in `apps/cli`. It is the main way to use Orbit from a terminal.
It shows readable output to people and can return structured output for scripts
and agents.

The CLI sends requests through `packages/php-sdk`. The SDK handles the Gateway
API without deciding how Orbit should behave.

## Gateway

The Gateway lives in `apps/gateway`. It stores Orbit's data, checks who may do
what, and coordinates changes on Nodes. There is only one active Gateway in an
Orbit setup, which gives you one place to understand the current state of the
machines and applications managed by Orbit.

The Gateway uses SQLite for its data. Nodes contain the files and services that
make that data real.

## Nodes and roles

A Node is a machine connected to Orbit. A Node can have one or more roles. For
example, an `app-dev` Node runs development applications, while a Router sends
traffic to the right application.

Nodes communicate with the Gateway over WireGuard. Orbit manages the files and
services needed by their assigned roles.

## Applications and traffic

Orbit groups related Nodes and applications in a Cluster. An App is the
application itself, while an AppInstance is one development or production
placement of that App. A Route gives an AppInstance a hostname.

A Cluster has one Router for private traffic. Public traffic first reaches the
Ingress, which handles the public connection and forwards the request to the
Router. Caddy on the workload Node then sends the request to the application.
The design is described in
[ADR 0009](decisions/0009-clustered-app-instance-routing.md) and
[ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

This application model is still being built. The current API still includes the
older Instance and Workspace names, and not every AppInstance, Route, or
Ingress feature is available yet. Check the current code before using those
newer APIs.

## Doctor

`orbit doctor` compares what the Gateway expects with what is actually on a
Node. It reports problems without changing the machine. This behavior is
described in [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).

## Testing on real Linux machines

Most behavior is covered by automated tests. Changes that depend on Linux,
systemd, file permissions, networking, or several machines are also tested in
a fresh Incus environment. See
[ADR 0006](decisions/0006-topology-led-feature-development.md) for the reason
behind this approach.

## Documentation tools

The `apps/docs` project checks the Markdown files in `docs/` and builds the
index used by `composer docs-context`. It is a development tool, not a website
or production service. The approach is explained in
[ADR 0014](decisions/0014-maintain-verified-documentation-context.md).

The `apps/e2e` project creates the temporary Incus machines used for proof. It
is separate from the product code, which makes the test environment easier to
trust.
