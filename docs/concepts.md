# Concepts

These are the common terms you will see throughout the Orbit documentation.

- **Gateway** — The central Orbit service. It stores information about your
  machines and applications, authorizes actions, and coordinates changes. See
  [Architecture](architecture.md#gateway).
- **Cluster** — A group of Nodes and applications that share networking and
  routing. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Node** — A machine connected to Orbit. A Node can run one or more roles.
  See
  [ADR 0012](decisions/0012-ubuntu-24-04-roleless-operator-clients.md).
- **App** — An application managed by Orbit. It owns the Git repository,
  default branch, relative web root, and other settings shared by its
  AppInstances. Legacy Apps can have null source defaults until a later
  conversion supplies them. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **AppInstance** — One placement of an App on a Node and in that Node's
  Cluster. A development AppInstance owns an independent Git clone, a branch,
  an immutable checkout path, a starting commit, and an optional relative web
  root override. See [Applications](domains/applications.md) and
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Effective web root** — The AppInstance root override when one is stored,
  or the App root otherwise. Both values are normalized relative paths.
- **Legacy Instance** — The previous runnable application record. Orbit keeps
  this record as transitional input for existing Workspace and Doctor behavior.
  New development placements use AppInstance.
- **Workspace** — A transitional Git worktree owned by a legacy Instance.
  AppInstance creation does not use or change Workspace source.
- **Route** — A hostname that sends traffic to one or more AppInstances. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Router** — The Node role that sends private traffic to the right
  AppInstance. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and
  forwards it to the Router. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Doctor** — A check that compares what Orbit expects with what is actually
  on a machine. Doctor reports problems without changing anything. See
  [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).
- **Proof topology** — A temporary group of Incus machines used to test a
  change on Linux. See
  [Incus topology registry](reference/incus-topologies.md).
- **Documentation context** — A short list of pages that helps a contributor or
  agent understand the part of Orbit being changed. See
  [ADR 0014](decisions/0014-maintain-verified-documentation-context.md).
