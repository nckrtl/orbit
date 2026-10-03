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

The `annotator` Process uses a separate stored `annotator_port` and shares the Agentation allocator. Its reservation persists through pending proxy withdrawal and source retirement on transfer. Environment projection changes only existing unit files and does not activate a sleeping Vite Process. It does not change Vite's reservation and retry behavior. See [Annotator Process](/reference/agentation#annotator-process).

## Assignment

Orbit assigns `vite_port` when it creates or registers a development Instance. Production Instances get none. An assignment creates or starts no Process. Caddy, wake, and the `vp-dev` preset use only that assigned port.

When this Instance already has a recorded port on the Node, the search starts there. When it has no recorded port, the search starts at `5173` and moves up to `65535`. It never tries a port below its start. It skips:

- ports that other Instances on the Node hold,
- ports that any TCP socket on the Node uses, over IPv4 or IPv6, except in `TIME_WAIT`,
- common service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`.

Two Nodes can use the same port. The database keeps each port unique per Node. The search runs under the Node's operation lock and fails when no port is left.

`instance:list`, `instance:show`, the API, and the SDK return `vite_port`. The assignment is a stored preference, not an open socket. It survives hibernation, dependency pruning, Process replacement, and reboots. Removal releases it after runtime cleanup. A [transfer](/reference/instance-transfer) assigns a port on the destination and releases the source port after cleanup.

## The vp-dev preset

Create the preset Process on a development Instance. `--instance` accepts an Instance ID or its exact Route domain.

```bash
orbit process:create vite --instance=commander.test --preset=vp-dev --start
```

The preset needs `/usr/local/bin/vp`, a readable `package.json`, and an installed `node_modules`. It installs no dependencies and edits no application code. It sets the command, the working directory, and restart on failure. A custom command, runtime, or Docker option conflicts with the preset and is refused. An Instance has at most one preset Process. Naming a plain Process `vp-dev` has no effect. The [Processes](/reference/processes-and-schedules#presets) page lists every preset.

For a Laravel Instance, preparation checks `package.json` and `node_modules` in its [application directory](/reference/projects#application-directory), and the Process runs from that same directory. With root `server/web/public`, both prerequisites live in `<checkout>/server/web`, and Laravel's Vite plugin writes `<checkout>/server/web/public/hot`. Root `public` keeps these paths at the checkout root. Non-Laravel Instances keep checkout-root prerequisite checks and working directories. Orbit does not install missing dependencies during preparation.

The preset runs Vite on loopback with strict binding:

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-<id>.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Orbit writes `ORBIT_DEV_SERVER_PORT` to the environment file before each start. Systemd reads it at start, so a new port needs no unit change. The removed certificate variables keep Vite on plain HTTP; Caddy terminates TLS.

## Start, restart, and wake

Every start of the preset runs one preparation step:

1. If the preset's Vite already answers on the port, start returns without restarting it.
2. This also applies to automatic starts during wake, transfer restore, and activation. Orbit does not suspend traffic or re-project Caddy for a healthy endpoint.
3. An explicit start marks the Instance awake only after Vite is ready.
4. An automatic start that must prepare Vite suspends traffic and may clear the awake marker.
5. Orbit checks the port again. Another listener on the port makes Orbit pick a new port. The other listener keeps running.
6. It writes the environment file and points the workload Caddy's `/__orbit/vite` path at the port.
7. It starts Vite and waits until Vite answers the client request on that port.

When a port is taken between the check and the bind, Orbit retries with a new port. It makes at most three attempts within the deadline. Another startup error does not change the port. After `process:restart` of the preset, the next HTTP request goes through the wake page. An explicit `process:start` marks the site awake after readiness. An automatic start preserves the awake marker only when its owned endpoint is already ready; a start that must prepare Vite suspends traffic and may clear the marker. When Vite does not become ready, the start fails with `vite.not_ready`.

## Application setup

The workload Caddy proxies `https://<domain>/__orbit/vite/` to the port and keeps the `/__orbit/vite/` prefix. Every Process of a development Instance with a Route gets these variables. Instances without a Route get none:

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

The `vp-dev` Process preset uses the assigned Vite port for a development Instance and does not apply to Node or Project targets.
