---
title: "App-dev runtime hibernation"
description: "How Orbit stops the Processes of idle development Instances, prunes their dependencies after a long idle period, and wakes them on the next HTTP request."
covers:
  - apps/gateway/app/{Actions,Domain,Infrastructure}/Hibernation/**
  - apps/gateway/app/Http/Responses/RuntimeActivationPage.php
  - apps/gateway/config/orbit.php
---

# App-dev runtime hibernation

A development Instance often runs Processes, such as Vite, long after anyone uses the site. Orbit stops those Processes after an idle hour. After an idle week, it also deletes the dependency directories that lockfiles can rebuild. The next HTTP request shows a progress page, and Orbit restores and starts everything before it lets the request through. An explicit `process:start` for a Vite development Process marks the Instance awake, including when Vite was already listening; an automatic HTTP wake does not turn that temporary wake into a manual one.

## What hibernates

Hibernation applies to a Process that meets every condition:

| Condition | Value |
| --- | --- |
| Owner | An Instance. |
| Instance | A development Instance on a Node with an active `app-dev` role. |
| Desired state | `running`. Orbit leaves a stopped Process stopped. |
| `keep_alive` | `false`. |

Restart policy does not matter. Hibernation never touches Node Processes, production Instances, Schedules, PHP-FPM services or pools, or Caddy socket paths.

The [Pi server](/reference/pi-server) remains a Node Process when it runs as `orbit-worker`, so hibernation never stops it. `ORBIT_TASKS_WORKER_USER` configures [checkout ACLs](/reference/instance-setup#checkout-access), not the idle window or Process eligibility. Sharing a task checkout with the worker does not change the hibernation settings below.

## Keep a Process running

Set `keep_alive` on a Process or process definition to keep it running while the Instance sleeps. Use it for a worker that must keep draining jobs:

```bash
orbit process:create queue --instance=12 --runtime=systemd \
  --command=/usr/bin/php --command=artisan --command=queue:work \
  --restart=on-failure --keep-alive --start
```

A wake still starts every desired-running Process, including a keep-alive Process that is down. `process:stop` records `desired_state=stopped`, and a wake leaves that Process stopped. Keep-alive has no effect outside hibernation.

## Idle window and sweep

`orbit-runtime-hibernator.timer` runs on the Gateway host every 10 minutes. It reads the last HTTP activity of each Instance: the newer of the Instance's Caddy access log and its awake marker.

Set these values in the Gateway's environment, not an Instance's `.env`. They configure hibernation only; [task-agent effort](/reference/tasks#drivers) uses separate Gateway settings.

| Setting | Default | Config key | Environment key |
| --- | --- | --- | --- |
| Idle window | 3,600 seconds | `orbit.hibernation.idle_seconds` | `ORBIT_HIBERNATION_IDLE_SECONDS` |
| Dependency idle window | 604,800 seconds | `orbit.hibernation.dependency_idle_seconds` | `ORBIT_HIBERNATION_DEPENDENCY_IDLE_SECONDS` |
| Sweep interval | 600 seconds | `orbit.hibernation.sweep_seconds` | `ORBIT_HIBERNATION_SWEEP_SECONDS` |
| Wake timeout | 60 seconds | `orbit.hibernation.wake_timeout_seconds` | `ORBIT_HIBERNATION_WAKE_TIMEOUT_SECONDS` |
| Cold wake timeout | 1,800 seconds | `orbit.hibernation.cold_wake_timeout_seconds` | `ORBIT_HIBERNATION_COLD_WAKE_TIMEOUT_SECONDS` |

After the idle window, the sweep stops each desired-running Process that is not keep-alive. It keeps `desired_state` as it is and removes the awake marker. When the [Node agent view](/reference/node-agent#gateway-view) is fresh and shows a Process already stopped, the sweep skips it. When an Instance has no desired-running Process without keep-alive, the sweep skips it: it never sleeps and is never pruned. An Instance with no recorded HTTP activity counts as idle, so it sleeps at the first sweep.

### Dependency prune

The same sweep deletes `vendor` and `node_modules` when all of these are true:

- The Instance is asleep.
- It is not already marked cold.
- It had no HTTP activity for the dependency idle window.
- No Process row of the Instance changed in that window.
- No checkout file outside `vendor`, `node_modules`, and `.git` changed in that window.
- No desired-running Process has `keep_alive`.

Orbit deletes `vendor` only next to `composer.json` and `composer.lock`. It deletes `node_modules` only next to `package.json` and exactly one JavaScript lockfile. It never follows a symlink and keeps every lockfile. Then it writes the cold marker. The [dependency inventory](/reference/instance-dependencies) stays, because it reads lockfiles.

## Wake

Caddy on the Instance's Node checks for the awake marker on every request. When the marker is missing, Caddy calls `GET /api/v1/runtime-activations/app-instance/{id}` on the Gateway over WireGuard, trusting the Orbit root certificate. The caller must be the Instance's Node, or a Node with an [access grant](/cli/node) to it.

The Gateway answers with a progress page, status 401, headers `X-Orbit-Runtime-Activation-State: pending` and `Retry-After: 1`, and then starts the wake. Caddy shows the page and does not pass the request on. The page polls the original path once a second and loads it when the state header is gone. That load is the first application request.

The wake runs in this order:

1. When the cold marker exists, restore dependencies with `composer install --no-interaction --prefer-dist` and `vp install --frozen-lockfile`. This uses the cold wake timeout.
2. Start every desired-running Process.
3. Wait until each one runs. A `vp-dev` Process must answer on its [assigned Vite port](/reference/assigned-vite-ports). An `agentation-mcp` Process must answer `/health` on its port.
4. Clear the cold marker and write the awake marker.

Any other Process is ready when its runtime reports running.

Orbit checks each Process every 0.5 seconds. A fresh Node agent view answers without SSH. Orbit confirms a `failed` answer, or a timeout, over SSH before the wake fails.

Every intercepted request starts a wake. A request during a running wake gets the same progress page. Its own wake attempt ends quietly when the running wake holds the Process lock. A failed wake keeps the cold marker and stores the error for up to 120 seconds. The next request gets a failure page, status 503, with state `failed`, the error, and a Try again link, and it also starts a new wake. The error shows once. Try again adds `orbit-wake-retry=1` to the path, and its request shows the progress page of the new wake.

### Probe requests

A request with `X-Orbit-Probe: 1` skips the wake and is not logged. [`profile --instance`](/cli/profile) sends it, so you can measure a sleeping Instance without waking it or resetting its idle time. `log_skip` needs Caddy 2.8 or newer, which Orbit installs from its [pinned package source](/reference/node-provisioning#package-sources).

### After a reboot

The awake markers live in `/dev/shm`, so a reboot clears them. Development Process units start with `systemctl start` and are never enabled for boot. The first request after a reboot wakes the Instance.

## Host directories

The `app-dev` role and each marker write create these directories before Caddy reloads or reads a marker.

| Path | Owner | Mode | Use |
| --- | --- | --- | --- |
| `/dev/shm/orbit/hibernation` | `root:caddy` | `0755` | `app-instance-{id}.awake` markers. |
| `/data/caddy/orbit/hibernation` | `root:caddy` | `2775` | `app-instance-{id}.log` access logs and `app-instance-{id}.cold` markers. |

Orbit sets each parent directory to `0755` so the `caddy` user can reach them. The Gateway validates each Caddy build as the Caddy user, so new log files stay writable by the service.

## Docker Processes

A Docker Process of a development Instance maps restart policy `always` to Docker `unless-stopped`. An idle stop then survives a Docker daemon restart.

## Inspect

While the awake marker is missing, [Doctor](/cli/doctor) does not report a desired-running, stopped Process as drift, unless it is keep-alive. `process:list --instance=ID` shows desired and observed states and `keep_alive`.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Restart policy is not keep-alive

A restart policy covers crashes while a unit runs. Treating it as a reason to stay awake was rejected. `keep_alive` is a separate field.

### Schedules keep running

Schedules are independent systemd timers, and they must fire on time. So hibernation never pauses them.

### PHP-FPM stays up

All development sites of one PHP version share one master, and each pool already ends idle workers. Stopping the service or removing a pool would affect every site.

### No start at boot

Enabling development units for boot would start every Instance without a request. So units start only on demand.

### The first response is a page

When the intercept returned 200, Caddy would pass the request to an application whose Processes are not ready. So the intercept returns the progress page, and the reload after the wake is the first application request.

### One page for both tiers

A soft wake and a cold wake show the same progress and failure pages. Separate pages were rejected, because Caddy owns one intercept contract.

### Keep-alive blocks the prune

A keep-alive Process can still need `vendor` or `node_modules`. So Orbit never prunes an Instance with one.
