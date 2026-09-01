# Concepts

This index defines canonical Orbit terms and links each term to its owning
architecture or reference document. Keep definitions short; detailed behavior
belongs in the linked source.

- **Gateway** — The singleton control plane that owns durable Orbit state,
  authorization, typed API behavior, and convergence policy. See
  [Architecture](architecture.md#components).
- **Cluster** — The trust and routing boundary that groups Nodes, AppInstances,
  and Routes. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Node** — An enrolled machine with control-plane identity and optional
  Orbit-managed roles. See
  [ADR 0012](decisions/0012-ubuntu-24-04-roleless-operator-clients.md).
- **App** — The logical identity and shared defaults for one application. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **AppInstance** — One concrete development checkout or production runtime
  placement for an App in a Cluster. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Route** — A hostname and target set that publishes AppInstances through a
  Cluster Router. See
  [ADR 0009](decisions/0009-clustered-app-instance-routing.md).
- **Router** — The Cluster role that owns Route target selection and private
  workload routing. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Ingress** — The Cluster role that owns public listeners, public TLS, edge
  policy, and forwarding to the Router. See
  [ADR 0011](decisions/0011-clustered-production-ingress-and-app-prod-placement.md).
- **Doctor** — Verify-only inspection of desired and observed state. See
  [ADR 0004](decisions/0004-verify-only-doctor-boundary.md).
- **Proof topology** — A fresh disposable Incus environment used to prove one
  exact issue and commit. See
  [Incus topology registry](reference/incus-topologies.md).
- **Documentation context** — The ordered routing set derived from maintained
  docs for an affected component or concept. See
  [ADR 0014](decisions/0014-maintain-verified-documentation-context.md).
