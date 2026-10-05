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

Several development Instances can run Vite on one Node. Orbit gives each development Instance/app pair its own Vite port on its Node. The `vp-dev` Process preset runs Vite on that port and connects it to Caddy and to [wake](/reference/app-dev-runtime-hibernation). Browsers never see the port: they load assets through the Route's HTTPS origin.

The `annotator` Process uses a separate stored `annotator_port` and shares the Agentation allocator. Its reservation persists through pending proxy withdrawal and source retirement on transfer. Environment projection changes only existing unit files and does not activate a sleeping Vite Process. It does not change Vite's reservation and retry behavior. See [Annotator Process](/reference/agentation#annotator-process).

## Assignment

Orbit assigns `vite_port` for every app when it creates or registers a development Instance, including a non-serving app. Production apps get none. Assignment identity is `(node_id, instance_id, app)`. New app assignments run in app-name order, including apps added to an existing development Instance by a Project update. Existing app ports stay unchanged. An assignment creates or starts no Process. Caddy, wake, and the `vp-dev` preset use only that assigned port.

When this Instance/app pair already has a recorded port on the Node, the search starts there. When it has no recorded port, the search starts at `5173` and moves up to `65535`. It never tries a port below its start. It skips:

- all reservations for other Vite, Agentation and annotator endpoints on the Node, including another kind in the same Instance/app pair,
- retained source-transfer and pending-withdrawal reservations,
- ports that any TCP socket on the Node uses, over IPv4 or IPv6, except in `TIME_WAIT`,
- common service ports: `3306`, `5432`, `5672`, `6379`, `8000`, `8080`, `8443`, `9000`, `9090`, `9200`, `11211`, `15672`, and `27017`.

Two Nodes can use the same port. The database keeps each port unique per Node. The search runs under the Node's operation lock and fails when no port is left.

`instance:list`, `instance:show`, API and MCP return `app_runtime.<app>.vite_port`; SDK `$appRuntime[$app]->vitePort` emits that same key. The assignment is a stored preference, not an open socket. It survives hibernation, dependency pruning, Process replacement, and reboots. Removal releases it after runtime cleanup. A [transfer](/reference/instance-transfer) assigns a port on the destination and releases the source port after cleanup.

## Migrate port reservations

Legacy Vite and annotation allocators did not exclude every endpoint family. Two sleeping endpoints can therefore hold the same Node port even when no listener exists. Migration inventories every active and retained reservation before publishing app-keyed assignments. Identity includes Node, Instance, app name and endpoint kind; all legacy apps resolve to `web`. It holds the Node operation lock and affected Instance operation owners while recording and applying the migration plan. It never treats another endpoint kind in the same Instance/app pair as the same owner.

Retained source-transfer and pending-withdrawal reservations have priority and cannot move while an old proxy may reference them. If two such retained owners share a Node port, migration stops before mutation with `app.port_migration_conflict` (409). Bounded error details identify the Node, Instance IDs, endpoint kinds and owning operation IDs, not store contents. Complete those transfer/withdrawal operations through their existing retry commands, then rerun migration. The error never authorizes releasing a reservation or touching another operation's runtime.

Otherwise the recorded plan preserves retained reservations first, then ordinary Vite, Agentation and annotator assignments in that priority order, sorting each kind by Instance ID and app name. The first owner of a port keeps it; each ordinary conflicting owner gets a new port. Search starts at its family's default (`5173`, `4747` or `4848`) and excludes every old reservation, prepared new reservation, reserved service port and observed TCP listener. No old port is recycled during preparation. Allocation exhaustion keeps the old configuration authoritative and uses the existing family exhaustion code.

The journal records old/new assignments, exact Process IDs, app association, unit/runtime-file snapshots, Caddy projections and observed running/stopped state before mutation. It stops only owned Processes observed running on an affected assignment, stages app-qualified runtime files and units, and verifies the new endpoint projection. It restores those running Processes on the planned ports; stopped, sleeping and failed Processes are not started. A sibling port or store is not adopted. Numeric ports without conflicts are unchanged, including retained transfer/withdrawal state.

