---
title: "Metrics role"
description: "What the metrics role runs, how to enable, inspect, and disable it, and how Grafana access is authorized."
---

# Metrics role

The `metrics` role runs Prometheus and Grafana on one Node and collects metrics from selected managed Nodes. Use it to view machine health at `https://metrics.orbit`. [ADR 0003](/decisions/0003-singleton-metrics-role) defines placement, [ADR 0055](/decisions/0055-restrict-grafana-access-to-authorized-gateway-peers) defines access, and [ADR 0057](/decisions/0057-limit-metrics-exporters-to-managed-nodes) defines eligible exporters.

[Service metrics](/reference/service-metrics) proposes native Caddy monitoring on selected ingress nodes and Cbox FPM Exporter on selected app-prod nodes. That extension is not implemented yet; the current exporters are described below.

The containers are `orbit-metrics-prometheus` and `orbit-metrics-grafana`. Each selected Node runs `prometheus-node-exporter` and `orbit-cadvisor`. Both containers use host networking. Prometheus listens locally at `127.0.0.1:9090`, without a firewall rule. Grafana listens on WireGuard port 3000. Two Orbit UFW rules allow the Gateway and block other peers before general member rules apply. Container logs use `json-file`, limited to three files of 10 MB each.

## Placement and recovery

Enable Metrics on an active node:

```text
orbit metrics:enable [node]
```

The node is a numeric ID or a registered node name. The command prompts for a node only in an interactive terminal. JSON and other non-interactive calls must supply the node.

Metrics can share a Node with any other role. Only one Metrics assignment may exist. A second enable request returns `node.role_conflict`, even if the existing assignment failed.

Retry a failed convergence with the generic role command:

```text
orbit node:role:add <node> metrics --converge
```

`--converge` re-claims an assignment that is active or whose failed step starts with `converge:`. A removal that fails leaves the assignment `failed` with a step that starts with `remove:`; `orbit metrics:disable` retries that removal, and `--converge` answers `node.role_conflict` for it. There is no separate Metrics convergence command.

Move an existing assignment with `orbit node:role:relocate <node> metrics --force`. Relocate copies missing Grafana credentials onto the target, converges the target baseline, and retracts the source publication and runtime. It does not overwrite credentials the target already has. Pass `--from` when the target already holds `metrics` and leftovers remain on another Node. Remove-then-add is not the supported path.

Convergence starts exporters, then cAdvisor, then the Prometheus and Grafana runtime, then Gateway publication (certificate, Caddy, Grafana firewall, and private DNS). After the runtime is up, a publication failure — including `converge:private-dns` / `app-dev.dns_config_failed` when the DNS listener lives on a different `vpn` node — leaves Grafana, Prometheus, cAdvisor, and `/etc/orbit/metrics` in place. Retry `--converge` after the DNS path is reachable. Adding or relocating the `gateway` role grants that Gateway access to the `vpn` and `metrics` nodes so this path can run. [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate) owns those rules.

Orbit updates each container when its configuration changes: `prometheus.yml` for Prometheus; `grafana.ini` and provisioning files for Grafana. Changing exporters replaces Prometheus and interrupts active queries. Grafana and its sessions keep running. Grafana reloads dashboard files without a container restart. Password resets use Grafana's API and replace neither container.

## Exporter selection

The Gateway selects exporters only on active Nodes that use the supported managed-node platform, have a managed WireGuard address, and have Gateway-owned Secure Shell (SSH) management. A non-empty stored SSH fingerprint proves that management for a roleless Node. An active or provisioning managed role also preserves it for a Node whose fingerprint is not stored. Within that eligible managed fleet, the Gateway evaluates the stored exporter preference and role assignments that are active or still provisioning:

| Preference | Node state | Result |
| --- | --- | --- |
| absent | carries an active or provisioning role | selected |
| absent | carries no role | excluded |
| enabled | eligible Node with or without a role | selected |
| disabled | eligible Node except the Metrics Node | excluded |
| any value | ineligible record | excluded |
| any value | the Metrics Node | selected |

Set an explicit preference with:

```text
orbit metrics:exporter:enable <node>
orbit metrics:exporter:disable <node>
```

Both commands answer `metrics.exporter_node_inactive` for a Node that is not active, and `metrics:exporter:disable` answers `node.role_conflict` for the Metrics Node. The enable command answers `metrics.exporter_node_ineligible` (HTTP 409) for a Node outside Gateway-owned SSH management before it saves the preference or starts remote work. A stored enabled preference cannot make an ineligible record an exporter target.

A selected node runs the packaged `prometheus-node-exporter` unit with the Orbit drop-in at `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf`. The drop-in binds the exporter to the node's WireGuard address on port 9100, and a UFW rule that the Metrics role owns admits that port only from the Metrics node's WireGuard address.

