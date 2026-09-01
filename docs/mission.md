# Mission

Orbit is a typed, agent-friendly system for developing and operating Laravel
applications across infrastructure the operator controls. It gives humans and
agents one verifiable path from repository intent to managed Nodes and running
application workloads.

## Why

Application development and infrastructure operation otherwise fragment state,
identity, networking, deployment, and diagnostics across unrelated tools. That
fragmentation forces contributors and agents to reconstruct system ownership
before each change and makes unsafe assumptions difficult to detect.

Orbit keeps durable intent, typed operations, verification, and reusable
documentation close enough that new work can start from established contracts
instead of rediscovering them.

## How

The Gateway owns durable fleet state and exposes typed HTTP operations. The PHP
SDK transports those contracts, while the CLI provides deterministic human and
machine-readable interaction. Managed Nodes host role-specific infrastructure
and application workloads. Repository tests and disposable Incus proof
topologies verify behavior at the boundary proportional to each change.

Accepted ADRs record significant direction before implementation issues.
Maintained documentation then explains the resulting system and is reconciled
as each feature lands.

## What

Orbit manages the control-plane and workload concepts needed to connect local
development and self-operated production: Clusters, Nodes, Apps, AppInstances,
Routes, Ingress, runtime roles, tools, processes, metrics, and supporting
network and certificate state.

The monorepo contains the `apps/cli`, `apps/gateway`, `apps/docs`, `apps/e2e`,
and `packages/php-sdk` projects. Each project owns its code and verification;
root commands coordinate repository-wide work.

## Boundaries

Orbit owns only state and resources covered by an accepted contract. It does
not infer ownership from arbitrary host state, turn typed inputs into a generic
remote-execution surface, or treat a successful automated test as proof of a
real machine boundary when Incus proof is required.

Generated context helps contributors find maintained sources but never creates
product authority. Production deployment remains separate from disposable
development proof.
