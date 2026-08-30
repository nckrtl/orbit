# Metrics role

Orbit provides private fleet host metrics through one mutable `metrics` role.
The role runs Prometheus and Grafana as standalone Docker containers. It runs
`prometheus-node-exporter` under systemd on selected active nodes. Orbit does
not use Docker Swarm, Compose, public `Process` rows, or public `Tool` rows for
this role. The containers use Docker host networking: Prometheus listens only
on loopback on the Metrics node, and Grafana listens only on the node's
WireGuard address, with UFW governing both ports. Both containers log to a
rotating `json-file` driver, capped at 10 MB per file and three files.

## Placement and recovery

At most one `metrics` assignment can exist. It can share a node with
`gateway`, `vpn`, `app-dev`, or `app-prod`.

Enable Metrics on an active node:

```text
orbit metrics:enable [node]
```

The node is a numeric ID or a registered node name. The command prompts for a
node only in an interactive terminal. JSON and other non-interactive calls must
supply the node.

Use the generic role command to retry a failed assignment:

```text
orbit node:role:add <node> metrics --converge
```

There is no separate Metrics convergence command.

Each container is converged against its own configuration. Prometheus is bound
to `prometheus.yml`, Grafana to `grafana.ini` and its provisioning files. So a
fleet change replaces Prometheus alone and leaves live Grafana sessions and
in-flight queries running. The provisioned dashboard is bound to neither: the
Grafana file provider reloads it from the bind mount while Grafana runs. The
Grafana administrator password is in no hash.

## Exporter selection

Orbit selects exporters from active role state and the stored node preference:

| Preference | Node state | Result |
| --- | --- | --- |
| absent | has an active role | selected |
| absent | roleless | excluded |
| enabled | any active node | selected |
| disabled | non-Metrics node | excluded |
| any value | current Metrics node | selected |

Set an explicit preference with:

```text
orbit metrics:exporter:enable <node>
orbit metrics:exporter:disable <node>
```

Preferences survive role changes and periods when Metrics is disabled. Orbit
refuses to disable the current Metrics node exporter.

A selected node runs the packaged `prometheus-node-exporter` unit with the
Orbit drop-in at
`/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf`. The
drop-in binds the exporter to the node's WireGuard address on port 9100, and a
Metrics-owned UFW rule opens that port to the Metrics node only.

## Private access and credentials

Grafana is available at `https://metrics.orbit`. Private DNS resolves this name
to the active Gateway node. Gateway Caddy terminates the Orbit-CA certificate
and proxies to Grafana over WireGuard. Prometheus has no fleet endpoint.

All focused Metrics API calls authorize against the active Gateway node. A
non-Gateway caller needs a directed access grant to that node. Access to the
Metrics node or an exporter node is not sufficient.

Show or reset the verified Grafana administrator credential:

```text
orbit metrics:credentials
orbit metrics:credentials --reset
```

Orbit stores the active and pending passwords as encrypted node settings. A
reset reuses a pending password after partial failure and returns it only after
Grafana verifies it. Credential responses use `Cache-Control: no-store`.

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

Interactive disable asks for confirmation. Non-interactive disable requires
`--force`. Purge also requires `--force`.

Normal disable removes owned containers, generated configuration, private
publication, exporter configuration, and Metrics firewall rules. It preserves
named volumes, the active credential, installed packages, Docker, and exporter
preferences.

Purge also removes proven Metrics volumes and active or pending credentials.
It never removes shared packages, Docker, unrelated containers, user files, or
exporter preferences. Ambiguous ownership fails closed.

A normal disable reports `"publication": "cleaned"`.

### Disable with no single active Gateway

**Disable is not reachable in this state today.** Every Metrics route
authorizes against the one active Gateway: `MetricsController` carries a
class-level `RequiresNodeAccess(ServingNode::Gateway)`, and
`ServingNodeResolver::roleMutation()` sends `node:role:remove metrics` to the
same resolver. With no active Gateway, or with more than one, both refuse
before the request reaches the role manager, and `orbit metrics:disable
--force` fails with `node_access.required` from any caller, including the
Gateway node itself. NCK-116 decides how that is resolved.

The role removal itself no longer requires a Gateway. Once a caller can reach
it, Orbit removes the Metrics node's runtime, exporters and Grafana upstream
firewall rule, then reports `"publication": "uncleaned"`, and the CLI prints a
warning. The `metrics.orbit` route, its certificate, and its DNS record stay on
the Gateway host until an operator removes them.

## API surface

| Method | Path | Purpose |
| --- | --- | --- |
| `POST` | `/api/v1/metrics` | Enable the role. |
| `DELETE` | `/api/v1/metrics` | Disable the role. |
| `GET` | `/api/v1/metrics/status` | Read status. |
| `GET` | `/api/v1/metrics/credentials` | Read verified credentials. |
| `POST` | `/api/v1/metrics/credentials/reset` | Reset credentials. |
| `PUT` | `/api/v1/metrics/exporters/{node}` | Enable one exporter. |
| `DELETE` | `/api/v1/metrics/exporters/{node}` | Disable one exporter. |
