---
title: "App-dev runtime hibernation"
description: "How Orbit stops idle development Instance Processes and wakes them on the next HTTP request."
---

# App-dev runtime hibernation

This page tells an operator how Orbit stops idle development Instance Processes and starts them again on the next HTTP request. After a longer idle window it also deletes reconstructable checkout dependencies and restores them before those Processes start. [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes) owns the idle-halt boundary. [ADR 0075](/decisions/0075-prune-idle-app-dev-checkout-dependencies) owns the cold dependency tier. [Project processes and schedules](/reference/app-processes-and-schedules) owns Process add, start, stop, and removal. [Schedules](/reference/schedules) owns timer execution.

## Who hibernates

The Gateway applies idle halt to Processes that meet every condition below.

| Condition | Result |
| --- | --- |
| Owner | An Instance. |
| Environment | `development`. |
| Node role | The Instance Node has an active `app-dev` role. |
| Desired state | `running`. The Gateway leaves desired-stopped Processes stopped. |
| Restart policy | Any value. Restart policy does not exempt a Process. |
| Keep-alive | `false`. A Process with `keep_alive=true` stays running through idle halt. |

The Gateway does not halt Node Processes, production Instance Processes, or Schedules. Hibernation never stops, disables, or rewrites a systemd timer. It does not stop the shared per-version PHP FastCGI Process Manager (PHP-FPM) service or its on-demand pools, and it does not rewrite pool files or Caddy FastCGI socket paths. Caddy keeps the published per-site socket after a wake.

## Keep-alive

An operator opts a Process out of idle halt with the boolean `keep_alive` field on that Process or process definition. Restart policy remains crash recovery only and never implies keep-alive.

A keep-alive Process stays running when the Gateway hibernates the Instance. The Gateway still starts every desired-running Process on wake, including a keep-alive Process that is down. An already running keep-alive Process stays up. An operator `process:stop` records `desired_state=stopped`, and wake leaves that Process stopped.

Keep-alive has no effect on Node Processes or production Instance Processes because those targets sit outside hibernation.

A queue worker that must drain jobs while Vite sleeps is recorded like this:

```bash
orbit process:create queue \
  --instance=12 \
  --runtime=systemd \
  --command=/usr/bin/php \
  --command=artisan \
  --command=queue:work \
  --restart=on-failure \
  --keep-alive \
  --start
```

## Idle window and sweep

The Gateway reads last HTTP activity from the more recent of the Instance Caddy access log and the awake marker on the workload Node. A probe request is absent from that log by design, so measuring an Instance never extends its idle window. The default idle window is 3,600 seconds. The Gateway hibernator runs every 10 minutes on the Gateway host as `orbit-runtime-hibernator.timer`. The same sweep also evaluates the cold dependency window.

| Setting | Default | Config key |
| --- | --- | --- |
| Idle window | 3,600 seconds | `orbit.hibernation.idle_seconds` |
| Dependency idle window | 604,800 seconds | `orbit.hibernation.dependency_idle_seconds` |
| Sweep interval | 600 seconds | `orbit.hibernation.sweep_seconds` |
| Wake timeout | 60 seconds | `orbit.hibernation.wake_timeout_seconds` |
| Cold wake timeout | 1,800 seconds | `orbit.hibernation.cold_wake_timeout_seconds` |

A sweep that finds no recent HTTP activity stops each desired-running Instance Process that is not keep-alive without changing `desired_state`, then removes the awake marker. Keep-alive Processes stay running. When every desired-running Process is keep-alive, the Gateway does not mark the Instance asleep. Schedules on that Instance keep their timer state.

A later pass in that sweep deletes reconstructable `vendor` and `node_modules` directories when every condition below is true.

| Condition | Result |
| --- | --- |
| Already hibernated | The awake marker is absent. |
| Not already cold | The durable cold marker is absent. |
| HTTP idle | Last HTTP activity is older than the dependency idle window. |
| Process lifecycle idle | No Instance Process row changed inside that window. |
| Source tree quiet | The newest checkout file outside `vendor`, `node_modules`, and `.git` is older than that window. |
| Reconstructable trees present | `vendor` has `composer.json` and `composer.lock` and is not a symlink, or `node_modules` has `package.json` and exactly one JavaScript lock family and is not a symlink. |
| No keep-alive workers | No desired-running Process has `keep_alive=true`. |

The Gateway leaves lockfiles in the checkout. A keep-alive desired-running Process blocks prune for the whole Instance because that Process can still need those trees.

## Host directories

App-dev Caddy publish and each awake-marker write create the hibernation directories on the Instance Node before Caddy reloads or reads a marker. The `caddy` user must traverse every ancestor, write the access log, and read the awake marker.

