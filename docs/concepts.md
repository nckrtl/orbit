# Concepts

These are the main words you will see throughout the Orbit documentation.

- **Gateway** — The central Orbit service. It stores information about your
  machines and applications and coordinates changes across them. See
  [Architecture](architecture.md#gateway).
- **Cluster** — A group of Nodes and applications that share networking and
  routing. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Node** — A machine connected to Orbit. A Node can run one or more roles.
  See
  [ADR 0012](decisions/0012-ubuntu-24-04-roleless-operator-clients.md).
- **App** — An application managed by Orbit. It holds the settings shared by
  its development and production placements. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **AppInstance** — One development checkout or production placement of an App
  on a Node. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
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
- **Proof topology** — A temporary set of Incus machines used to test one
  change on real Linux systems. See
  [Incus topology registry](reference/incus-topologies.md).
- **Documentation context** — A short list of pages selected for the part of
  Orbit being changed. See
  [ADR 0014](decisions/0014-maintain-verified-documentation-context.md).
