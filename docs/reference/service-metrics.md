---
title: "Service metrics"
description: "Caddy proxy traffic and dedicated production PHP-FPM capacity monitoring."
covers:
  - apps/gateway/app/Infrastructure/Metrics/ServiceMetrics*.php
  - apps/gateway/app/Infrastructure/Metrics/NativeServiceMetrics*.php
  - apps/gateway/app/Infrastructure/Metrics/PrometheusConfigRenderer.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/ServiceMetricsCaddySiteSource.php
  - apps/gateway/app/Infrastructure/Instances/ProductionPhpRuntimeConfigRenderer.php
  - apps/gateway/resources/scripts/service-metrics*.py
---

# Service metrics

The [Metrics role](/reference/metrics) also collects service metrics. It scrapes Caddy's own metrics on selected `ingress` Nodes. It runs Cbox FPM Exporter on selected `app-prod` Nodes. Dashboard generation uses the same service-metrics projection as collection and selection.

## Selection

The node exporter preference also controls service metrics. No other command enables them. They need an active Metrics role and a selected Node.

| Selected Node | Added monitoring |
| --- | --- |
| `ingress` with an active public Route | Caddy metrics |
| `app-prod` | One Cbox FPM Exporter service, with one pool for each production Instance that has its own PHP-FPM master |
| Both roles | Both |
| Neither role | Only the node exporter and cAdvisor |

An `ingress` Node gets a metrics listener with its first active public Route. An `app-prod` Node without such Instances has no FPM scrape target.

## Caddy traffic

The **Orbit Caddy Traffic** dashboard shows scrape health, request rate, 5xx responses, request-duration p95, time-to-first-byte p95, and requests in flight. The histograms also support other percentiles and payload sizes.

The dashboard reads Caddy's outer `subroute` handler, which Orbit's site configuration produces. It counts direct and proxied responses once. It counts requests that the selected Caddy site saw, not unique requests across the fleet. Private traffic to a shared site can also count.

Every Node that runs Caddy collects per-host metrics, because Orbit's [Caddy global options](/reference/caddy-configuration#published-layout) turn them on. Service metrics adds only a WireGuard scrape site on a selected `ingress` Node. Prometheus keeps `caddy_http_*` series for active public hosts and series without a host label. It also keeps `caddy_reverse_proxy_upstreams_healthy`. This filter limits stored series, not Caddy's own series in memory.

An `ingress` Node normally proxies to the Router. So its upstream health does not show the health of each Instance. Traffic that never reaches Caddy needs an external probe.

## PHP capacity

Orbit pins Cbox FPM Exporter `v3.1.1` for each architecture and checks its SHA-256 checksum. Orbit gives Cbox the recorded sockets, configuration paths, and PHP binaries. Pool discovery, CLI PHP monitoring, and Laravel collectors are off.

The **Orbit PHP Capacity** dashboard groups pools by Node and Instance. It shows pool health, active workers, `pm.max_children`, waiting requests, worker-limit events, slow requests, and OPcache memory, hit rate, and manual resets. Prometheus drops the per-worker `phpfpm_process_*` series before storage.

Cbox's average worker memory metric is the PHP memory of the last request. It is not resident process memory. Do not use it as RSS to size workers.

### Runtime behavior

Dedicated production pools have no slowlog threshold by default. So a zero slow-request counter does not prove that requests are fast. The dashboard shows slow-request rates only when the threshold is positive.

Each production master has its own OPcache. Deployment and rollback reset that cache through the master's application socket. Monitoring never resets the cache.

Status monitoring uses a separate local socket, `<socket>.status`. So busy application workers do not block status collection. Cbox's OPcache helpers run through that socket, outside the web root.

The Gateway renders the PHP-FPM status lines into each production Instance's `pool.conf` and sends that configuration to the Node. The Node-side metrics script does not edit `pool.conf`. When monitoring adds or removes its status lines, Orbit reloads only a changed master that runs. The reload can warm its cache again. A stopped master stays stopped. Orbit never changes `local.conf`. When an operator's own status directive conflicts with monitoring, convergence fails and keeps the directive.

## Private access

Caddy serves its metrics handler on WireGuard port 9103. It does not expose the administration API. Cbox listens on WireGuard port 9114. An owned allow and deny rule pair comes before the broader member rules, so only the current Metrics Node can connect. Caddy also checks the scraper's source address.