| Path | Owner | Mode | Use |
| --- | --- | --- | --- |
| `/dev/shm/orbit` | unchanged | `0755` | Lets `caddy` reach the marker directory. |
| `/dev/shm/orbit/hibernation` | `root:caddy` | `0755` | Holds `app-instance-{id}.awake` markers. |
| `/data/caddy` | unchanged | `0755` | Lets `caddy` reach the log directory. |
| `/data/caddy/orbit` | unchanged | `0755` | Lets `caddy` reach the log directory. |
| `/data/caddy/orbit/hibernation` | `root:caddy` | `2775` | Lets `caddy` write `app-instance-{id}.log` and holds durable `app-instance-{id}.cold` markers. |
| `app-instance-{id}.awake` | `root` | `0644` | Lets `caddy` skip wake when the marker exists. |
| `app-instance-{id}.cold` | `root` | `0644` | Tells the Gateway to restore checkout dependencies before Process start. Caddy does not read this file. |

The publish lock uses `umask 0077`. The Gateway sets each ancestor to `0755` so the `caddy` user can reach the leaf. Aggregate configuration validation runs as `caddy`, the service user, because validation can open access logs. Privileged publication still owns configuration files, certificates, locks, and rollback. Validation does not change existing log ownership or permissions. If an existing log is not writable by `caddy`, publication fails before switching the live configuration. An operator must verify and repair that exact log before retrying; Orbit does not adopt or repair arbitrary log paths.

## Wake

Caddy on the Instance Node looks for `app-instance-{id}.awake` under `/dev/shm/orbit/hibernation` with a file matcher that names that directory as its root. A matching request enters a `handle` that runs before the Vite and application handles. When the marker is absent, that handle calls `GET /api/v1/runtime-activations/app-instance/{id}` on `https://gateway.orbit` over WireGuard. The transport trusts the Orbit root CA already published as `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt` with `tls_trusted_ca_certs`.

A request carrying `X-Orbit-Probe: 1` is exempt from both halves of that mechanism: it never enters the wake handle, so it starts nothing, and `log_skip` keeps it out of the access log, so it never becomes the activity the sweep reads. `log_skip` needs Caddy 2.8 or newer, which is why Orbit installs Caddy from [its own pinned package source](/reference/node-provisioning#package-sources) rather than the Ubuntu archive. [`profile --instance`](/cli/profile) sends that header, which is what lets an operator measure a sleeping Instance without changing whether it sleeps. A request without the header behaves exactly as described above.

The Gateway accepts that call only from the Instance's Node. It returns an HTML progress page with status 401 and a two-second refresh, then starts the desired-running Instance Processes after that response. Caddy returns that non-2xx page to the client and does not proxy the site.

When the durable cold marker is set, the Gateway restores missing reconstructable dependencies before it starts Processes. Composer runs `composer install --no-interaction --prefer-dist`. JavaScript restore runs `vp install --frozen-lockfile` so Vite+ selects the project's package manager. Soft wake without a cold marker starts Processes only. The Gateway uses the cold wake timeout for restore and the ordinary wake timeout for Process readiness.

For a `vp-dev` preset Process, the Gateway prepares the assigned Vite port and waits for its owned service to answer the Vite client request. An unrelated listener cannot satisfy readiness. Legacy instances without an assignment retain their previous port `5173` check.

For an `agentation-mcp` preset Process, the Gateway waits for `/health` on the assigned Agentation loopback port. The `antigravity-watch` preset has no keep-alive, so idle halt stops it and wake starts it with the rest of the desired-running group.

After restore, when needed, and after every desired-running Process is running, the Gateway clears the cold marker and then writes the awake marker. A keep-alive Process that is already running is already ready. The next browser refresh finds the marker and Caddy proxies that request, so the first application request already has Vite and its peers.

A concurrent wake receives the same progress page. A failed restore or start keeps the cold marker and stores the error. The next intercept returns an HTML failure page with status 503 and a five-second retry, then starts again. Soft and cold wakes share those pages.

After host reboot the tmpfs awake markers are gone. App-dev Instance Process units are not enabled for boot, so the first HTTP request wakes the desired-running group.

## On-demand Process start

A systemd Process for an Instance on an app-dev Node uses `systemctl start`, never `systemctl enable`. The `process:start` command records `desired_state=running`; `process:stop` records `desired_state=stopped`. Idle halt stops the runtime without changing the desired state.

A Docker Instance Process on app-dev maps restart policy `always` to Docker `unless-stopped` so an explicit idle stop survives a Docker daemon restart.

## Inspect

Doctor compares desired Process state with the observed systemd or Docker status. When the awake marker is absent, Doctor does not report a state mismatch for a non-keep-alive Process that is desired running and observed stopped. A keep-alive Process that is desired running and down remains a state mismatch. Doctor does not start or stop the Processes.

`orbit process:list --instance=ID` shows the same desired and observed states, including `keep_alive`.
