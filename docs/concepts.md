# Concepts

These are the common terms you will see throughout the Orbit documentation. Each entry defines its term on its own, and a link leads to the page or decision that owns the detail.

- **Gateway** — The central Orbit service. It stores every machine and application record, authorizes each action, and applies changes to machines over SSH. See [Architecture](architecture.md#gateway).
- **Node** — A machine connected to Orbit. It runs one or more roles and reaches the Gateway over WireGuard. A Node outside a Cluster is standalone.
- **Cluster** — An optional group of Nodes. Active membership gives a member's Routes Cluster scope. Its optional TLD is only a fallback for generated hostnames. See [ADR 0023](decisions/0023-separate-hostname-selection-from-cluster-routing.md).
- **App** — An application managed by Orbit. It holds the repository URL, default branch, and relative web root that every AppInstance inherits. See [Apps](reference/apps.md).
- **AppInstance** — One placement of an App on one Node. A development placement owns an independent Git clone with its own branch. Its Cluster comes from its Node. See [Applications](domains/applications.md) and [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Effective web root** — The web root an AppInstance serves. An AppInstance override replaces the App root. Both are normalized relative paths.
- **Legacy Instance** — The earlier runnable application record. Orbit retains it for existing Workspace and Doctor behavior. New development placements use AppInstance.
- **Workspace** — A Git worktree owned by a legacy Instance. AppInstance creation does not use or change Workspace source.
- **Route** — An App-owned hostname and routing record. It stores provenance and publication intent. It has one scope and an ordered target set. Public operations configure at most one target. See [Routes](reference/routes.md).
- **Router** — The Node role that forwards traffic for Cluster-scoped Routes. Every Cluster that owns a Route has one active Router.
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and forwards it to the Router. See [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Doctor** — The check that compares what the Gateway expects with what is on a Node and reports every difference. Doctor never changes a machine. See [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).
- **Proof topology** — A disposable group of Incus machines built for one issue. The harness runs the issue's proof plan on it against one exact commit and keeps the result immutable. See [Incus topologies](reference/incus-topologies.md).
- **Documentation context** — The ordered list of pages that `composer docs-context` selects for a component or concept. A contributor or agent reads it before changing that part of Orbit.
