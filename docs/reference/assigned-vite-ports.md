---
title: "Assigned Vite ports"
description: "How Orbit gives each development Instance its own Vite port, and how the vp-dev Process preset connects that port to systemd, Caddy, and wake."
covers:
  - apps/gateway/app/Domain/AppDev/{VitePortAllocator,VitePortRuntime,ViteProcessLifecycle,DevelopmentServerEndpoint}.php
  - apps/gateway/app/Infrastructure/AppDev/RemoteVitePortRuntime.php
  - apps/gateway/app/Domain/Processes/VpDevPreset.php
  - apps/gateway/database/migrations/2026_09_16_000000_add_vite_port_assignments.php
---

# Assigned Vite ports

Several development Instances can run Vite on one Node. Orbit gives each development Instance its own Vite port on its Node. The `vp-dev` Process preset runs Vite on that port and connects it to Caddy and to [wake](/reference/app-dev-runtime-hibernation). Browsers never see the port: they load assets through the Route's HTTPS origin.

## Assignment

Orbit assigns `vite_port` when it creates or registers a development Instance. Production Instances get none. An assignment creates or starts no Process.

The search starts at `5173`, or at the recorded port, and moves up through unprivileged TCP ports. It skips:

- ports that other Instances on the Node hold,
- ports that are bound on the Node, over IPv4 or IPv6,
- common service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`.

Two Nodes can use the same port. The database keeps each port unique per Node. The search runs under the Node's operation lock and fails when no port is left.

`instance:list`, `instance:show`, the API, and the SDK return `vite_port`. The assignment is a stored preference, not an open socket. It survives hibernation, dependency pruning, Process replacement, and reboots. Removal releases it after runtime cleanup. A [transfer](/reference/appinstance-transfer) assigns a port on the destination and releases the source port after cleanup.

## The vp-dev preset

Create the preset Process on a development Instance. `--instance` accepts an Instance ID or its exact Route domain.

```bash
orbit process:create vite --instance=commander.test --preset=vp-dev --start
```

The preset needs `/usr/local/bin/vp`, a readable `package.json`, and an installed `node_modules`. It installs no dependencies and edits no application code. It sets the command, the working directory, and restart on failure. A custom command, runtime, or Docker option conflicts with the preset and is refused. An Instance has at most one preset Process. Naming a plain Process `vp-dev` has no effect. The [Processes](/reference/app-processes-and-schedules#presets) page lists every preset.

The preset runs Vite on loopback with strict binding:

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-<id>.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Orbit writes `ORBIT_DEV_SERVER_PORT` to the environment file before each start. Systemd reads it at start, so a new port needs no unit change. The removed certificate variables keep Vite on plain HTTP; Caddy terminates TLS.

## Start, restart, and wake

Every start of the preset runs one preparation step:

1. A start does nothing when the preset's own Vite already answers on the port.
2. Orbit checks the port again. Another listener on the port makes Orbit pick a new port. The other listener keeps running.
3. It writes the environment file and points the workload Caddy's `/__orbit/vite` path at the port.
4. It starts Vite and waits until Vite answers the client request on that port.

When a port is taken between the check and the bind, Orbit retries with a new port. It makes at most three attempts within the deadline. Another startup error does not change the port. When Vite does not become ready, the start fails with `vite.not_ready` and wake stays incomplete.

## Application setup

The workload Caddy proxies `https://<domain>/__orbit/vite/` to the port and keeps the `/__orbit/vite/` prefix. Every development Process gets these variables:

| Variable | Value |
| --- | --- |
| `ORBIT_DEV_SERVER_ORIGIN` | `https://<domain>/__orbit/vite` |
| `ORBIT_DEV_SERVER_HOST` | The Route domain. |
| `ORBIT_DEV_SERVER_PATH` | `/__orbit/vite` |
| `ORBIT_DEV_SERVER_PORT` | The assigned port. |

Set Vite's `server.origin` to the origin of `ORBIT_DEV_SERVER_ORIGIN` and `server.hmr.path` to `hmr`. Configure assets and HMR for the Route origin. Laravel's plugin writes the public URL into `public/hot`, but Orbit does not read that file. Plain Vite applications use the same setup.

Let Orbit pick the port. Do not select one by hand, and do not add `keep_alive` only to make the Process start on wake: `--start` already does that.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A stored port, not discovery

Vite can pick another port by itself, but Orbit would then have to find out which one. `public/hot` exists only for Laravel, and behind the proxy it holds the public URL, not the local port. A discovery plugin would add a dependency to every application. So Orbit stores the port and runs Vite with `--strictPort`.

### Unique per Node

Ports only collide on one Node. A Cluster-wide rule would waste ports.

### No socket held while asleep

The stored assignment already keeps other Instances off the port. A process outside Orbit can still take it, and strict binding plus a new check at start handle that case.

### An explicit preset

Orbit does not guess from a Process name or command that it runs Vite. The stored preset connects start, restart, wake, and transfer to port preparation.
