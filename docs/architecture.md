# Architecture

This document is a current synthesis of Orbit's accepted architecture. The
linked ADRs remain authoritative when implementation or summary text is
incomplete.

## Components

- `apps/gateway` is the Laravel control plane. It owns typed validation,
  durable state, authorization, convergence policy, and API behavior.
- `packages/php-sdk` is the framework-neutral typed transport for the Gateway
  API. It preserves Gateway contracts without adding product policy.
- `apps/cli` is the Laravel Zero client. It owns operator interaction and calls
  the Gateway through the SDK.
- `apps/e2e` is the external Incus proof harness. It owns disposable topology
  lifecycle, not product behavior.
- `apps/docs` is the console-only documentation toolchain. It reads the root
  `docs/` corpus and owns linting, context generation, and their tests.

The product model groups Nodes and workloads into Clusters. Apps provide
logical application identity, AppInstances provide concrete development or
production placements, Routes connect hostnames to AppInstances, Routers own
Cluster-local target selection, and Ingress owns public HTTP and TLS exposure.
The governing contracts are [ADR 0009](decisions/0009-clustered-app-instance-routing.md)
and [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).

This clustered application model is an accepted target that is still being
implemented. The current Gateway retains the legacy Instance and Workspace
resources while that migration is incomplete. Inspect the implementation issue
and current code before assuming that AppInstance, Route, or Ingress APIs are
available.

## Relationships

The CLI sends typed requests through the PHP SDK to the Gateway. The Gateway
authenticates and authorizes the caller, stores desired state, and converges the
owned projection on a Node. Workload traffic remains separate from this
control-plane path.

A Cluster has one Router for private Route handling and may have an Ingress for
public Routes. Router and Ingress roles can be co-located where the governing
placement rules permit it, without collapsing their ownership. Workload Node
Caddy serves the AppInstances placed on that Node.

Documentation connects to the same system without becoming another authority.
The context index routes agents from expected components and concepts to
governing ADRs, current-state documents, references, and solutions as defined
by [ADR 0014](decisions/0014-maintain-verified-documentation-context.md).

## State

The Gateway stores Orbit-owned desired state in SQLite. Managed projections on
Nodes represent the current runtime state. Verify-only Doctor operations report
differences without mutation under
[ADR 0004](decisions/0004-verify-only-doctor-boundary.md).

Repository tests prove deterministic behavior. Changes that depend on a real
operating system, service manager, privilege boundary, filesystem ownership,
network, certificate, or multi-node interaction use a fresh Incus proof
topology under [ADR 0006](decisions/0006-topology-led-feature-development.md).

Documentation source remains under root `docs/`. The committed context index
is derived state and must match that corpus exactly.

## Boundaries

Accepted ADRs own significant architecture; Linear issues own bounded
implementation outcomes. Current-state documentation explains both without
silently changing either one.

The Gateway owns product policy. The SDK does not reinterpret it, and the CLI
does not bypass it. The proof harness does not become product code. The
documentation application does not provide a production service or maintain a
second content tree.

Product feature branches do not modify the harness. Production releases do not
reuse disposable proof resources. Generated documentation never becomes
product authority.
