---
title: "ADR 0088: CLI reads display metrics from Grafana"
sidebarTitle: "0088 CLI reads display metrics from Grafana"
description: "Accepted. Amended by ADR 0147. orbit top and the Gateway's own node:metrics implementation both read Node metrics through the Metrics role's Grafana datasource proxy, never from the CLI to Prometheus directly and never through a new Gateway aggregate endpoint."
---

# ADR 0088: CLI reads display metrics from Grafana

`orbit top`'s dashboard and Node page read CPU, memory, swap, load, uptime, pressure, and disk metrics directly from the Metrics role's Grafana, through its existing datasource proxy, rather than through the Gateway API. The Gateway's own `GET /api/v1/nodes/{node}/metrics` (`node:metrics`) reads the same way, from the Gateway itself. Every other action, and the only documented way to read one Node's metrics from outside the fleet, still goes through the Gateway API; the Gateway stores no metrics sample anywhere.

## Status

Accepted on 2026-09-18. Amended by [ADR 0147](/decisions/0147-retire-orbit-top): the CLI no longer reads display metrics, and the web app reads them through the Gateway's `/grafana` path.

## Context

`orbit node:metrics` and the metrics blocks in `orbit top` both used to depend on a hidden CLI command, `orbit internal:node-metrics`, that the Gateway ran over SSH on the target Node. A Node only has that command if it was provisioned with a CLI build that carries it, so most of Nick's real Nodes answered `node.metrics_unreachable`. The Metrics role's Prometheus already scrapes a node exporter on every eligible Node every five seconds ([`metrics`](/reference/metrics)), so the fix is to read metrics from there instead of from the Node's own CLI.

