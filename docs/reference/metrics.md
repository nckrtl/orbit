# Metrics role

This page tells an operator what the `metrics` role runs, how to enable, inspect, and disable it, and what each command answers. [ADR 0003](../decisions/0003-singleton-metrics-role.md) records the decisions behind the role; this page states what the operator observes.

The role runs two Docker containers on one node, `orbit-metrics-prometheus` and `orbit-metrics-grafana`, and the packaged `prometheus-node-exporter` unit on every selected node. Both containers use Docker host networking. Prometheus binds `127.0.0.1:9090` and has no firewall rule, so only a process on the Metrics node reaches it. Grafana binds the node's WireGuard address on port 3000, and a UFW rule the Metrics role owns admits that port only from the Gateway's WireGuard address. Both containers log through the `json-file` driver, capped at 10 MB per file and three files.

## Placement and recovery

Enable Metrics on an active node:

```text
orbit metrics:enable [node]
```

The node is a numeric ID or a registered node name. The command prompts for a node only in an interactive terminal. JSON and other non-interactive calls must supply the node.

The Gateway accepts the role on a node that already carries any other role, and it answers `node.role_conflict` to a second `metrics:enable` while an assignment exists on any node, whatever that assignment's status.

Retry a failed convergence with the generic role command:

```text
orbit node:role:add <node> metrics --converge
```

`--converge` re-claims an assignment that is active or whose failed step starts with `converge:`. A removal that fails leaves the assignment `failed` with a step that starts with `remove:`; `orbit metrics:disable` retries that removal, and `--converge` answers `node.role_conflict` for it. There is no separate Metrics convergence command.

The Gateway converges each container against the files it reads: Prometheus against `prometheus.yml`, Grafana against `grafana.ini` and its provisioning files. A change in the selected exporters stops and replaces the Prometheus container, so a Prometheus query in flight fails, while the Grafana container and its sessions keep running. The provisioned dashboard is bound to neither container: Grafana's file provider reloads it from the bind mount while Grafana runs. A credential reset sets the password through Grafana's own API and replaces neither container.

## Exporter selection

The Gateway evaluates every active node against its stored exporter preference and its role assignments that are active or still provisioning:

| Preference | Node state | Result |
| --- | --- | --- |
| absent | carries an active or provisioning role | selected |
| absent | carries no role | excluded |
| enabled | any active node | selected |
| disabled | any node except the Metrics node | excluded |
| any value | the Metrics node | selected |

Set an explicit preference with:

```text
orbit metrics:exporter:enable <node>
orbit metrics:exporter:disable <node>
```

Both commands answer `metrics.exporter_node_inactive` for a node that is not active, and `metrics:exporter:disable` answers `node.role_conflict` for the Metrics node.

A selected node runs the packaged `prometheus-node-exporter` unit with the Orbit drop-in at `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf`. The drop-in binds the exporter to the node's WireGuard address on port 9100, and a UFW rule that the Metrics role owns admits that port only from the Metrics node's WireGuard address.

## Private access and credentials

An operator opens Grafana at `https://metrics.orbit` from any WireGuard peer. Private DNS answers with the Gateway's WireGuard address, and the Gateway's Caddy presents an Orbit-CA certificate and proxies the request over WireGuard to Grafana on the Metrics node. Grafana then asks for its own login.

Every Metrics route, reads included, and every `node:role:add` or `node:role:remove` call for `metrics` requires one active Gateway, and the Gateway authorizes the caller against that Gateway node. The Gateway node passes. Any other caller needs a directed access grant to the Gateway node; a caller that holds a grant only to the Metrics node or to an exporter node gets `node_access.required` (HTTP 403).

Show or reset the verified Grafana administrator credential:

```text
orbit metrics:credentials
orbit metrics:credentials --reset
```

The username is `admin`. The Gateway stores the active and pending passwords as encrypted node settings on the Metrics node. A reset reuses a pending password after a partial failure and returns a password only after Grafana has accepted it. Credential responses carry `Cache-Control: no-store`.

## Status, disable, and purge

Show assignment, container health, and desired and actual exporter state:

```text
orbit metrics:status
orbit metrics:status --json
```

Disable Metrics:

```text
orbit metrics:disable
orbit metrics:disable --force
orbit metrics:disable --force --purge-data
```

Interactive disable asks for confirmation. Non-interactive disable requires `--force`. Purge also requires `--force`.

After a disable without `--purge-data`, the Metrics node runs neither container, `/etc/orbit/metrics` and the Grafana upstream firewall rule are gone, every exporter drop-in and exporter firewall rule is gone, and the Gateway has removed the `metrics.orbit` route, its certificate, and its DNS record. The volumes `orbit-metrics-prometheus-data` and `orbit-metrics-grafana-data`, the stored Grafana password settings, Docker, the installed packages, and every exporter preference stay, and a later `orbit metrics:enable` reuses them.

With `--purge-data`, the Gateway also deletes both volumes and the active and pending password settings, and nothing else. When a volume of either name lacks the Orbit ownership labels, the Gateway deletes neither volume nor password, leaves the assignment failed at step `remove:baseline`, and answers `node_role.remove_failed` (HTTP 502).

`DELETE /api/v1/metrics` and `orbit metrics:disable` report the Gateway-side outcome in `publication`:

| Value | Meaning |
| --- | --- |
| `cleaned` | The Gateway removed the `metrics.orbit` route, its certificate, and its DNS record. |
| `uncleaned` | No single active Gateway existed when the removal ran, and the route, certificate, and DNS record stay on the Gateway host. |

### Disable when no single Gateway is active

With none or more than one active Gateway, the Gateway answers `node_access.required` to every Metrics route and to every `node:role:add` or `node:role:remove` call for `metrics`. Every caller gets that answer, the Gateway node included, so neither `orbit metrics:disable` nor `orbit node:role:remove` removes the role while the fleet has no single active Gateway.

A removal that the Gateway authorized and that finds no single active Gateway when its Metrics step runs still removes the exporters, both containers, and `/etc/orbit/metrics` from the Metrics node. It removes the Grafana upstream firewall rule when the node's firewall answers, and it reports `"publication": "uncleaned"`. `orbit metrics:disable` then prints `Publication not cleaned: no single active Gateway. The metrics.orbit route, certificate, and DNS record remain on the Gateway.` Those three items stay on the Gateway host until an operator removes them.

`orbit node:remove <node> --offline --force` resolves no Gateway, so it sheds the role from an unreachable Metrics node while the fleet has no single active Gateway; the route, certificate, and DNS record then stay on the Gateway host in the same way.

## API surface

The Metrics API exposes these routes on the active Gateway.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/metrics` | Enable the role. |
| `DELETE` | `/api/v1/metrics` | Disable the role. |
| `GET` | `/api/v1/metrics/status` | Read status. |
| `GET` | `/api/v1/metrics/credentials` | Read verified credentials. |
| `POST` | `/api/v1/metrics/credentials/reset` | Reset credentials. |
| `PUT` | `/api/v1/metrics/exporters/{node}` | Enable one exporter. |
| `DELETE` | `/api/v1/metrics/exporters/{node}` | Disable one exporter. |
