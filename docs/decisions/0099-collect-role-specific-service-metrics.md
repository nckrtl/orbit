---
title: "ADR 0099: Collect role-specific service metrics"
sidebarTitle: "0099 Collect role-specific service metrics"
description: "Proposed. Extend node metrics selection with native Caddy metrics on ingress nodes and Cbox FPM Exporter on app-prod nodes."
---

# ADR 0099: Collect role-specific service metrics

The existing node metrics preference also selects service metrics for that node's roles. Orbit scrapes Caddy's native metrics on ingress nodes and runs Cbox FPM Exporter on app-prod nodes. Production App instances keep their separate PHP-FPM masters and caches.

## Status

Proposed.

## Context

Node exporter and cAdvisor report machine and process resources. They do not show public request latency, HTTP errors, PHP worker saturation, or OPcache pressure. Caddy supplies a native Prometheus endpoint. Cbox FPM Exporter reads PHP-FPM status over FastCGI and can collect OPcache statistics from the owning runtime.

[ADR 0003](/decisions/0003-singleton-metrics-role) owns Metrics lifecycle and node preferences. [ADR 0057](/decisions/0057-limit-metrics-exporters-to-managed-nodes) restricts exporter management to eligible managed nodes. [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user) already isolates production masters and cache refresh. Service monitoring extends these contracts without creating another enablement preference or changing application capacity.

## Decision

- The Gateway derives service monitoring from the existing exporter selection and the node's applicable roles. A selected ingress node with published public Routes gets Caddy monitoring; a selected app-prod node with dedicated PHP instances gets PHP-FPM monitoring. A node carrying both gets both. Metrics remains responsible for exporter lifecycle, scrape targets, and private access.
- Caddy uses its built-in metrics handler on a dedicated WireGuard listener. Orbit does not install a separate Caddy exporter or expose the administration API. The shared Caddy publisher owns composition, validation, atomic publication, and recovery.
- One Cbox FPM Exporter service per app-prod node reads an explicit configuration derived from recorded dedicated production runtimes. Orbit pins its binary version and verifies its checksum. It does not discover or adopt unrelated pools. PHP runtime management owns the monitoring configuration added to each master and preserves local tuning.
- A dedicated status socket keeps pool status available when application workers are exhausted. OPcache collection uses the owning runtime; the separate status socket permits collection without using an application worker. A failed OPcache collection must not erase available pool health data or appear as a healthy zero.
- Both HTTP scrape listeners bind to WireGuard and admit only the current Metrics node. Status sockets and helper PHP scripts stay outside public web roots. Monitoring introduces no public application route.
- Role, node, App instance, and Metrics lifecycle changes reconcile the affected monitoring state. Reconciliation installs eligible instrumentation before publishing its target, removes obsolete targets and owned instrumentation, and preserves unrelated services and operator files. Relocation updates access to the new Metrics node and removes access from the former node.
- Grafana receives ingress traffic and PHP capacity dashboards. Stable node and App instance identities support correlation. Queries select the outer site handler observed in the generated Caddy configuration. Private traffic to a shared site can also contribute. Caddy versions starting at 2.9 supply host labels; older versions supply observations from the outer HTTPS handler without host labels. Prometheus filters unknown hosts and drops per-process series.
- Legacy shared production runtimes are excluded from per-instance service monitoring. Monitoring does not convert them or label a shared OPcache as an individual app's cache. Existing machine metrics remain available.
- Metrics enablement does not authorize runtime tuning, cache resets, application commands, or Laravel monitoring. FPM Tune recommendations are deferred; applying them requires separate enablement and explicit budgets across dedicated masters. Laravel collection remains a separate opt-in extension.

## Rejected alternatives

- Write a Caddy exporter: rejected because Caddy already exports the required metrics.
- Run one PHP exporter per App instance: rejected because Cbox can collect several explicitly configured pools from one node service.
- Automatically discover every local FPM pool: rejected because discovery cannot establish Orbit ownership or reliably bind a pool to an App instance.
- Share production masters to reduce monitoring overhead: rejected because it would undo independent deployment and cache refresh.
- Enable automatic tuning with metrics: rejected because observing a service and changing its capacity have different operational effects.

## Consequences

- Operators can relate request errors and latency to worker queues, capacity, and cache pressure.
- Service monitoring adds scrape cost, time series, and private listeners. PHP configuration changes can require an isolated master reload during enablement or removal.
- Caddy monitoring follows a shared process. When roles share a node, site observations can include private traffic. The selected outer handler includes direct and proxied responses.
- Cbox v3.1.1 omits an unavailable pool when others remain healthy. Missing series need comparison with the expected instance inventory; endpoint health alone is insufficient. OPcache dashboard panels omit failed or disabled-cache observations.
- Upstream health at ingress describes the next routing hop. It does not replace backend monitoring, application tracing, or external availability probes.
- The Gateway must extend exporter recovery, firewall inspection, Doctor, App instance lifecycle, and Grafana provisioning together.

## Affects

- Components: apps/gateway
- ADRs: extends [ADR 0003](/decisions/0003-singleton-metrics-role), [ADR 0057](/decisions/0057-limit-metrics-exporters-to-managed-nodes), and [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user)
- Detail: [Service metrics](/reference/service-metrics)
- Verify: selection, rendering, ownership, recovery, lifecycle, and Doctor tests; disposable Incus proof of private scraping, two isolated PHP masters, saturation, deployment cache refresh, removal, and Metrics relocation; documentation checks
