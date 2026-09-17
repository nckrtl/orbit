---
title: "metrics:disable"
description: "Disable Metrics, optionally purging its data."
---

# metrics:disable

Disable the Metrics role. Data stays unless you purge it.

```bash
orbit metrics:disable [--force] [--purge-data] [--json]
```

| Option | Meaning |
| --- | --- |
| `--force` | Skip the confirmation prompt. Required for a non-interactive or `--json` call, and required with `--purge-data`. |
| `--purge-data` | Also delete the Prometheus and Grafana volumes and the stored Grafana passwords. |

```bash
orbit metrics:disable
orbit metrics:disable --force --purge-data
```

An interactive call shows a preview and asks for confirmation. A non-interactive call without `--force` fails with `metrics.force_required`, and so does `--purge-data` without `--force`.

After a disable, the Metrics Node runs neither container. The Gateway removes `/etc/orbit/metrics`, both Grafana firewall rules, every exporter drop-in and firewall rule on eligible Nodes, and the `metrics.orbit` Route, certificate, and DNS record. The volumes `orbit-metrics-prometheus-data` and `orbit-metrics-grafana-data`, the stored passwords, Docker, the installed packages, and every exporter preference stay, and a later `metrics:enable` reuses them.

> **Warning:** `--purge-data` also deletes both volumes and the active and pending password settings, and nothing else. When a volume of either name lacks the Orbit ownership labels, the Gateway deletes neither volume nor password, leaves the assignment failed at `remove:baseline`, and answers `node_role.remove_failed`.

The result reports `publication` as `cleaned` when the Gateway removed the `metrics.orbit` Route, certificate, and DNS record, or `uncleaned` when no single active Gateway existed at that step and those three items remain on the Gateway host for an operator to remove.