Doctor does not expect an exporter service, exporter firewall rule, or exporter SSH reachability on an exporter-ineligible record. The Node family separately keeps lifecycle, reachability, and identity findings for a Node that the Gateway manages over SSH, even when that Node is not active. A stored fingerprint proves this observation contract in every lifecycle state. For a legacy Node without a stored fingerprint, any remaining managed role preserves the contract until the Gateway deletes that role. Doctor suppresses these Node-family findings only for records that the Gateway does not manage over SSH.

## Per-Process CPU and memory (cAdvisor)

`node_exporter` exposes systemd unit *state* only, not per-unit CPU or memory, so every selected exporter Node also runs [cAdvisor](https://github.com/google/cadvisor), pinned to `v0.60.5` and verified by SHA256 checksum before install. cAdvisor reads cgroups directly, which covers both systemd Processes and Docker Processes, and it is what [`orbit top`](/cli/top)'s Processes pane and `GET /api/v1/processes` read CPU and memory from.

Every Node that runs `prometheus-node-exporter` runs cAdvisor the same way: a pinned static binary at `/usr/local/bin/orbit-cadvisor`, not a Docker container, because the Gateway Node is itself an exporter Node and has no Docker. A systemd unit (`orbit-cadvisor.service`) binds it to the Node's WireGuard address on port 9102, `Restart=always`, and a UFW rule admits only the Metrics Node's WireGuard address to that port, mirroring the node exporter's own rule. Enabling and disabling the exporter on a Node enables and disables cAdvisor with it; disabling removes the unit, the firewall rule, and the binary.

An unfiltered cAdvisor is expensive: measured on beast, it added 8,359 series against `node_exporter`'s 5,150, at 3.1-4.5% of one core and 54-65 MiB. cAdvisor's install disables every metric kind except `cpu` and `memory` (`--disable_metrics=sched,percpu,memory_numa,cpuLoad,diskIO,disk,network,tcp,advtcp,udp,app,process,hugetlb,referenced_memory,cpu_topology,resctrl,cpuset,oom_event,pressure`), and `--store_container_labels=false` drops Docker container labels and environment variables from becoming Prometheus label dimensions. Prometheus scrapes cAdvisor every 30 seconds, six times less often than the node exporter's five seconds, because process CPU and memory do not need `orbit top`'s node-page resolution and cAdvisor is the more expensive job of the two.

A Process's cAdvisor series is keyed by its systemd unit name or Docker container name, both `orbit-process-{id}-{name}`. A Process cAdvisor has no series for — not running, or cAdvisor unreachable — reports null CPU and memory, never zero.

## Private access and credentials

An operator opens Grafana at `https://metrics.orbit` from the active Gateway node or an active WireGuard peer with a directed access grant to that Gateway. A grant only to the Metrics node does not allow dashboard access. Private DNS answers with the Gateway's WireGuard address, and the Gateway's Caddy presents an Orbit certificate-authority (CA) certificate. Caddy identifies the caller from the connection address, ignores caller-supplied forwarding and identity headers, checks current Gateway authority before each browser, API, or streaming request, and then proxies admitted traffic over WireGuard to Grafana on the Metrics node.

The Gateway refuses an unknown, inactive, ungranted, or public caller and refuses traffic when caller identity or authorization state is unavailable. Removing the peer from WireGuard membership or removing its Gateway grant refuses later requests and closes existing streaming connections. Repeating the revocation keeps access closed.

Only `metrics.orbit` publishes Grafana to users. The Gateway refuses an alternate host or direct Gateway-address request for Grafana. The Metrics node firewall refuses direct Grafana traffic from every peer except the Gateway proxy, including when the Gateway and Metrics roles share one node. This Grafana exception does not change WireGuard reachability for other private services.

Gateway authorization does not sign in to Grafana. Grafana asks every admitted caller for its own login, and ordinary Grafana and Metrics responses do not contain the stored administrator password.

Every Metrics route, reads included, and every `node:role:add` or `node:role:remove` call for `metrics` requires one active Gateway, and the Gateway authorizes the caller against that Gateway node. The Gateway node passes. Any other caller needs a directed access grant to the Gateway node; a caller that holds a grant only to the Metrics node or to an exporter node gets `node_access.required` (HTTP 403).

Show or reset the verified Grafana administrator credential:

```text
orbit metrics:credentials
orbit metrics:credentials --reset
```

The username is `admin`. The Gateway encrypts active and pending passwords in the Metrics Node's settings. One lock protects password creation, verified reads, resets, and deletion from first read to final update. A competing request waits within its deadline, then reads the completed state or returns HTTP 409 `metrics.credentials_busy` without changes. The lock does not expire during an operation.

A failed reset keeps the encrypted pending password. A retry first checks whether Grafana accepts it. If so, Orbit marks it active; otherwise, Orbit applies and verifies it first. Credential responses include the password only after verification and use `Cache-Control: no-store`.

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

Metrics reconvergence and removal preserve Caddy global options and unrelated site fragments when withdrawing the Metrics route.

Interactive disable shows a preview and asks for confirmation, defaulting to No. Interactive decline, Ctrl-C, or EOF exits with `input.cancelled` and makes no changes. Non-interactive disable without `--force` fails with `metrics.force_required`, and so does `--purge-data` without `--force`, in every mode.

After a disable without `--purge-data`, the Metrics node runs neither container. The Gateway removes `/etc/orbit/metrics`, both Grafana firewall rules, every exporter drop-in and exporter firewall rule on an eligible managed Node, and the `metrics.orbit` route, certificate, and DNS record. Exporter state that was converged before a Node became ineligible remains unchanged because the Gateway does not inspect or change it. The volumes `orbit-metrics-prometheus-data` and `orbit-metrics-grafana-data`, the stored Grafana password settings, Docker, the installed packages, and every exporter preference stay, and a later `orbit metrics:enable` reuses them.

With `--purge-data`, the Gateway also deletes both volumes and the active and pending password settings, and nothing else. Credential purge uses the same owner as creation, reads, and reset, so it cannot delete or restore a stale credential snapshot. A later authorized enable can initialize a new credential. When a volume of either name lacks the Orbit ownership labels, the Gateway deletes neither volume nor password, leaves the assignment failed at step `remove:baseline`, and answers `node_role.remove_failed` (HTTP 502).

`DELETE /api/v1/metrics` and `orbit metrics:disable` report the Gateway-side outcome in `publication`:

| Value | Meaning |
| --- | --- |
| `cleaned` | The Gateway removed the `metrics.orbit` route, its certificate, and its DNS record. |
| `uncleaned` | No single active Gateway existed when the removal ran, and the route, certificate, and DNS record stay on the Gateway host. |

### Disable when no single Gateway is active

With none or more than one active Gateway, the Gateway answers `node_access.required` to every Metrics route and to every `node:role:add` or `node:role:remove` call for `metrics`. Every caller gets that answer, the Gateway node included, so neither `orbit metrics:disable` nor `orbit node:role:remove` removes the role while the fleet has no single active Gateway.

A removal that the Gateway authorized and that finds no single active Gateway when its Metrics step runs still removes the exporters, both containers, and `/etc/orbit/metrics` from the Metrics node. It removes the Grafana upstream firewall rule when the node's firewall answers, and it reports `"publication": "uncleaned"`. `orbit metrics:disable` then prints `Publication not cleaned: no single active Gateway. The metrics.orbit route, certificate, and DNS record remain on the Gateway.` Those three items stay on the Gateway host until an operator removes them.

`orbit node:remove <node> --offline --force` resolves no Gateway, so it sheds the role from an unreachable Metrics node while the fleet has no single active Gateway; the route, certificate, and DNS record then stay on the Gateway host in the same way.

## Reading Node metrics

[`orbit node:metrics`](/cli/node#orbit-node-metrics) reads a Node's CPU, memory, swap, load, uptime, pressure, and disk snapshot from the Metrics role's own Prometheus. It reads through Grafana's datasource proxy, not from Prometheus directly. The Gateway authenticates with the stored Grafana credential, resolves the Prometheus datasource, and runs four instant PromQL queries filtered to that Node's exporter instance.

Prometheus scrapes every selected exporter every five seconds and keeps samples for seven days, so a reading is at most five seconds old. Each rate the queries compute covers a thirty-second window, wide enough to survive a dropped scrape and short enough to show a spike rather than average it away. A scrape costs the Node one read of `/proc` and `/sys`, measured between 0.08 and 0.18 seconds depending on how many cores and filesystems it has.

No orbit software runs on the Node beyond the exporter this role already manages. The command does not depend on what CLI build the Node was provisioned with. A Node with no active exporter selection or no Prometheus samples yet answers `node.metrics_unreachable` instead of failing.

[`orbit top`](/cli/top) reads the same way, directly from the CLI: one set of queries covers every Node for the dashboard, and a filtered set covers one Node for its page. See [ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana) for why the CLI reads this way instead of through a Gateway endpoint.

## API surface

The Metrics API exposes these routes on the active Gateway.

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/metrics` | Enable the role. |
| `DELETE` | `/api/v1/metrics` | Disable the role. |
| `GET` | `/api/v1/metrics/status` | Read status. |
| `GET` | `/api/v1/metrics/credentials` | Read verified credentials. |
| `POST` | `/api/v1/metrics/credentials/reset` | Reset credentials. |
| `GET` | `/api/v1/metrics/grafana/authorize` | Authorize one Caddy Grafana request from its connection address. |
| `PUT` | `/api/v1/metrics/exporters/{node}` | Enable one exporter. |
| `DELETE` | `/api/v1/metrics/exporters/{node}` | Disable one exporter. |
