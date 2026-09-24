---
title: "Service metrics"
description: "Caddy proxy traffic and dedicated production PHP-FPM capacity monitoring."
---

# Service metrics

The [Metrics role](/reference/metrics) collects native Caddy metrics on selected ingress nodes and Cbox FPM Exporter metrics on selected app-prod nodes. [ADR 0099](/decisions/0099-collect-role-specific-service-metrics) records the design.

## Selection

The existing node exporter preference controls service monitoring too. No extra enable command is required. Monitoring requires an active Metrics role and an eligible selected node.

| Selected node | Added monitoring |
| --- | --- |
| Ingress with a published public Route | Native Caddy metrics |
| App-prod with dedicated PHP Instances | One Cbox FPM Exporter service with explicit pools |
| Both roles | Both collectors |
| Neither, or no eligible workloads yet | Existing node exporter and cAdvisor only |

An empty ingress node needs no metrics listener until its first public Route is published. An app-prod node without eligible PHP instances has no FPM scrape target. Legacy shared pools are excluded; enabling metrics does not convert them. Their absence from the PHP dashboard does not establish their health.

## Caddy traffic

The **Orbit Caddy Traffic** dashboard shows request rate, 5xx responses, request-duration p95, time-to-first-byte p95, and requests in flight. The underlying histograms also support other percentiles and payload measurements.

The dashboard selects Caddy's outer `subroute` handler, as observed with Orbit's site configuration on Caddy 2.6.2 and 2.11.4. It includes direct and proxied responses without adding the nested handler observations. These are requests seen by the selected Caddy site, not deduplicated requests across the entire fleet. Private traffic to a shared site can also be included.

Every Node that runs Caddy collects per-host metrics, because Orbit's [Caddy global options](/reference/caddy-configuration#what-a-build-contains) turn them on. The service metrics site source adds only the WireGuard scrape site on a selected Ingress Node. Prometheus retains published public host labels and metrics without a host label. Per-host collection applies to the shared Caddy process, so the Prometheus host filter bounds stored series, not Caddy's own in-memory series.

Ingress normally proxies to the Router. Its upstream-health metrics do not establish the health of each Instance. Traffic that never reaches Caddy requires an external probe.

## PHP capacity

Cbox FPM Exporter **v3.1.1** is pinned by architecture and SHA-256. Orbit supplies recorded sockets, configuration paths, and matching PHP binaries. Filesystem discovery, CLI PHP monitoring, and Laravel collectors are disabled.

The **Orbit PHP Capacity** dashboard groups pool data by node and Instance. It shows observed pool health, active workers, effective worker capacity, waiting requests, worker-limit events, slow requests when a threshold exists, and OPcache memory, hit rate, and manual resets.

Other collected pool metrics include idle and total workers, accepted connections, queue capacity, effective timeout settings, and OPcache free memory, waste, misses, and restart causes. Detailed PID-labelled worker series are dropped before storage. Cbox's average worker memory metric describes last-request PHP memory; it is not resident process memory and must not be used as RSS for automatic sizing.

### Runtime behavior

Dedicated production defaults do not configure a slowlog threshold. A zero slow-request counter alone is not evidence that requests are fast. The dashboard only shows slow-request rates when the effective threshold is positive.

Each production master already has its own OPcache. Deployment and rollback retain their existing verified cache reset through that master's application socket. Monitoring never resets the cache. Enabling or removing status instrumentation gracefully reloads only a changed, running master; that reload can warm its cache again. Stopped masters stay stopped and `local.conf` remains unchanged.

FPM status uses a separate local socket, so saturated application workers do not block status collection. Cbox's OPcache helpers run through that socket, outside the web root. Existing operator status directives that conflict with monitoring cause convergence to fail instead of being overwritten.

## Private access and missing data

Caddy listens on WireGuard port **9103**, exposing its metrics handler rather than its administration API. Cbox listens on WireGuard port **9114**. Owned firewall allow/deny pairs precede broader member rules: only the current Metrics node may connect. Caddy also checks the scraper's source address.

Service jobs use a 15-second interval, a 12-second Prometheus timeout, and a 20,000-sample limit. Cbox has a 10-second collection deadline and 3-second pool timeout. Existing node exporter and cAdvisor intervals stay unchanged.

Prometheus `up` describes the exporter endpoint. `phpfpm_up` describes pools Cbox observed. In v3.1.1, one unavailable pool can disappear while the other pools still report healthy; there is no reliable per-pool zero for that case. Check the expected Instance inventory when a series disappears. If every pool is unavailable, Cbox emits an aggregate failure series.

Cbox can emit zero OPcache fields after a failed helper probe. Dashboard cache panels require `phpfpm_opcache_enabled == 1`, so failed or disabled-cache observations leave gaps rather than showing an empty cache. This does not distinguish a disabled cache from a failed probe. No notification delivery is added.

## Lifecycle and recovery

Role and preference changes reconcile monitoring before publishing the new Prometheus configuration. Production clone completion, retained production creation, instance removal, and Route publication, update, or removal refresh the projection. Removing Metrics or disabling a node removes its owned listeners, rules, exporter unit, binary, and pool status directives, while retaining application runtimes and local tuning. The small owned recovery directory may remain.

Changing the Metrics node replaces its scrape access. When a node is unreachable, removal attempts cleanup and drops its targets even if cleanup fails. Orbit retains its existing reports of degraded nodes; this feature does not add per-service fields to `metrics status`.

### Recovery and inspection

FPM updates validate a candidate and recover the prior pool file on reload failure. Caddy changes go through the [Node Caddy build](/reference/caddy-configuration), which validates the whole file and restores the previous version when Caddy fails to reload. Service metrics does not read or restore Caddy files on the Node; after a failed update it restores its stored state and requests another build.

Exporter and firewall updates retain a recovery journal; retry recovers an interrupted update. A failed fleet convergence restores touched service snapshots in reverse order, including when Prometheus publication fails. Ownership conflicts stop mutation, and recovery failures remain explicit errors.

Doctor's firewall expectations include the service allow and deny rules. Production runtime inspection expects the generated status directives when monitoring is selected. These checks do not replace scrape-health checks.

The exporter restarts when its pool configuration changes. Runtime configuration fingerprints also refresh Cbox's cached effective limits on the next reconciliation after local tuning changes. A manual tuning edit without reconciliation can leave those reported limits stale.

## Later tuning

FPM Tune remains deferred. Begin with recommendations based on measured traffic and process memory. Each dedicated master needs its own memory allowance or cgroup budget, leaving room for OPcache and other services. Several independent tuners must not each allocate against the whole node.

Automatic changes require separate enablement and an integration for FPM Tune's drop-in files. Laravel metrics also remain a separate opt-in extension because their collectors execute application commands.

Upstream references: [Caddy metrics](https://caddyserver.com/docs/metrics), [Cbox FPM Exporter](https://github.com/cboxdk/fpm-exporter), and [FPM Tune multi-master operation](https://github.com/cboxdk/fpm-tune/blob/main/docs/cookbook/two-php-versions.md).
