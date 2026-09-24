---
title: "ADR 0139: Collect Caddy HTTP metrics on every Node"
sidebarTitle: "0139 Collect Caddy HTTP metrics on every Node"
description: "Proposed. Orbit's single Caddy global options block turns on per-host HTTP metrics on every Node that runs Caddy. The service metrics fragment keeps only its scrape site, so it no longer opens a second global block."
---

# ADR 0139: Collect Caddy HTTP metrics on every Node

Orbit's Caddy global options block turns on per-host HTTP metrics on every Node that runs Caddy. The service metrics fragment on Ingress keeps only its scrape site and no longer opens its own global block. Which Nodes Prometheus scrapes does not change.

## Status

Proposed.

## Context

[ADR 0099](/decisions/0099-collect-role-specific-service-metrics) collects Caddy metrics on a selected Ingress Node with a published public Route. Its fragment, `00-metrics-service.caddy`, started with a global block, `{ metrics { per_host } }`, because Caddy collects HTTP metrics only when the `metrics` global option is set.

[ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) makes Orbit's block the only global block on a Node, and every publisher refuses a candidate that carries another one. The service metrics fragment is such a block. On an Ingress Node with service metrics, every Caddy publication therefore fails: App instance deploys, Route changes, and public-edge converges. Before ADR 0137, `caddy validate` rejected the same candidate with a generic error.

Caddy HTTP metrics cost CPU on every request. A benchmark on Caddy 2.11.4 with two pinned cores and Orbit's global block compared the block with and without `metrics { per_host }`. A site that only returned a fixed response served about 10% fewer requests per second, about 3 microseconds of extra CPU per request. For a reverse proxy site, the difference stayed within run-to-run noise of about 5 to 8%. Orbit's sites run PHP requests that take milliseconds, so the cost is a small fraction of a percent of each request.

Collected metrics also help debugging on Nodes that Prometheus does not scrape. Caddy's local administration endpoint serves them at `http://localhost:2019/metrics` on the Node.

## Decision

- `CaddyGlobalOptions` renders `metrics { per_host }` after `auto_https disable_certs`. Every Orbit Caddy publisher writes that block, so every Node that runs Caddy collects per-host HTTP metrics.
- The service metrics fragment contains only the Orbit marker and the WireGuard scrape site. It opens no global block.
- Scrape targets, the scrape listener, firewall rules, and Prometheus host filtering stay as ADR 0099 describes. Only a selected Ingress Node with a published public Route exposes Caddy metrics to Prometheus.
- Caddy supports `per_host` from release 2.9.0, which is the floor from [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation). Service metrics no longer checks the Caddy version or renders a fallback for older releases.

## Rejected alternatives

- Add the option only on Nodes with service metrics: rejected because every publisher would need the Node's service metrics state, and the global block would differ per Node. The measured cost does not justify that complexity.
- Let the guard accept Orbit's own service metrics fragment: rejected because Caddy still allows only one global block, so the candidate would fail validation.
- Leave HTTP metrics off: rejected because Ingress traffic monitoring from ADR 0099 depends on it.

## Consequences

- Caddy publications work again on Ingress Nodes with service metrics.
- Every Node that runs Caddy spends about 3 microseconds of CPU per request on metrics, and Caddy keeps in-memory series per host. Nodes with many hostnames, such as development Nodes, hold more series in Caddy's memory. Prometheus stores them only where it scrapes.
- A Node that already carries the old service metrics fragment keeps refusing publications until that fragment loses its global block. The refusal message names the file. The operator removes the global block from that fragment, then publishes again. The next service metrics converge writes the new fragment.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0099](/decisions/0099-collect-role-specific-service-metrics) for where Caddy collects metrics; extends [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) with the metrics option in Orbit's global block
- Detail: [Caddy configuration](/reference/caddy-configuration#what-a-build-contains), [Service metrics](/reference/service-metrics#caddy-traffic)
- Verify: `apps/gateway` Pest tests for `CaddyGlobalOptions`, the carried global options guard with the service metrics fragment, and `ServiceMetricsConfigRenderer` with a real `caddy adapt`
