# ADR 0012: Allow Ubuntu 24.04 role-less operator clients

## Status

Accepted on 2026-08-31.

## Context

Orbit restricts every managed infrastructure role to Ubuntu 26.04 Resolute.
That boundary protects convergence because Gateway, VPN, Router, Ingress,
app-dev, app-prod, Metrics, and future managed roles depend on one known
operating-system contract.

An operator client has a different responsibility. It runs the Orbit CLI,
joins the WireGuard control plane, and calls the Gateway API. It does not host
an Orbit-managed role or accept Gateway-owned convergence. Requiring Ubuntu
26.04 for that client-only responsibility prevents an otherwise compatible
Ubuntu 24.04 operator machine from participating without improving managed
infrastructure safety.

Orbit needs to support that client without turning Ubuntu 24.04 into a valid
managed-node platform, weakening active-peer identity, or giving the Gateway
the client's private key.

## Decision

### Define a role-less operator-client boundary

Orbit supports Ubuntu 24.04 as an operator client only when its Node has no
managed infrastructure role. An Ubuntu 24.04 operator client is ineligible for
Gateway, VPN, Router, Ingress, app-dev, app-prod, Metrics, and every other
managed role.

Ubuntu 26.04 remains the only supported operating system for managed roles.
Role assignment always enforces the managed-role operating-system policy at
the time of assignment. Client enrollment cannot bypass or weaken that guard.

This decision does not add macOS support and does not broaden the supported
operating systems for PHP, Composer, services, workloads, or convergence.

### Keep private identity on the client

The operator client generates its WireGuard private key locally. Enrollment
sends only the public key and the bounded client identity required by the
Gateway. The Gateway allocates and persists the peer address and active Node
identity, and returns the public peer configuration needed by the client. It
never receives, generates, logs, or stores the client private key.

API use requires both an active WireGuard peer identity and the explicit
directed node-access relationships needed for the requested resources.
Enrollment does not grant ambient fleet access.

### Do not impose managed-node SSH or convergence

A role-less operator client does not require Gateway-to-client SSH and receives
no managed-role convergence. Lack of SSH reachability, role packages, systemd
units, Caddy, PHP, or other managed projections is not drift for this client
class.

Doctor can report Gateway-owned enrollment, peer, lifecycle, and access-edge
state. Client-local operating-system, WireGuard, and CLI state is unverifiable
unless the client supplies a separate bounded observation. Doctor must not
pretend that absence of Gateway SSH proves either health or drift.

### Revoke authority on removal and recover by reenrollment

Removing an operator client revokes its active peer identity and its directed
access relationships before removing the Node record. Removal does not attempt
managed-role cleanup because the client owns its local CLI and WireGuard
configuration.

Recovery creates a new client-generated key and follows ordinary enrollment.
Orbit does not reconstruct, escrow, or reuse a lost private key. A retry with
the same still-valid public identity is idempotent; a conflicting identity
fails without rewriting the existing peer.

## Consequences

- Ubuntu 24.04 operators can use the CLI without becoming managed
  infrastructure Nodes.
- Ubuntu 26.04 remains the single managed-role platform and its convergence
  contract stays closed.
- The Gateway can authorize and revoke operator access without possessing
  client private keys.
- Role-less clients have a deliberately smaller Doctor and recovery contract
  than managed Nodes.
- Supporting another operator-client operating system requires a later
  decision; this ADR does not create a general multi-platform client policy.