One publication transaction installs the app-keyed reservations and migration receipt only after all planned projections succeed. Prepublication failure restores old assignments, units and projections and removes only migration-owned candidates. A failed rollback stays journaled for identical retry. After publication, retry completes cleanup forward without allocating again. Old ordinary reservations are released only after no stored unit or Caddy site references them; retained reservations remain owned by their original operations. A crash or lost response verifies the recorded step and receipt rather than taking a second port.

Port reallocation never changes annotator store paths or queued data. The separate migration of each app's store remains journaled.

## The vp-dev preset

Create the preset Process on a development Instance. `--instance` accepts an Instance ID or its exact Route domain.

```bash
orbit process:create vite --instance=commander.test --preset=vp-dev --start
```

The preset needs `/usr/local/bin/vp`, a readable `package.json`, and an installed `node_modules`. It installs no dependencies and edits no application code. It sets the command, the working directory, and restart on failure. A custom command, runtime, or Docker option conflicts with the preset and is refused. Each Instance/app pair has at most one `vp-dev` Process. A second name for that same app/preset returns existing `process.preset_exists`; another app may have its own preset Process with an owner-wide unique name. Naming a plain Process `vp-dev` has no effect. The [Processes](/reference/processes-and-schedules#presets) page lists every preset.

For every app type, preparation checks `package.json` and `node_modules` in the selected app's effective [application directory](/reference/projects#application-directory), and the Process runs there. With app path `server/web` and web root `public`, Laravel's Vite plugin writes `<checkout>/server/web/public/hot`. App path `.` selects the checkout root. Orbit does not install missing dependencies during preparation. API/MCP creation uses `app`, SDK uses `$app`, and CLI uses `--app`; a Route domain already identifies the app.

The preset runs Vite on loopback with strict binding:

```ini
EnvironmentFile=/etc/orbit/vite/app-instance-<id>-<app>.env
UnsetEnvironment=VITE_DEV_SERVER_CERT VITE_DEV_SERVER_KEY
ExecStart=/usr/local/bin/vp dev --host=127.0.0.1 --port=${ORBIT_DEV_SERVER_PORT} --strictPort --base=/__orbit/vite/
```

Orbit writes `ORBIT_DEV_SERVER_PORT` to the selected app's environment file before each start. The file carries both Instance ID and app name ownership markers; it cannot be adopted or removed by a sibling Process.

Migration associates the recorded port with `web`, stages `app-instance-<id>-web.env`, rewrites the existing Vite unit and verifies it before removing the former Instance-only runtime file. Pending port changes and transfer reservations migrate with the same app. Process removal removes only its app runtime file; the Vite assignment survives Process replacement and is released on app or Instance removal after proxy withdrawal. Systemd reads it at start, so a new port needs no unit change. The removed certificate variables keep Vite on plain HTTP; Caddy terminates TLS.

## Start, restart, and wake

Every start of the preset runs one preparation step:

1. If the preset's Vite already answers on the port, start returns without restarting it.
2. This also applies to automatic starts during wake, transfer restore, and activation. Orbit does not suspend traffic or re-project Caddy for a healthy endpoint.
3. An explicit start marks the Instance awake only after Vite is ready.
4. An automatic start that must prepare Vite suspends traffic and may clear the awake marker.
5. Orbit checks the port again. Another listener on the port makes Orbit pick a new port. The other listener keeps running.
6. It writes the environment file and points the workload Caddy's `/__orbit/vite` path at the port.
7. It starts Vite and waits until Vite answers the client request on that port.

When a port is taken between the check and the bind, Orbit retries with a new port. It makes at most three attempts within the deadline. Another startup error does not change the port.

After `process:restart` of the preset, the next HTTP request goes through the wake page. An explicit `process:start` marks the Instance awake after readiness. The awake marker remains Instance-wide; it is not an app runtime file. Wake prepares all desired-running app presets and only marks the Instance ready after all required endpoints pass. An automatic start preserves the awake marker only when its owned endpoint is already ready; a start that must prepare Vite suspends traffic and may clear the marker. When Vite does not become ready, the start fails with `vite.not_ready`.

## Application setup

The workload Caddy proxies `https://<domain>/__orbit/vite/` to the port and keeps the `/__orbit/vite/` prefix. Every systemd Process of the selected development app gets these variables from that app's Route. An app without a Route gets none:

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
