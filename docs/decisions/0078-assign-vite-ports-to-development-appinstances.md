---
title: "ADR 0078: Assign Vite ports to development App instances"
sidebarTitle: "0078 Assign Vite ports to App instances"
description: "Proposed. Replace the shared Vite port with a stored port assignment per development App instance and Node."
---

# ADR 0078: Assign Vite ports to development App instances

Orbit stores a preferred Vite port for each development App instance and checks it before startup. A detected conflict triggers coordinated reassignment before the Process starts. The service environment, proxy, and readiness check use the selected assignment. Explicit ports with strict binding keep the runtime deterministic across Laravel and other Vite applications.

## Status

Proposed.

This proposal supersedes the fixed `127.0.0.1:5173` upstream in [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin). It preserves that decision's Route origin, reserved path, and HTTPS proxy boundaries. The feature PR implements this decision.

## Context

Several development App instances may run on the same Node. The shared port in ADR 0067 cannot identify their separate Vite servers. [Hibernation](/reference/app-dev-runtime-hibernation) also needs the correct endpoint before it admits application traffic after a wake.

Vite can select another port when its preferred port is occupied. Discovering that choice on every start would require a shared runtime integration: Laravel's Vite plugin writes `public/hot`, but plain Vite applications do not. Under Orbit's proxy contract, Laravel's hot file contains the public Route URL rather than the local upstream port.

The chosen design favors a stored preferred assignment over startup discovery. A stored assignment prevents another Orbit instance from receiving the same port on that Node. It does not keep a socket open while the process sleeps or prevent an unmanaged process from binding the port.

## Decision

- The Gateway must automatically assign a `vite_port` when it creates or registers a development App instance. Assignment must not depend on Laravel detection or on a Vite Process already existing.
- Allocation must prefer the recorded port and search subsequent valid unprivileged TCP ports when a replacement is needed. Initial allocation starts at `5173`; Orbit does not reserve a fixed-size range for Vite. The Gateway must exclude other assignments, excluded service ports, and bound TCP ports on the destination Node, including IPv4 and IPv6 bindings. Assignments on other Nodes must not exclude a port.
- Allocation and startup preparation must run under the Node's operation owner and enforce database uniqueness for `(node_id, vite_port)`. A retry must reuse its recorded candidate unless a new conflict requires replacement. Search must terminate at the TCP port limit and report exhaustion without assigning a duplicate or leaving a partial new placement.
- The Process environment file, workload Caddy upstream, and wake-readiness check must derive their port from the same stored assignment. Orbit must finish those projections before starting Vite or admitting traffic. Vite must use strict port binding and must not silently select another port.
- The assignment must survive idle hibernation, dependency pruning, Process replacement, and host reboot. Removing an App instance must release its assignment only after owned runtime cleanup completes.
- Transfer must allocate a port on the destination Node before preparing destination runtime. It must preserve the source assignment for recovery until cutover and source cleanup complete. It must update destination Process configuration, proxying, and readiness together.
- Vite must listen on Node loopback and publish development assets and HMR on the Route's HTTPS origin through the reserved path from ADR 0067. Browsers must not depend on a workload address or the assigned port.
- The preset sets the Vite base path to `/__orbit/vite/`, and Caddy preserves it for assigned endpoints. This keeps generated module imports on the reserved path. Legacy unassigned endpoints retain prefix stripping.
- Orbit must not discover the port from `public/hot`, process logs, or a custom Vite discovery plugin. Laravel retains its normal hot-file integration; the allocation contract also applies to plain Vite and VitePlus applications.
- Start, restart, and wake must distinguish an already running owned listener from a conflicting listener. An identical start of a healthy Process must keep its port. A conflicting listener must trigger coordinated reassignment without stopping the unrelated process.
- A bind conflict after the availability check must not establish readiness. Orbit may retry confirmed bind conflicts through the same preparation operation within a bounded startup deadline. Other startup failures must not trigger port changes. Failed projection or startup keeps wake incomplete.

## Rejected alternatives

- One shared port per Node: it cannot serve concurrent Vite processes for several App instances.
- Automatic Vite port fallback and hot-file discovery: hot files are framework-specific and do not reliably identify an internal endpoint behind the Route proxy.
- A custom discovery plugin for all applications: it adds an application dependency and endpoint discovery to every startup.
- Cluster-wide port uniqueness: separate Nodes can bind the same port without conflict.
- Keeping a socket reserved during hibernation: the database assignment prevents duplicate Orbit allocations; strict startup reports external conflicts.

## Consequences

- All development lifecycle entry points share one allocator, including registration and transfer.
- Caddy is configured before Vite starts. Wake keeps the upstream when the preferred port is available and updates it when reassignment is required.
- Applications and agents must consume Orbit's assigned port with strict binding. Existing fixed-port processes need an explicit migration.
- Availability checks and binding are separate operations. Strict startup and bounded conflict recovery must prevent an unrelated listener from being accepted as ready.
- An explicit `vp-dev` Process preset connects registration with port preparation and service configuration. A Process name alone must not enable this behavior.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/e2e
- ADRs: supersedes the fixed upstream in [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin); extends [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes) and [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes)
- Detail: [Assigned Vite ports proposal](/reference/assigned-vite-ports)
- Verify: allocator concurrency and exhaustion tests; lifecycle retry, removal, and transfer tests; Process environment and Caddy projection tests; Incus wake, bind-conflict, and browser asset/HMR checks for Laravel and plain Vite applications