Service jobs use a 15-second interval, a 12-second timeout, and a limit of 20,000 samples. Cbox has a 10-second collection deadline and a 3-second pool timeout. The node exporter and cAdvisor keep their 10-second interval.

## Missing data

Prometheus `up` describes the exporter endpoint. `phpfpm_up` describes the pools that Cbox saw. In `v3.1.1`, one unavailable pool can vanish while the other pools still report healthy. So compare the series with the expected Instances when a series is missing. When every pool is unavailable, Cbox emits an aggregate failure series.

Cbox can emit zero OPcache fields after a failed probe. The cache panels require `phpfpm_opcache_enabled == 1`, so a failed probe or a disabled cache leaves a gap instead of an empty cache. The panels cannot tell these two cases apart. Orbit sends no alerts.

## Lifecycle

Role and preference changes converge the services first, and then publish the new Prometheus configuration. Service metrics also reconcile across the fleet whenever a public site appears or disappears. This includes:

- a Route is created, updated, or removed;
- a Route target is set or cleared; and
- a Route is published as public.

Production clone completion and production Instance creation or removal also reconcile service metrics.

If a Node's service-metrics snapshot or convergence fails, the Gateway records that Node as degraded with the failing step and error code. It skips that Node and continues reconciling the others. The operation that triggered the reconcile, such as an Instance removal on another Node, continues. A later reconcile retries the Node and clears its degradation when it succeeds. Inspect the affected Node's exporter row in `metrics:status` as described under [Exporter degradation](/reference/metrics#exporter-degradation).

Removing Metrics, or disabling a Node's exporter, removes the owned listeners, firewall rules, exporter unit, binary, and pool status lines. It keeps the application runtimes and local tuning. The small recovery directory `/etc/orbit/service-metrics` stays.

When the Metrics Node changes, the scrape access moves to the new Node. When a Node is unreachable, removal tries to clean up and drops its targets even if cleanup fails. `metrics:status` has no per-service fields.

### Recovery

FPM updates validate a candidate file. When the reload fails, Orbit restores the earlier pool file. The scrape site renders from stored state in the [Node Caddy build](/reference/caddy-configuration#node-caddy-build), which owns validation and rollback. Service metrics never reads or writes Caddy files on a Node. It asks for a build of each `ingress` Node. When its own lifecycle fails, it restores the exporter and pool state and builds that Node again.

Exporter and firewall updates keep a recovery journal, and a retry recovers an interrupted update. When convergence fails on one Node, Orbit tries to restore that Node's exporter and pool state before continuing on the other Nodes. If that recovery fails, the Node's degradation records the `restore` step and `metrics.service_rollback_failed`. An ownership conflict degrades the affected Node without changing unmanaged state.

A Prometheus publication failure still fails the reconcile and restores the changed services in reverse order. If that recovery fails, the reconcile returns `metrics.service_rollback_failed`. A service failure stays recorded until that Node's service reconciliation and publication succeed; successful exporter or cAdvisor snapshots do not clear it. Recording and clearing its step and error code are atomic.

If the operation loses a Node lock, reconciliation stops and reports `node.lock_lost`. This failure affects the whole operation, not only service metrics on one Node. Orbit stops further remote work, including recovery, after detecting the lost lock.

### Doctor

Doctor's firewall family expects the service allow and deny rules. Doctor's production Instance checks expect the status lines when monitoring is selected. These checks do not replace scrape health.

The exporter restarts when its pool configuration changes. A fingerprint of the PHP configuration also refreshes Cbox's limits at the next reconcile after a tuning change. A manual tuning edit without a reconcile can leave those limits stale.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Caddy's own metrics

Caddy already exports the metrics that Orbit needs. A separate Caddy exporter would add a service and no data.

### One Cbox service per Node

Cbox reads several named pools from one service. One exporter per Instance would multiply services and listeners.

### Named pools instead of discovery

Discovery cannot prove that Orbit owns a pool, or bind a pool to an Instance. So Orbit names each pool from its records.

### One master per production Instance

A shared master would reduce monitoring cost. It would also break independent deployment and cache reset for each Instance.

### No automatic tuning

Observing a service and changing its capacity have different effects. So Metrics enablement never tunes PHP-FPM, resets caches, or runs application commands. FPM Tune and Laravel metrics need their own opt-in.
