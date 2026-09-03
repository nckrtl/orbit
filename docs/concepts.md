# Concepts

These are the common terms you will see throughout the Orbit documentation. Each entry defines its term on its own, and a link leads to the page or decision that owns the detail.

- **Gateway** — The central Orbit service. It stores every machine and application record, authorizes each action, and applies changes to machines over SSH. See [Architecture](architecture.md#gateway).
- **Node** — A machine connected to Orbit. It runs one or more roles and reaches the Gateway over WireGuard. A Node outside a Cluster is standalone.
- **Cluster** — An optional group of Nodes with one name and at most one development TLD. An active Cluster with a TLD owns routing for its members. See [ADR 0017](decisions/0017-optional-cluster-placement-and-tld-precedence.md).
- **App** — An application managed by Orbit. It holds the repository URL, default branch, relative web root, and other settings shared by its AppInstances. Legacy Apps can leave those source defaults empty until conversion. See [Apps](reference/apps.md).
- **AppInstance** — One placement of an App on one Node for development or production. A managed-clone development AppInstance owns an independent Git clone, selected branch, immutable checkout path, starting commit, and optional web root override. Its Cluster comes from its Node. See [Applications](domains/applications.md) and [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Effective web root** — The AppInstance web root override when it has one, or the App web root otherwise. Both are normalized relative paths.
- **Legacy Instance** — The previous runnable application record. Orbit retains it for existing Workspace and Doctor behavior while new development placements use AppInstance.
- **Workspace** — A Git worktree owned by a legacy Instance. AppInstance creation does not use or change Workspace source.
- **Route** — A hostname that sends traffic to one or more AppInstances of one App. A Route without a target answers with a fixed unavailable response. See [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Router** — The Node role that receives traffic for an active Cluster with a TLD and forwards it to the target AppInstance's Node. An active Cluster without a TLD does not require a Router.
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and forwards it to the Router. See [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Doctor** — The check that compares what the Gateway expects with what is on a Node and reports every difference. Doctor never changes a machine. See [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).
- **Proof topology** — A disposable group of Incus machines built for one issue. The harness runs the issue's proof plan on it against one exact commit and keeps the result immutable. See [Incus topologies](reference/incus-topologies.md).
- **Documentation context** — The ordered list of pages that `composer docs-context` selects for a component or concept. A contributor or agent reads it before changing that part of Orbit.
