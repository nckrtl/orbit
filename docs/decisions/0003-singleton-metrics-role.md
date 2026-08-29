# ADR 0003: Adopt the singleton Metrics role contract

## Status

Accepted on 2026-08-28.

## Context

Orbit needs private host-resource observability for its managed fleet. The old
Orbit implementation provided Prometheus, Grafana, node-exporter, private
publication, and Grafana credential access. It also depended on Docker Swarm,
custom image delivery, generic credentials, and control-plane systems that are
not part of current Orbit.

This capability crosses role lifecycle, remote host management, Gateway
publication, authorization, secret storage, PHP SDK transport, and CLI
workflows. It needs one durable contract for placement, runtime ownership,
exporter selection, access, secrets, removal, and component responsibilities.

This decision records the architecture approved in the
[Metrics role design](file:///home/nckrtl/shared-knowledge/projects/orbit/superpowers/specs/2026-08-28-orbit-metrics-role-design.md).
The old implementation is evidence for useful behavior, not a source tree to
restore.

## Decision

### Model Metrics as one mutable role

`metrics` is a code-defined managed Linux role. At most one node in the
topology can have a provisioning, active, failed, or removing Metrics
assignment. The singleton claim is authoritative during concurrent changes.

Metrics can share a node with `gateway`, `vpn`, `app-dev`, or `app-prod`. It
uses the generic node-role lifecycle for assignment, convergence, failure
recovery, and removal. A failed assignment is recovered through generic role
convergence. Orbit does not add a separate Metrics lifecycle or asynchronous
reconciler.

### Keep the runtime boundary closed

The Metrics role owns Prometheus and Grafana as standalone Docker containers.
Orbit uses pinned official upstream images, deterministic container and volume
names, validated configuration, health checks, rollback, and proof of
ownership before replacement or deletion. Repeated convergence is idempotent.

The Metrics role also owns node-exporter configuration and systemd lifecycle
on selected nodes. It binds node-exporter to each node's WireGuard address and
uses Metrics-owned firewall rules. Shared packages, Docker, and unrelated
runtime state stay outside Metrics ownership.

Prometheus uses local storage with 15-day retention. Grafana receives one
managed Prometheus datasource and the provisioned `Orbit Node Resources`
dashboard. Prometheus targets use stable Orbit node names in the `node` label.

Prometheus, Grafana, and node-exporter do not create public `Process` rows.
Their role-owned prerequisites do not create public `Tool` rows. Orbit does
not use Docker Swarm, Compose, custom images, image builds, image distribution,
or a registry for this role.

### Select exporters from role state and explicit preference

Each node can store one Metrics-owned exporter preference: enabled, disabled,
or absent. Registration and adoption do not create a preference.

The selected exporter projection follows these rules:

- A role-bearing node with no preference is selected.
- A roleless node with no preference is not selected.
- An explicit enabled preference selects any active node, including a
  roleless node.
- An explicit disabled preference excludes a non-Metrics node.
- The current Metrics node is always selected and cannot disable its exporter.

Preferences survive role changes and periods when Metrics is disabled. Metrics
convergence applies the projection to node-exporter services, firewall rules,
and Prometheus targets. Provisioning, adoption, role changes, and node removal
use the prospective projection. Orbit does not publish a target before its
exporter has converged.

### Publish Grafana only through the Gateway

`metrics.orbit` is a private fleet endpoint. Private DNS resolves it to the
Gateway's WireGuard address. Gateway Caddy terminates an Orbit-CA certificate
and proxies over WireGuard to Grafana on the Metrics node. A Metrics-owned
upstream firewall rule accepts Grafana traffic only from the Gateway's
WireGuard address. Prometheus is not published as a fleet endpoint.

Metrics removal deletes the owned DNS, certificate publication, Caddy
fragment, upstream firewall rule, and generated Prometheus configuration.

### Authorize against access to the Gateway node

The active Gateway role node is the authority for every focused Metrics API
operation. The Gateway node has implicit access. Every other caller needs a
directed node-access grant to the active Gateway node. Access to only the
Metrics node or an exporter node is insufficient.

Authorization runs before Metrics state or credentials are read or changed.
Generic node provisioning and node-role mutation enforce the same rule when
the requested role is `metrics`. A caller cannot bypass the Metrics authority
through the generic role API. Grafana still requires its own login.

### Keep Grafana credentials inside the Metrics domain

The Grafana username is `admin`. Initial convergence generates a random
password and stores it as an encrypted node setting on the Metrics node.
Repeated convergence reuses that password. Orbit does not add a generic
credential entity or public credential command family.

Credential reset uses encrypted active and pending password settings. It
creates or reuses the pending password, applies it through protected input,
verifies Grafana authentication, promotes it to active, and then deletes the
pending value. A retry after partial failure resumes this sequence. Orbit
never returns an unverified password.

Only the explicit Metrics credential response can contain the password. That
response is not cacheable. Status, node, role, activity, logs, exceptions, and
debug output do not expose either password setting.

### Preserve state unless purge is explicit

Normal Metrics removal stops and removes the owned containers, exporters,
managed configuration, publication, and firewall rules. It preserves the
Prometheus and Grafana volumes, the active Grafana password, installed shared
packages, Docker, and exporter preferences. Re-enabling Metrics can reuse the
preserved data and credential.

An explicit forced purge can also delete proven Metrics volumes, generated
Metrics data, and active or pending Grafana password settings. It does not
delete shared packages, Docker, unrelated containers, user files, or exporter
preferences. Ambiguous ownership fails closed.

### Split component ownership

The Gateway owns role and exporter policy, authorization, settings, locks,
remote operations, Docker and systemd convergence, configuration, private
publication, credentials, status, lifecycle state, stable failures, and
redaction.

The PHP SDK owns typed transport for the focused Metrics routes. It preserves
request IDs, structured errors, bounded responses, and secret-safe debugging
without applying Metrics policy.

The CLI owns interactive node selection, confirmation, force requirements,
and deterministic human or JSON output. It calls only the PHP SDK and contains
no infrastructure, exporter, credential, authorization, or role policy.

## Consequences

- Orbit restores useful fleet resource metrics without restoring the old
  control plane or an image-delivery system.
- One Metrics node simplifies placement and private publication but does not
  provide high availability or rolling Metrics updates.
- Standalone Docker keeps development and upgrades simple. A later need for
  rolling Gateway updates can justify a separate Docker Swarm decision.
- Default exporter coverage follows workload roles, while an explicit
  preference can include a roleless node or exclude a non-Metrics node.
- Prospective exporter convergence couples relevant node and role changes to
  synchronous Metrics reconciliation.
- Gateway-based authorization gives the Metrics command family one stable
  control-plane authority even when Metrics runs on another node.
- Domain-owned encrypted settings avoid a generic credential subsystem but
  require a recoverable pending-secret flow.
- Data-preserving removal makes re-enablement safe. Destructive cleanup needs
  explicit purge intent and proven ownership.
- Docker, systemd, firewall, WireGuard, DNS, TLS, Caddy, and multi-node
  behavior require live proof on a registered disposable topology before the
  dependent feature can merge.
