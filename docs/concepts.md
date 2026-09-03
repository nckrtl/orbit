# Concepts

These are the common terms you will see throughout the Orbit documentation.

- **Gateway** — The central Orbit service. It stores information about your
  machines and applications, authorizes actions, and coordinates changes. See
  [Architecture](architecture.md#gateway).
- **Cluster** — An optional group of Nodes. A Cluster becomes their shared
  routing scope only while it is active and has a TLD. See
  [ADR 0017](decisions/0017-optional-cluster-placement-and-tld-precedence.md).
- **Node** — A machine connected to Orbit. A Node can remain standalone or
  belong to one Cluster, and it can run one or more roles. Its own TLD remains
  the fallback when no active Cluster TLD applies. See
  [ADR 0012](decisions/0012-ubuntu-24-04-roleless-operator-clients.md) and
  [ADR 0017](decisions/0017-optional-cluster-placement-and-tld-precedence.md).
- **App** — An application managed by Orbit. It holds the repository, main
  branch, relative web root, and other settings shared by its AppInstances. See
  [Apps](reference/apps.md) and
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **AppInstance** — One place on a Node where an App is developed or runs in
  production. It belongs to the App and Node; any Cluster routing scope comes
  from the Node. See
  [ADR 0017](decisions/0017-optional-cluster-placement-and-tld-precedence.md).
- **Route** — A hostname that sends traffic to one or more AppInstances. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Router** — The Node role that sends traffic for an active TLD-bearing
  Cluster to the right AppInstance. An active Cluster without a TLD does not
  require a Router. See
  [ADR 0017](decisions/0017-optional-cluster-placement-and-tld-precedence.md).
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