Prometheus binds `127.0.0.1:9090` on the Metrics Node only ([`MetricsRuntimeSpec`](https://github.com/nckrtl/orbit/blob/main/apps/gateway/app/Infrastructure/Metrics/MetricsRuntimeSpec.php)), and nothing off that host can reach it today. Opening it further — a new bind address, a new firewall rule, a new published hostname — was considered and rejected (see below): Grafana already sits in front of Prometheus, already requires its own login, and its datasource proxy already re-serves the underlying Prometheus HTTP API at `GET {url}/api/datasources/proxy/uid/{uid}/api/v1/query`, authorized by the same Grafana credential `orbit metrics:credentials` already returns. Verified live: that endpoint answers the standard Prometheus JSON envelope, keyed by the scraped `instance` label (`WireGuardIP:9100`), and the same request without credentials answers 401.

[ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client) requires `orbit top` to compose existing per-family SDK requests and never invent a bulk or aggregate Gateway endpoint. Node metrics are the one exception this ADR carves out: querying Prometheus once, through Grafana, for every Node's exporter instance at once is what makes the dashboard's fleet-wide refresh cheap, and a Gateway aggregate endpoint would only move that same query to a different host for no benefit, since Grafana already answers it directly.

## Decision

- The CLI must read Node metrics for display — `orbit top`'s dashboard and Node page — directly from the Metrics role's Grafana, never from Prometheus directly and never through a new Gateway endpoint. It must:
  1. Ask the Gateway for the Metrics credential once per session, through the existing `GET /api/v1/metrics/credentials` (`ShowMetricsCredentialsRequest`) — the same call `metrics:credentials` makes.
  2. Resolve the Prometheus datasource by calling `GET {url}/api/datasources` with that credential and selecting the entry whose `type` is `prometheus`, rather than hard-coding its `uid`.
  3. Run one instant PromQL query per metric group (cores, memory and swap, load and uptime, pressure, disks) against `GET {url}/api/datasources/proxy/uid/{uid}/api/v1/query`, unfiltered for the dashboard (one round trip covers every Node) and filtered by `instance="{wireguard_ip}:9100"` for one Node's page.
  4. Match a returned series to a Node by its `instance` label's address against the Node's `wireguard_ip`, using the Node list the screen already loaded at startup — never by trusting a custom label, and never by the Node's stored `platform` field, which can be stale.
- `App\Infrastructure\Metrics\PrometheusNodeMetricsMapper` (Gateway) and `App\Support\Metrics\PrometheusNodeMetricsMapper` (CLI) must map the four decoded Prometheus responses into the existing `NodeMetricsResponse`/`NodeMetricsData` shape unchanged, detecting a Node's memory family (Linux vs. Darwin `node_exporter` metric names) from which metrics it actually reported. A Node with no samples for either memory family must be treated as unavailable, not zeroed.
- The Gateway's own `GET /api/v1/nodes/{node}/metrics` must read the same way, from the Gateway itself: `App\Infrastructure\Nodes\Metrics\GrafanaPrometheusNodeMetricsReader` calls Grafana's datasource proxy directly on the Metrics Node's WireGuard address, port 3000 — the same address and port the Grafana upstream firewall rule already admits the Gateway to reach ([`MetricsPublicationSshExecutor::converge()`](https://github.com/nckrtl/orbit/blob/main/apps/gateway/app/Infrastructure/Metrics/MetricsPublicationSshExecutor.php)) — instead of looping back out through its own published `metrics.orbit` hostname, which the Gateway host cannot resolve by name anyway ([ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) notes the same constraint for Reverb).
- No new hostname, listener, firewall rule, or Gateway route is part of this decision. Prometheus keeps binding loopback on the Metrics Node.
- Response shapes, units, and error codes are unchanged: `node.metrics_unavailable` (422) for a Node that is not active or has no WireGuard address, `metrics.assignment_missing` (409) when no Node carries the Metrics role, `node.metrics_unreachable` (502) when Grafana rejects the request or Prometheus has no samples for the Node.
- A metrics pane that cannot place a sample — Metrics disabled, the credential rejected, the Node has no WireGuard address, or Prometheus has no data for it yet — must render the existing dim "No metrics." rather than failing the screen, exactly as an unreachable `node:metrics` response already does.
- Adding a metric to `orbit top`'s query set (a new PromQL query, a new panel) needs no Gateway route, migration, or deploy, since the Gateway never stores or forwards a display sample; it is a CLI-only change.
- Authorization for this path is two gates. The Caddy site on the Gateway that fronts `metrics.orbit` forward-auths every request against the caller's active WireGuard peer identity, the same check every other Gateway API call passes. Grafana then requires its own credential on top of that. The CLI's direct-to-Grafana calls and the Gateway's own direct-to-Grafana calls both carry that credential; neither reaches Prometheus or Grafana any other way. Neither gate is removed or bypassed by this decision.

## Rejected alternatives

- **Publish Prometheus itself as a new hostname (`prometheus.orbit`), with its own certificate, Caddy site, and firewall rule**: rejected. It would duplicate almost everything Grafana's publication already does (a certificate, a Caddy site, a firewall boundary) for a service that already sits, authenticated, behind Grafana's proxy. It also puts a second unauthenticated-by-default port in front of every Node's resource metrics, which Nick's stated constraint (operators must not reach a raw Prometheus endpoint) rules out directly.
- **A new Gateway fleet endpoint (`GET /api/v1/metrics/nodes`) that reads Prometheus over SSH and the CLI calls instead of Grafana**: rejected. It reintroduces an SSH round trip to the Metrics Node for every dashboard refresh, needs a new route, a new SDK request and response, and a new CLI command surface entry, and still ends up proxying the exact same Prometheus data Grafana already proxies for free. [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client) already disfavors inventing aggregate endpoints for `orbit top`.
- **Bind Prometheus on the Metrics Node's WireGuard address and reach it directly (no Grafana in the path)**: rejected once Nick clarified the actual constraint is operator-facing (no raw Prometheus endpoint), not merely "the Gateway can't resolve `*.orbit`" — Grafana's existing password gate already satisfies that constraint, so adding a second listener would only add exposure without adding a real capability.
- **The CLI queries Prometheus directly over the Node's `internal:node-metrics` command, kept as a fallback**: rejected outright; that is the exact unreliable dependency this change removes, and a fallback would leave the unreliable path reachable.

## Consequences

- `orbit node:metrics` works on every Node the Metrics role scrapes, regardless of which CLI build (if any) that Node was provisioned with, because nothing needs to run on the Node beyond the exporter the Metrics role already manages.
- `orbit top`'s dashboard cost is one Grafana round trip per refresh tick for every Node, not one Gateway request per Node, matching [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client)'s intent even though this is its one carved-out exception to "no aggregate endpoint" — the aggregate lives in Grafana already, so `orbit top` composes an existing capability rather than inventing a Gateway one.
- A new metrics panel built on data `node_exporter` already reports needs no Gateway change, migration, or deploy: it is a CLI-only addition to the query set.
- The Gateway never stores, logs, or forwards a metrics sample outside of relaying Grafana's own answer for one Node's `node:metrics` request; the Grafana credential itself must never appear in a log, a fixture, a snapshot, or any `--json` output beyond what `metrics:credentials` already returns.
- Metrics display depends on the Metrics role's Grafana being reachable and authenticated exactly as it already is for a human operator opening `https://metrics.orbit`; nothing about this decision changes who can reach Grafana or what they need to authenticate.

## Affects

- Components: apps/gateway, apps/cli, apps/docs
- ADRs: carves out one exception to [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client)'s "no aggregate endpoint" rule, scoped to Node metrics display only; none amended
- Detail: [`node`](/cli/node), [`metrics`](/cli/metrics)
- Verify: `apps/gateway/tests/Unit/Infrastructure/Metrics/PrometheusNodeMetricsMapperTest.php`, `apps/gateway/tests/Unit/Infrastructure/Nodes/Metrics/GrafanaPrometheusNodeMetricsReaderTest.php`
