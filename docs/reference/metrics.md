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

The HTTP access model does not change here. `MetricsController` still carries
a class-level `RequiresNodeAccess(ServingNode::Gateway)`, and
`ServingNodeResolver::roleMutation()` still sends `node:role:remove metrics` to
the same resolver. With no active Gateway, or with more than one, both refuse
before the request reaches the role manager, and every Metrics route and
`orbit metrics:disable --force` fail with `node_access.required` from any
caller, including the Gateway node itself. This is deliberate: the active
Gateway peer is the implicit authority for the role.

The recovery is node-local. Orbit publishes
`/usr/local/sbin/orbit-metrics-uninstall` on every node it converges the
Metrics exporter onto, rendered from the same constants the remote executors
mutate, and removes it again when the exporter is removed through Orbit. An
operator standing on the node runs it directly:

```text
sudo /usr/local/sbin/orbit-metrics-uninstall
sudo /usr/local/sbin/orbit-metrics-uninstall --force
sudo /usr/local/sbin/orbit-metrics-uninstall --dry-run
```

`--force` skips the confirmation prompt, for non-interactive use. `--dry-run`
changes nothing.

Both the prompt and `--dry-run` name every resource, one per line, before
anything happens. The plan is computed once and the removal acts only on it,
so the operator confirms the list that runs.

The script proves ownership with the same evidence the remote path uses: the
`com.orbit.managed=metrics` container and volume labels, re-read immediately
before each removal; the `/etc/orbit/metrics/.orbit-owner` marker reading
`metrics`; and the drop-in's `# Managed by Orbit: metrics` first line.

A firewall rule needs more than its comment. The script requires exactly one
rule carrying the Orbit comment, matched at the end of the line so
`orbit:metrics-node-exporter-v2` is never claimed, and that rule must be the
rule Orbit writes: `allow in on orbit`, `tcp`, destination this node's
WireGuard address, port `9100` or `3000`, and a single IPv4 source. Anything
else is drift, which the Gateway also refuses. A hand-edited rule that kept
the comment is therefore reported, not deleted.

The destination check needs the `orbit` interface to hold an IPv4 address.
When it does not, the escape does not refuse: an isolated node with a dead
WireGuard interface is squarely the case this tool exists for, and the
destination is the one field that cannot be checked either way, so refusing on
it strands the operator without buying safety. The escape then proves
everything else — the anchored Orbit comment, `ALLOW IN`, on `orbit`, `tcp`,
the expected port, a single IPv4 destination and a single IPv4 source — and
removes on that basis. It says so in the plan, before the confirmation prompt,
under `Proved with less evidence than usual:`, marks each such rule
`(destination address not verified)` in the list the operator approves, and
repeats both in the final report.

Anything without a proof is reported, never removed, and the script exits `3`.

The script discovers its own scope rather than being told. An exporter-only
node loses the drop-in, the exporter service, and the 9100 UFW rule. A Metrics
node additionally loses the labelled containers, the labelled volumes
(including their data), everything Orbit generated under `/etc/orbit/metrics`,
and the Grafana upstream 3000 UFW rule.

| Exit code | Meaning |
| --- | --- |
| `0` | Clean: nothing Orbit owns is left, or everything owned was removed. A node with no Metrics footprint exits `0` even when UFW is inactive or absent. |
| `2` | Usage error, or the operator declined the confirmation prompt. |
| `3` | Something was refused or survived removal. |
| `4` | Not running as root. |

The script leaves some things in place on purpose. It never removes the
`prometheus-node-exporter` package: Orbit installs it but cannot prove it owns
it, and the remote removal path does not remove it either. The cleanup stops
and disables the service and removes Orbit's drop-in, so the package is left
inert, and the script prints the exact `apt-get purge` command instead. It leaves Docker and any
container Orbit did not label. It leaves itself in place, so the cleanup stays
re-runnable and verifiable.

Some things can only be cleaned up on the Gateway. The `metrics.orbit` route,
its certificate, and its private DNS record stay on the Gateway host. The
Metrics role assignment, exporter preferences, and stored credentials stay in
the Gateway database. The script reports both.

Re-enabling is ordinary registration. The escape leaves the role assignment in
the Gateway database, so once a Gateway is reachable an operator takes the
stale role off and adds it again:

```text
orbit metrics:disable --force
orbit metrics:enable app-dev
```

Disable copes with a node the escape already emptied: it finds nothing to
remove and reports `"publication": "cleaned"`. Enable then re-publishes the
configuration, the containers, the volumes, the firewall rules and the escape
itself, and converges the role back to healthy. The escape does not need to be
reversible in place.

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
