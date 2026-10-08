---
title: "Metrics role"
description: "What the metrics role runs, how to enable, inspect, and disable it, and how Grafana access is authorized."
covers:
  - apps/gateway/app/Infrastructure/Metrics/**
  - apps/gateway/app/Domain/Metrics/**
  - apps/gateway/app/Infrastructure/Nodes/Metrics/**
  - apps/gateway/app/Infrastructure/Nodes/Roles/MetricsRoleBaseline.php
  - apps/gateway/app/Domain/Nodes/ManagedNodeEligibility.php
  - apps/gateway/app/Http/Controllers/Api/{MetricsController,GrafanaAccessAuthorizationController}.php
  - apps/gateway/app/Infrastructure/Processes/PrometheusProcessRuntimeStatusIndex.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/MetricsCaddySiteSource.php
---

# Metrics role

The `metrics` role runs Prometheus and Grafana on one Node. It collects metrics from the selected managed Nodes. Exporters and Node-agent installation require managed Linux Nodes. SSH management on a macOS Node enables tools, not these Linux services. Open the dashboards at `https://metrics.orbit`. [`metrics`](/cli/metrics) lists the commands. [Service metrics](/reference/service-metrics) adds Caddy and PHP-FPM metrics to the same role.

## What runs where

The role runs two containers on the Metrics Node and two units on each selected Node.

| Part | Where | Listens on |
| --- | --- | --- |
| `orbit-metrics-prometheus` container | The Metrics Node | `127.0.0.1:9090`, with no firewall rule |
| `orbit-metrics-grafana` container | The Metrics Node | The Node's WireGuard address, port 3000 |
| `prometheus-node-exporter` unit | Every selected Node | The Node's WireGuard address, port 9100 |
| `orbit-cadvisor` unit | Every selected Node | The Node's WireGuard address, port 9102 |

Both containers use pinned official images and host networking. Their logs use `json-file`, with three files of 10 MB each. Prometheus scrapes every target every 10 seconds and keeps samples for 7 days. It labels each target with the Orbit Node name in `node`, and the dashboards filter on that label. Grafana gets one Prometheus datasource and three dashboards: `Orbit Node Resources`, `Orbit Caddy Traffic`, and `Orbit PHP Capacity`. The last two stay empty until [service metrics](/reference/service-metrics) select a Node.

The role runs its containers and units itself. They create no `Process` records, and their packages create no `Tool` records.

## Enable and recover

Enable the role with `orbit metrics:enable <node>`. The Node must be active. The role is a singleton and can share a Node with any other role. A second enable returns `node.role_conflict`, even when the existing assignment failed.

Convergence runs four steps in this order. Each failure records its step as `failed_step`.

| Step | Work |
| --- | --- |
| `converge:metrics-exporters` | Installs and starts the node exporter on each selected Node. |
| `converge:metrics-cadvisor` | Installs and starts cAdvisor on each selected Node. |
| `converge:metrics-runtime` | Writes `/etc/orbit/metrics` and runs the two containers. |
| `converge:metrics-publication` | Publishes the certificate, the Grafana firewall rules, the Gateway Caddy site, and the private DNS record, in that order. |

`error_code` holds the code that stopped the step. This is usually a `metrics.*` code. It can be another component's code, such as a certificate error. A failure without a code records `metrics.convergence_failed`. A step that names itself, such as `converge:private-dns`, keeps its own name and code. The error response carries the code as `details.error_code` next to `details.step`. A publication failure leaves the runtime in place.

Retry a failed convergence with `orbit node:role:add <node> metrics --converge`. This retries an assignment that is active or whose failed step starts with `converge:`. It also takes over a `provisioning` claim that is stale. A failed removal leaves a failed step that starts with `remove:`. Retry it with `orbit metrics:disable`. `--converge` answers `node.role_conflict` for that assignment.

Convergence has fixed time limits.

| Work | Limit | Error code |
| --- | --- | --- |
| Pull a missing image | 300 seconds | `metrics.image_pull_failed` or `metrics.image_pull_timed_out` |
| Check the Prometheus configuration with `promtool` | 60 seconds | `metrics.prometheus_configuration_check_timed_out` |
| Install the node exporter package | 300 seconds | `metrics.exporter_install_failed` |
| Download cAdvisor | 120 seconds, in a 150-second command | `metrics.cadvisor_binary_download_failed` |
| Any other remote command | 120 seconds | `metrics.remote_command_timed_out` |

Convergence pulls an image only when the Metrics Node lacks it. When an image is missing and Docker does not answer, convergence fails with `metrics.docker_unavailable` and names the Node. Start Docker and converge again.

Orbit replaces a container only when its configuration changes. A change to the selected exporters replaces Prometheus and interrupts open queries. Grafana keeps running and reloads dashboard files without a restart. A password reset uses Grafana's API and replaces no container.

## Relocate

Move the role with `orbit node:role:relocate <node> metrics --force`. Relocate copies missing Grafana credentials to the target. It never overwrites credentials that the target already has. It converges the target, then removes the source runtime and publication.

The target convergence points every node exporter and cAdvisor firewall rule at the target's WireGuard address. It replaces an Orbit rule only when the IPv4 source is the only difference. Any other difference fails with `metrics.exporter_firewall_ownership_drift` or `metrics.cadvisor_firewall_ownership_drift`.

When the target convergence fails, rollback removes the exporters and cAdvisor, and the source keeps its runtime. The command names the `--from` retry that finishes the move. Use `--from` when the target already holds `metrics` and leftovers remain on another Node. Adding or relocating the `gateway` role grants the Gateway access to the `vpn` and `metrics` Nodes, so this path can run.

## Exporter selection

A Node can host an exporter only when the Gateway manages it. The Node must be active, run Linux, have a WireGuard address, and have a stored SSH host fingerprint. Within that set, the Gateway reads the Node's exporter preference and its active or provisioning roles.

| Preference | Node | Result |
| --- | --- | --- |
| none | Has an active or provisioning role | Selected |
| none | Has no role | Not selected |
| `enabled` | Any eligible Node | Selected |
| `disabled` | Any eligible Node except the Metrics Node | Not selected |
| any | Not eligible | Not selected |
| any | The Metrics Node, when eligible | Selected |

`orbit metrics:exporter:enable` and `orbit metrics:exporter:disable` store the preference. Nothing else writes one: adding or adopting a Node stores no preference. Preferences survive role changes and a disabled Metrics role. The Gateway checks eligibility before it stores an enabled preference or starts remote work.

A selected Node runs the packaged `prometheus-node-exporter` unit with the Orbit drop-in `/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf`. The drop-in binds the exporter to the WireGuard address. It sets `Restart=always` and `RestartSec=2`, because the exporter can start before WireGuard adds that address at boot. The UFW rule `orbit:metrics-node-exporter` allows the Metrics Node to reach the port.

The first convergence of a Node installs the exporter package and cAdvisor. This normally happens during `node:add`. Later reconciles only verify them. These requests reconcile Metrics before they finish: `node:add`, `node:remove`, role changes, exporter preference changes, and the Instance and Route events that [service metrics](/reference/service-metrics#lifecycle) lists.

The node exporter and cAdvisor ports are open to every WireGuard peer, not only to the Metrics Node. Each Node keeps the rule `orbit:wireguard-members`, which admits every member on its WireGuard address, and no deny rule guards ports 9100 and 9102. WireGuard membership is the security boundary, so this is intended.

### Exporter degradation

An exporter reconcile failure does not demote an active Node or fail `node:add`; the affected Node stays active and its Metrics state is degraded with the error code. A service-metrics snapshot or convergence failure also degrades only the affected Node. The Gateway records the failing step and error code for that Node rather than failing the operation that triggered the reconcile. The degraded Node is skipped while the reconcile continues on the other Nodes. A later reconcile retries it and clears its degradation when reconciliation succeeds.

`orbit metrics:status` reports the fleet summary in `reconcile_status` and `reconcile_error_code`, and reports the affected Node's `degraded_reason: reconcile_failed` and exact `degraded_error_code` in its exporter row. The [Node provisioning](/reference/node-provisioning#converge-an-existing-node) page documents the exporter provisioning outcomes.

## cAdvisor

The node exporter reports systemd unit state, but not the CPU and memory of each unit. So each selected Node also runs cAdvisor `v0.60.5`. Orbit verifies its SHA-256 checksum before install. cAdvisor reads cgroups, so it covers systemd and Docker Processes. `GET /api/v1/processes` reads CPU and memory from it.

cAdvisor runs as the static binary `/usr/local/bin/orbit-cadvisor` under `orbit-cadvisor.service`, with `Restart=always`. The UFW rule `orbit:metrics-cadvisor` allows the Metrics Node to reach port 9102. Enabling or disabling the exporter on a Node does the same to cAdvisor. Disabling removes the unit, the rule, and the binary.

cAdvisor collects only CPU and memory. Its flags disable every other metric kind and drop Docker container labels, because an unfiltered cAdvisor adds thousands of series per Node. A Docker Process's series uses its container name, `orbit-process-{id}-{name}`. A systemd Process's series uses its unit name, `orbit-process-{id}-{name}.service`. A Process without a series reports null CPU and memory, never zero.

## Service metrics

[Service metrics](/reference/service-metrics) collects Caddy traffic and PHP-FPM capacity on selected Nodes. Instance and Route changes trigger a fleet reconcile to update those services and the Prometheus targets.

If a Node's service-metrics snapshot or convergence fails, the Gateway records that Node as degraded with the failing step and error code. The operation that triggered the reconcile continues. For example, an Instance removal on another Node does not fail because service metrics cannot reconcile on this Node. The Gateway skips the degraded Node and continues reconciling the rest of the fleet. It retries the Node on a later reconcile and clears the degradation when reconciliation succeeds. Inspect the Node's exporter row in `orbit metrics:status` as described under [Exporter degradation](/reference/metrics#exporter-degradation).

Service degradation belongs to service metrics. Exporter and cAdvisor snapshots do not clear it. The Gateway clears it only after that Node's service reconciliation and publication succeed. Step and error-code updates are atomic. If the operation loses a Node lock, reconciliation stops and reports `node.lock_lost`. This failure affects the whole operation, not only service metrics on one Node.

## Process runtime status

A Process list reads each Process's status from the Gateway's [view of the Node agents](/reference/node-agent#gateway-view) first. For a Process whose Node has no fresh view, it reads Prometheus in one query.

In Prometheus, a systemd Process takes its state from `node_systemd_unit_state`. A missing unit is `inactive`. cAdvisor reports only running containers, so a Docker Process with a series is `running` and one without is `exited`. The Gateway caches this answer for 10 seconds.

These statuses describe sampled runtime state, not health. A systemd Process can appear `active` between crashes. [Doctor](/cli/doctor#what-each-family-checks) reports `process.crash_loop` from the unit's auto-restart sub-state or an increasing restart count when the Process is desired running; the [Processes reference](/reference/processes-and-schedules#process-runtime-state) owns that rule. Doctor does not need the Metrics role to inspect this evidence on the Node.

After the Gateway starts, stops, or restarts a Process, it reads the new status from the Node and broadcasts `process.status`. Lists show that status for 30 seconds, ahead of the view and Prometheus. When Prometheus cannot answer, the list asks each remaining Node over SSH and does not cache the answer.

## Grafana access

Open Grafana at `https://metrics.orbit` from the active Gateway Node, or from an active WireGuard peer with an access grant to the Gateway Node. A grant to only the Metrics Node is not enough. Grafana then asks for its own login.

Private DNS answers `metrics.orbit` with the Gateway's WireGuard address. The Gateway's Caddy presents an Orbit CA certificate. Before each request, Caddy calls `GET /api/v1/metrics/grafana/authorize` with the connection address. It ignores forwarding and identity headers from the caller. Caddy runs that check in the current Gateway release: it resolves the stable Gateway application path for each request, as the Gateway site does ([Runtime handoff](/reference/gateway-recovery#runtime-handoff)). It proxies admitted traffic over WireGuard to Grafana on the Metrics Node.

The Gateway refuses an unknown, inactive, ungranted, or public caller. It also refuses when it cannot establish the caller's identity or authority. The Gateway reloads its Caddy when a Node is removed, or when an access grant to the Gateway Node is removed, while a Metrics role exists. Later requests fail, and open streaming connections close.

The Gateway's own origin also serves Grafana under `/grafana/`, behind the same check. The web app reads Node metrics there. On the Metrics Node, the rules `orbit:metrics-grafana-upstream` and `orbit:metrics-grafana-isolation` admit only the Gateway to port 3000. This applies also when the Gateway and Metrics roles share a Node.

## Authorization

Every Metrics route, reads included, needs exactly one active Gateway Node. So does every `node:role:add` and `node:role:remove` call for `metrics`. The Gateway authorizes the caller against the Gateway Node. The Gateway Node itself passes. Every other caller needs an access grant to the Gateway Node, or it gets `node_access.required` (HTTP 403). A grant to only the Metrics Node or an exporter Node is not enough.

With no active Gateway, or more than one, every caller gets `node_access.required` from these routes.

## Credentials

The Grafana user is `admin`. The first convergence generates a random password. The Gateway stores it encrypted in the Metrics Node's settings. Later convergence reuses it.

`orbit metrics:credentials --reset` creates a pending password, applies it through Grafana's API, verifies it, and then makes it active. A failed reset keeps the pending password. The next reset first checks whether Grafana accepts it, and applies it only when Grafana does not. Orbit never returns a password that it has not verified.

One lock per Metrics Node covers password creation, verified reads, resets, and purge. A competing request waits within its deadline. It then reads the finished state or returns HTTP 409 `metrics.credentials_busy` with no changes. Credential responses use `Cache-Control: no-store`. No other response contains a password.

## Disable and purge

`orbit metrics:disable` removes the role. After it, the Metrics Node runs neither container. The Gateway removes `/etc/orbit/metrics`, both Grafana firewall rules, and every exporter drop-in and exporter rule on eligible Nodes. It removes the `metrics.orbit` site, certificate, and DNS record. It keeps these items, so a later `metrics:enable` reuses them:

- the volumes `orbit-metrics-prometheus-data` and `orbit-metrics-grafana-data`;
- the stored Grafana password;
- Docker and the installed packages; and
- every exporter preference.

`--purge-data` also deletes both volumes and the active and pending passwords, and nothing else. The Gateway deletes a volume only when it has the Orbit ownership labels. When a volume lacks them, the Gateway deletes neither volume nor password. It leaves the assignment failed at `remove:baseline` and answers `node_role.remove_failed` (HTTP 502).

Metrics convergence and removal [build the Gateway's Caddyfile](/reference/caddy-configuration#node-caddy-build). The build renders `metrics.orbit` while the role converges, is active, or failed at a `converge:` step. It keeps every other site. Convergence publishes the certificate before the build. Removal converges private DNS, builds, removes the Grafana firewall rules, and then removes the certificate when no Metrics site renders.

The result reports `publication`:

| Value | Meaning |
| --- | --- |
| `cleaned` | The Gateway removed the `metrics.orbit` site, certificate, and DNS record. |
| `uncleaned` | No single active Gateway existed when the step ran. The site, certificate, and DNS record stay on the Gateway host for an operator to remove. |

An `uncleaned` removal still removes the exporters, both containers, and `/etc/orbit/metrics`. It removes both Grafana firewall rules when the Node's firewall answers.

`orbit node:remove <node> --offline --force` removes the role from an unreachable Metrics Node without SSH. With a single active Gateway, it removes the Gateway publication and reports `cleaned`. Without one, it reports `uncleaned`. The containers, volumes, `/etc/orbit/metrics`, and the Grafana firewall rules stay on the unreachable machine.

## Read Node metrics

[`orbit node:metrics`](/cli/node#orbit-nodemetrics) and the [web app](/reference/web-app) read CPU, memory, swap, load, uptime, pressure, and disk from the role's Prometheus. The Gateway reads through Grafana's datasource proxy with the stored Grafana credential. It runs four instant PromQL queries for the Node's exporter. Each rate uses a 40-second window. This window survives a dropped scrape and still shows a spike.

A reading is at most 10 seconds old. The web app refreshes node metrics every 10 seconds through the Gateway's `/grafana/` path. A Node without an active exporter or without samples answers `node.metrics_unreachable` (HTTP 502). Without a Metrics assignment, the read fails with `metrics.assignment_missing` (HTTP 409).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One Metrics Node with plain Docker containers

One Node keeps placement and private publication simple. Standalone containers keep development and upgrades simple. Do not add Docker Swarm, Compose, custom images, or a registry for this role. The cost is no high availability and no rolling Metrics updates.

### cAdvisor as a binary

Exporter Nodes, such as the Gateway Node, often have no Docker. So cAdvisor runs as a static binary, not as a container.

### The Gateway Node as the authority

The Gateway owns the Metrics boundary. Authorization against the Gateway Node gives one stable authority, even when Metrics runs on another Node. A grant on the Metrics Node would split that authority.

### A Gateway check in front of Grafana

WireGuard membership alone does not prove Gateway authority. A Grafana login alone does not prove Orbit authorization. So the Gateway checks each request, and Grafana keeps its own login as a second gate. Direct Grafana access for every peer is a rejected alternative.

### Exporters only on managed Nodes

A Node without Gateway SSH management has no service-management contract with the Gateway. An exporter preference must not turn such a Node into a managed Node. A roleless Linux Node that the Gateway manages can still host an exporter. A managed Mac cannot host the Linux exporter in this slice. Enabling an exporter or assigning the Metrics role on macOS returns `metrics.platform_unsupported` (HTTP 422) before SSH.

### The credential in Metrics settings

Metrics keeps its own encrypted settings. Orbit has no generic credential system. The pending password lets a failed reset resume, and Orbit never returns an unverified password.

### Removal that keeps data

Volumes and the password survive a removal, so re-enabling is safe. Data deletion needs explicit `--purge-data` intent and proven ownership.

### Node metrics through Grafana

Prometheus stays unpublished. Grafana's datasource proxy reuses the publication and the password that already exist. A new Prometheus host name is a rejected alternative. A Gateway endpoint that reads Prometheus over SSH is also rejected, because it adds an SSH round trip to each refresh.
