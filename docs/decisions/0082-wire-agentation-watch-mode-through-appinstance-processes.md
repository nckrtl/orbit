---
title: "ADR 0082: Wire Agentation watch mode through App instance Processes"
sidebarTitle: "0082 Wire Agentation watch mode"
description: "Proposed. Run Agentation HTTP and the Antigravity watcher as ordinary development App instance Processes, expose AGENTATION_URL on the reserved Route path, and hibernate both with the App instance."
---

# ADR 0082: Wire Agentation watch mode through App instance Processes

Orbit runs Agentation watch mode as two development App instance Processes. The HTTP Process receives toolbar annotations on loopback. Workload Caddy publishes that server on the Route origin at `/__orbit/agentation`. Orbit projects `AGENTATION_URL` from that origin. The Antigravity watcher is a second desired-running Process with no keep-alive, so idle halt and HTTP wake start and stop it with the rest of the App instance group.

## Status

Proposed.

This proposal extends [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin), [ADR 0069](/decisions/0069-allow-node-process-targets), [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes), and [ADR 0078](/decisions/0078-assign-vite-ports-to-development-appinstances). It does not change Vite's reserved path, port allocator, or keep-alive contract.

## Context

The Agentation toolbar is already installed on application sites. Agents need a per-App HTTP companion that accepts annotations, a public HTTPS URL on the existing Route, and an Antigravity watcher that only runs while the development App instance is awake.

App instance Processes already own systemd units, desired state, start and stop, and hibernation. [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes) starts every desired-running Process on wake and stops those without keep-alive after idle HTTP silence. [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin) already publishes a reserved `/__orbit` path on the Route origin. Inventing a sidecar outside Process would duplicate ownership, Doctor, and hibernation.

Several development App instances can share one Node. Agentation's default port `4747` cannot identify those HTTP servers. Vite already stores a Node-scoped preferred port on the App instance; Agentation needs the same uniqueness for its HTTP listener, without reusing the Vite allocator or port range.

## Decision

- Orbit must expose Agentation through two explicit development App instance Process presets: `agentation-mcp` for the HTTP annotation server and `antigravity-watch` for the Antigravity watcher. A Process name alone must not enable either behavior.
- Both presets require a development App instance systemd Process. They own runtime, command, working directory, and environment configuration. Operators must not combine them with `--app`, `--node`, custom command or runtime flags, or `keep_alive`.
- One App instance may have at most one Process of each preset. The watcher requires an existing `agentation-mcp` Process on that App instance. Removing the HTTP Process while the watcher remains must fail closed.
- The Gateway must assign a Node-scoped `agentation_port` when it creates the HTTP Process. Initial allocation starts at `4747`. The assignment must survive hibernation and must be released after the HTTP Process is removed. Transferring the App instance must pick a free port on the destination Node when an assignment exists.
- Workload Caddy must reverse-proxy the reserved path `/__orbit/agentation` to `127.0.0.1:{agentation_port}` when that assignment exists. Caddy must strip the reserved prefix because the Agentation HTTP API serves `/health` and `/sessions` at the upstream root. The path is absent when no assignment exists. Creating or removing the HTTP Process must republish workload Caddy so the handle appears and disappears with the assignment.
- Orbit must project `AGENTATION_URL` as `https://<route-domain>/__orbit/agentation`. The stored App instance value is `https://{{app_instance.domain}}/__orbit/agentation`. Systemd units on that App instance receive the concrete URL and `ORBIT_AGENTATION_PORT` when the assignment exists.
- The HTTP Process command is `/usr/local/bin/agentation-mcp server --port=${ORBIT_AGENTATION_PORT}`. The watcher command is `/usr/local/bin/agy --dangerously-skip-permissions -p` with the Agentation hands-free watch prompt. The watcher restart policy is `always` so a finished prompt re-enters watch. The HTTP Process keeps `on-failure`.
- Hibernation must treat both Processes as ordinary desired-running App instance Processes. They stop on idle halt and start on HTTP wake. Wake readiness for `agentation-mcp` must wait for the owned `/health` endpoint on the assigned loopback port.
- Orbit must not install the toolbar, edit application frontend code, or start a fleet-wide Agentation or Antigravity service.

## Rejected alternatives

- A Gateway-owned sidecar outside Process: rejected because start, stop, Doctor, and hibernation would invent a second runtime model. [ADR 0069](/decisions/0069-allow-node-process-targets) already uses Process as the runtime owner.
- Keep-alive for the watcher: rejected because a sleeping App instance has no toolbar traffic, and keep-alive would leave a token-consuming agent running after idle halt.
- Publishing Agentation on a custom proxy hostname: rejected because the toolbar talks to the App's public Route. [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes) is for Node-local services without an App instance.
- Reusing the Vite port and `/__orbit/vite` path: rejected because Vite and Agentation are different upstream contracts. Vite preserves its base path; Agentation strips the reserved prefix.
- Inferring preset identity from a Process name such as `agentation-mcp`: rejected because [ADR 0078](/decisions/0078-assign-vite-ports-to-development-appinstances) already requires an explicit preset.

## Consequences

- Operators create Agentation with the same `process:create --instance --preset` surface as VitePlus.
- `env:sync` writes the concrete `AGENTATION_URL` into the workload file from the stored placeholder. Process units also receive the concrete URL, so the watcher does not depend on a prior sync.
- Existing development Caddy sites gain a second reserved handle only after the HTTP Process exists.
- Hosts must provide `/usr/local/bin/agentation-mcp` and `/usr/local/bin/agy`. Orbit does not install those binaries in this decision.
- The toolbar remains an application concern. This decision only publishes the HTTP companion, the Route path, the projected URL, and the hibernate-tied watcher.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin), [ADR 0069](/decisions/0069-allow-node-process-targets), [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes), and [ADR 0078](/decisions/0078-assign-vite-ports-to-development-appinstances)
- Detail: [Agentation](/reference/agentation)
- Verify: Gateway Process preset, `AGENTATION_URL` projection, Caddy reserved-path, and hibernation start/stop tests; CLI preset contract tests; `composer docs-lint`
