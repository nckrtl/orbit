# Concepts

These are the common terms you will see throughout the Orbit documentation. Each entry defines its term on its own, and a link leads to the page or decision that owns the detail.

- **Gateway** — The central Orbit service. It stores every machine and application record, authorizes each action, and applies changes to machines over SSH. See [Architecture](architecture.md#gateway).
- **Node** — A machine connected to Orbit. It runs one or more roles and reaches the Gateway over WireGuard. A Node outside a Cluster is standalone.
- **Cluster** — An optional group of Nodes with one name and at most one development TLD. Active membership gives member Routes Cluster scope. The Cluster can have no TLD. See [ADR 0023](decisions/0023-separate-hostname-selection-from-cluster-routing.md).
- **App** — An application managed by Orbit. It owns one repository identity and access URL, plus the default branch and relative web root that every AppInstance inherits. See [Apps](reference/apps.md).
- **Repository identity** — The transport-independent Git host and path that one App owns after optional terminal `.git` removal. See [Apps](reference/apps.md) and [ADR 0026](decisions/0026-identify-each-app-by-one-repository.md).
- **AppInstance** — One placement of an App on one Node. It inherits Cluster membership from the Node. An active AppInstance has one Route and returns its hostname. See [Applications](domains/applications.md) and [ADR 0028](decisions/0028-require-one-route-per-active-appinstance.md).
- **Effective web root** — The web root an AppInstance serves. An AppInstance override replaces the App root. Both are normalized relative paths.
- **Legacy Instance** — The earlier runnable application record. Orbit retains it for existing Workspace and Doctor behavior. New development placements use AppInstance.
- **Workspace** — A Git worktree owned by a legacy Instance. AppInstance creation does not use or change Workspace source.
- **Route** — An App-owned hostname with stored provenance and one Node or active Cluster scope. A generated Route follows its target Node. See [Routes](reference/routes.md) and [ADR 0024](decisions/0024-follow-generated-route-targets.md).
- **Router** — The Node role that receives Routes with Cluster scope and selects their workload targets. Every Cluster with a Route needs one active Router.
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and forwards it to the Router. See [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Doctor** — The check that compares what the Gateway expects with what is on a Node and reports every difference. Doctor never changes a machine. See [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).
- **Proof topology** — A disposable group of Incus machines built for one issue. The harness runs the issue's proof plan on it against one exact commit and keeps the result immutable. See [Incus topologies](reference/incus-topologies.md).
- **Documentation context** — The ordered list of pages that `composer docs-context` selects for a component or concept. A contributor or agent reads it before changing that part of Orbit.
