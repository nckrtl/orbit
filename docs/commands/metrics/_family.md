---
title: "metrics"
description: "Enable the Prometheus and Grafana role on one Node, select which Nodes run an exporter, read the Grafana credential, and disable or purge the role."
commands:
  - metrics:enable
  - metrics:status
  - metrics:credentials
  - metrics:exporter:enable
  - metrics:exporter:disable
  - metrics:disable
---

The `metrics` role runs Prometheus and Grafana as two Docker containers on one Node and the packaged `prometheus-node-exporter` unit on every selected Node. The `metrics` family enables the role, reports its status, manages exporter selection, shows the Grafana credential, and disables the role with or without its data.

The [Metrics reference](/reference/metrics) owns placement, exporter eligibility, Grafana access, credential ownership, and the API surface.

## Commands

| Command | Result |
| --- | --- |
| [`metrics:enable`](#orbit-metricsenable) | Enable Metrics on one Node. |
| [`metrics:status`](#orbit-metricsstatus) | Show the assignment, container health, and exporter state. |
| [`metrics:credentials`](#orbit-metricscredentials) | Show or reset the verified Grafana administrator credential. |
| [`metrics:exporter:enable`](#orbit-metricsexporterenable) | Select one Node as an exporter target. |
| [`metrics:exporter:disable`](#orbit-metricsexporterdisable) | Exclude one Node from exporter selection. |
| [`metrics:disable`](#orbit-metricsdisable) | Disable Metrics, optionally purging its data. |

Every command accepts `--json`, and a `node` argument accepts a numeric Node ID or a registered Node name. Every Metrics command requires exactly one active Gateway, and the Gateway authorizes the caller against that Gateway Node: the Gateway Node passes, and any other caller needs a directed access grant to the Gateway Node or receives `node_access.required`.

{/* commands */}

## Related

- [`node`](/cli/node) retries a failed convergence with `node:role:add <node> metrics --converge` and removes the role with `node:role:remove`.
- [`doctor`](/cli/doctor) expects the exporter unit and firewall rule only on eligible selected Nodes.
- [`firewall`](/cli/firewall) adds operator rules beside the role-owned `orbit:` rules on the same Node.
