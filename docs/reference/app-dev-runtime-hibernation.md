# App-dev runtime hibernation

This page tells an operator how Orbit stops idle development App instance Processes and starts them again on the next HTTP request. [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes) owns the idle-halt boundary. [App processes and schedules](/reference/app-processes-and-schedules) owns Process add, start, stop, and removal. [Schedules](/reference/schedules) owns timer execution.

## Who hibernates

The Gateway applies idle halt to Processes that meet every condition below.

| Condition | Result |
| --- | --- |
| Owner | An App instance. |
| Environment | `development`. |
| Node role | The App instance Node has an active `app-dev` role. |
| Desired state | `running`. The Gateway leaves desired-stopped Processes stopped. |
| Restart policy | Any value. Restart policy does not exempt a Process. |
| Keep-alive | `false`. A Process with `keep_alive=true` stays running through idle halt. |

The Gateway does not halt Node Processes, production App instance Processes, or Schedules. Hibernation never stops, disables, or rewrites a systemd timer. It does not stop the shared per-version PHP FastCGI Process Manager (PHP-FPM) service or its on-demand pools, and it does not rewrite pool files or Caddy FastCGI socket paths. Caddy keeps the published per-site socket after a wake.

## Keep-alive

An operator opts a Process out of idle halt with the boolean `keep_alive` field on that Process or process definition. Restart policy remains crash recovery only and never implies keep-alive.

A keep-alive Process stays running when the Gateway hibernates the App instance. The Gateway still starts every desired-running Process on wake, including a keep-alive Process that is down. An already running keep-alive Process stays up. An operator `process:stop` records `desired_state=stopped`, and wake leaves that Process stopped.

Keep-alive has no effect on Node Processes or production App instance Processes because those targets sit outside hibernation.

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

The Gateway reads last HTTP activity from the more recent of the App instance Caddy access log and the awake marker on the workload Node. The default idle window is 3,600 seconds. The Gateway hibernator runs every 10 minutes on the Gateway host as `orbit-runtime-hibernator.timer`.

| Setting | Default | Config key |
| --- | --- | --- |
| Idle window | 3,600 seconds | `orbit.hibernation.idle_seconds` |
| Sweep interval | 600 seconds | `orbit.hibernation.sweep_seconds` |
| Wake timeout | 60 seconds | `orbit.hibernation.wake_timeout_seconds` |

A sweep that finds no recent HTTP activity stops each desired-running App instance Process that is not keep-alive without changing `desired_state`, then removes the awake marker. Keep-alive Processes stay running. When every desired-running Process is keep-alive, the Gateway does not mark the App instance asleep. Schedules on that App instance keep their timer state.

## Wake

Caddy on the App instance Node looks for `app-instance-{id}.awake` under `/dev/shm/orbit/hibernation` with a file matcher that names that directory as its root. A matching request enters a `handle` that runs before the Vite and application handles. When the marker is absent, that handle calls `GET /api/v1/runtime-activations/app-instance/{id}` on `https://gateway.orbit` over WireGuard. The transport trusts the Orbit root CA already published as `/usr/local/share/ca-certificates/orbit-managed-root-ca.crt` with `tls_trusted_ca_certs`, a Caddy 2.6 directive. Caddy 2.6 is the fleet floor for the Ubuntu `caddy` package Orbit installs. A Node may run a newer Caddy.

The Gateway accepts that call only from the App instance's Node. It returns an HTML progress page with status 401 and a two-second refresh, then starts the desired-running App instance Processes after that response. Caddy returns that non-2xx page to the client and does not proxy the site.

When a Vite Process is present, the Gateway waits until `127.0.0.1:5173` accepts a connection. It writes the awake marker only after every desired-running Process is running. A keep-alive Process that is already running is already ready. The next browser refresh finds the marker and Caddy proxies that request, so the first application request already has Vite and its peers.

A concurrent wake receives the same progress page. A failed start stores the error. The next intercept returns an HTML failure page with status 503 and a five-second retry, then starts again.

After host reboot the tmpfs awake markers are gone. App-dev App instance Process units are not enabled for boot, so the first HTTP request wakes the desired-running group.

## On-demand Process start

A systemd Process for an App instance on an app-dev Node uses `systemctl start`, never `systemctl enable`. The `process:start` command records `desired_state=running`; `process:stop` records `desired_state=stopped`. Idle halt stops the runtime without changing the desired state.

A Docker App instance Process on app-dev maps restart policy `always` to Docker `unless-stopped` so an explicit idle stop survives a Docker daemon restart.

## Inspect

Doctor compares desired Process state with the observed systemd or Docker status. When the awake marker is absent, Doctor does not report a state mismatch for a non-keep-alive Process that is desired running and observed stopped. A keep-alive Process that is desired running and down remains a state mismatch. Doctor does not start or stop the Processes.

`orbit process:list --instance=ID` shows the same desired and observed states, including `keep_alive`.
