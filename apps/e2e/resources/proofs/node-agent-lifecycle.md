# Node agent lifecycle proof record

This is the shell-only Incus proof for ADRs 0128–0130 and [Node agent](../../../../docs/reference/node-agent.md) / [Node agent channels](../../../../docs/reference/events.mdx#node-agent-channels). The transcript excerpts below were recorded during TASK-58 discovery leases. Timestamps are UTC from the guest clocks. Commands are run on the topology Gateway unless a Node is named.

## Environment and limitations

- Feature worktree HEAD: `f7a1cc7fe3a93f80cdf2d46b8f744fd17dfdd589`; the topology mounted the dirty worktree overlay containing the viewer and E2E proof-input-policy changes.
- Topology profile: `gateway_app-dev_app-prod`; `websocket` role active on `gateway`.
- Checks of root-owned paths must run with `sudo`: app-prod's `/etc/orbit` is `0700 root`, so an `orbit`-user check reports its contents absent.
- Proof leases used: `6a2856288a520ffc2ae01899887c0ef8`, `6b98161d13a00f7f52a6dd6a3ac7d130`, `93d79871484bffc4485daebc63957976`, `54d35030a81c5fd24963bc350e8f220d`, and `8c294db884e216c8815dd7b45dae4517`. Every lease was released after the proof.
- Reverb WebSocket upgrade succeeded on the refreshed snapshot; the reusable viewer connected without a manual Caddy patch. The earlier empty-200 failure was the topology Caddy wildcard-bind bug, fixed on main by #631. The unrelated private-DNS gap on Gateway/VPN Nodes remains assigned to ORB-96; it is not fixed here. Agents and viewers for the final successful realtime run were on app-dev/app-prod, which resolved the service names. No `/etc/hosts` workaround was used on the final snapshot.
- The first removal attempt on an older snapshot failed during app instance cleanup because the E2E internal-TLS fixture wrote a conflicting Caddy global block. #634 (`b34743da`) fixed the harness. This was not a node-agent finding. The successful removal proofs below used a fresh snapshot containing the fix.

## Install, no-restart converge, Doctor, and footprint

Commands on Gateway:

```sh
orbit node:add gateway
systemctl show orbit-agent -p ActiveState -p ActiveEnterTimestamp
orbit node:add gateway
systemctl show orbit-agent -p ActiveEnterTimestamp
orbit doctor --node=1 --family=node
```

Recorded output, 2026-09-24 UTC:

```text
06:04:14 ActiveState=active
06:04:14 ActiveEnterTimestamp=Thu 2026-09-24 06:04:14 UTC
06:04:56 second node:add started; completed 06:05:14
06:05:14 ActiveEnterTimestamp=Thu 2026-09-24 06:04:14 UTC
Doctor: node gateway / family node / healthy; drift=0; unverifiable=0
```

Footprint on that installed agent:

```text
ps -o pid,rss,args -C orbit-agent
PID   RSS COMMAND
4144  6936 /usr/local/bin/orbit-agent

stat -c '%s bytes' /usr/local/bin/orbit-agent
11946696 bytes
```

The pinned release was `0.1.0`, x86_64 SHA-256 `5241c052051273eb77b0e6459ce638b2c208121b5257298ec9122ed4e79402f4`.

All Doctor drift findings were observed and repaired with `node:add`/role converge:

| Condition introduced on a disposable Node | Doctor finding | Repair/result |
| --- | --- | --- |
| Stop `orbit-agent` on gateway | `node.agent_inactive` | `node:add gateway`; service active |
| Replace the gateway binary with `/bin/false` | `node.agent_outdated` | `node:add gateway`; pinned checksum restored |
| Move the gateway binary out of `/usr/local/bin/orbit-agent` | `node.agent_missing` | `node:add gateway`; binary restored |

These three codes were exercised on an Instance-free gateway in lease `8c294db884e216c8815dd7b45dae4517`, so each was repaired by `node:add` rather than role converge. Timestamped commands/results:

```text
2026-09-24T11:12:10Z systemctl stop orbit-agent
Doctor JSON: node.agent_inactive (drift); next node:add gateway succeeded
2026-09-24T11:12:39Z replaced /usr/local/bin/orbit-agent with /bin/false
Doctor JSON: node.agent_outdated (drift); next node:add gateway succeeded
moved /usr/local/bin/orbit-agent to /tmp/orbit-agent-missing
Doctor JSON: node.agent_missing (drift); next node:add gateway succeeded
Doctor after repair: gateway/node healthy; drift=0; unverifiable=0
```

On the initial install, second `node:add` kept `ActiveEnterTimestamp` unchanged. For inactive/outdated/missing, the Doctor JSON included the exact issue code and one check; after repair it reported healthy with no drift.

## Join, snapshot, live Process events, viewer identity

The reusable client is `apps/agent/scripts/node-agent-viewer.ts`. It uses the topology Node's source address to authorize at `/api/v1/broadcasting/auth` and speaks the Pusher protocol over the named Reverb WebSocket. Example used on app-dev:

```sh
bun apps/agent/scripts/node-agent-viewer.ts \
  --gateway=https://gateway.orbit \
  --reverb=wss://reverb.orbit \
  --key="$REALTIME_KEY" \
  --node=2
```

The app-dev viewer joined `presence-node.2`, saw `agent.2`, and printed its initial Docker snapshot. Representative timestamped output:

```text
2026-09-24T06:35:37.287Z subscribed presence-node.2 ... "agent.2":{"kind":"agent","node_id":2,"version":"0.1.0"}
2026-09-24T06:35:37.288Z client-snapshot user_id=agent.2 ... "docker":"available" ...
2026-09-24T06:36:52.790Z client-process user_id=agent.2 ... "name":"orbit-process-5-agent-proof-systemd","runtime_status":"active"
2026-09-24T06:37:10.857Z client-process user_id=agent.2 ... "name":"orbit-process-5-agent-proof-systemd","runtime_status":"failed"
2026-09-24T06:37:31.672Z client-process user_id=agent.2 ... "name":"orbit-process-3-e2e-valkey","runtime_status":"exited"
2026-09-24T06:37:51.889Z client-process user_id=agent.2 ... "name":"orbit-process-99-agent-proof-oom","runtime_status":"running"
2026-09-24T06:37:52.282Z client-process user_id=agent.2 ... "name":"orbit-process-99-agent-proof-oom","runtime_status":"exited"
2026-09-24T06:38:09.009Z client-process user_id=agent.2 ... "name":"orbit-process-5-agent-proof-systemd","runtime_status":"active"
```

Actions and observations:

- Created a disposable systemd Process running `/usr/bin/sleep 300`; sent `SIGKILL` to its `orbit-process-5-agent-proof-systemd.service`. Viewer received `failed` about 0.4 seconds after the action timestamp.
- Ran `docker stop orbit-process-3-e2e-valkey`; viewer received `exited` within about 0.8 seconds. Docker was restarted after the check.
- Ran an `ubuntu:26.04` container with a 16 MiB memory limit that allocated memory; `docker inspect` reported `exited oom=true`. Viewer saw `running` then `exited` within about 0.4 seconds between the two event timestamps.
- Ran `orbit process:start 5`; viewer saw the unit return to `active` within about a second of the CLI start completing.

A second viewer authenticated from app-dev and requested a viewer client event. The observer received Reverb's sender identity:

```text
2026-09-24T06:48:59.475Z client-viewer-proof user_id=viewer.781206029.875227640 {"at":"2026-09-24T06:48:59.475Z","probe":"viewer-client"}
```

Agent authorization for another Node's channel was rejected from app-dev:

```text
POST /api/v1/agent/broadcasting/auth
socket_id=123.456&channel_name=presence-node.3&version=1.2.3
HTTP/2 403
{"error":{"code":"agent.channel_forbidden","message":"Agent may only join its own presence channel."}}
```

## Disconnect and Docker socket recovery

On app-dev, stopping the service closed presence immediately:

```text
2026-09-24T06:38:15.772Z systemctl stop orbit-agent
2026-09-24T06:38:16.092Z member_removed user_id=agent.2 {"user_id":"agent.2"}
```

The heartbeat/drop and recovery proof was on app-prod with the viewer on app-dev. An OUTPUT firewall rule dropped the WireGuard peer UDP traffic to the Gateway endpoint. The viewer log's receive-time prefix stopped at `06:51:35.182Z`, while subsequent heartbeat frames were not logged until `06:54:02.867Z`, after restoring the peer path at `06:53:34Z`. The resumed frames carried agent `at` times spanning `06:51:40Z` through `06:54:00Z`; thus the viewer saw a roughly 147-second receive gap across the hard drop and recovery. Heartbeats continued afterward. This confirms heartbeats stop reaching the viewer during the drop and recover when traffic returns; Reverb itself does not detect a dead connection on this interval.

Docker socket discovery on app-prod:

```text
2026-09-24T06:56:41.404Z client-snapshot user_id=agent.3 {"docker":"absent", ...}
2026-09-24T06:57:11.420Z client-snapshot user_id=agent.3 {"docker":"absent", ...}
2026-09-24T06:57:31.424Z client-snapshot user_id=agent.3 {"docker":"available", ...}
```

The first absent snapshot followed stopping the service while its socket could still activate it; stopping both `docker.socket` and `docker.service` gave a stable absent result. Starting both again produced `available` on the socket check.

## Online and offline removal

Online removal was performed on fresh TASK-58 topology `93d79871484bffc4485daebc63957976`, after confirming `orbit instance:list` showed ID 2 was only the disposable `e2e-prod` Instance. The Instance and its test Route were removed through the Orbit CLI, then its fixture-only `app-prod` and `ingress` roles were removed through the CLI. At `2026-09-24T10:34:31Z`:

```sh
orbit node:remove 3 --force --json
```

```json
{"id":3,"name":"app-prod","removed":true,"wireguard_peer_removed":true,"dns_records_removed":true,"degradation":null,"roles_shed":[],"retained_on_node":[],"follow_up":null}
```

The viewer saw the agent leave (`member_removed`/socket close). A topology-shell check at `10:35:31Z` confirmed:

```text
ABSENT:/etc/systemd/system/orbit-agent.service
ABSENT:/usr/local/bin/orbit-agent
ABSENT:/etc/orbit/agent
inactive
```

That check ran as `orbit`. On app-prod `/etc/orbit` is `0700 root`, so an `orbit`-user `test -e` reports `/etc/orbit/agent` absent whether or not it exists; only the unit and binary results above are valid. The reviewer repeated the online removal on TASK-58 lease `1b9e123a343f593df45616d06a11c206` and checked every path as root with `sudo`:

```text
2026-09-24T11:20:50Z before: PRESENT unit, PRESENT binary, PRESENT /etc/orbit/agent, active
2026-09-24T11:20:50Z orbit node:remove 3 --force --json
{"id":3,"name":"app-prod","removed":true,"wireguard_peer_removed":true,"dns_records_removed":true,"degradation":null,"roles_shed":[],"retained_on_node":[],"follow_up":null}
2026-09-24T11:21:05Z after: ABSENT unit, ABSENT binary, ABSENT /etc/orbit/agent, inactive
```

Offline removal was completed on fresh TASK-58 topology `54d35030a81c5fd24963bc350e8f220d`. After removing only the disposable `e2e-prod` Instance by CLI, `ssh.socket` and `ssh.service` were stopped on app-prod. `orbit doctor --node=3 --family=node --json` then reported `node.ssh_unreachable`. The still-assigned `app-prod` and `ingress` roles were left for `node:remove` to shed offline. At `2026-09-24T11:02:33Z`:

```sh
orbit node:remove 3 --offline --force --json
```

```json
{"id":3,"name":"app-prod","removed":true,"wireguard_peer_removed":true,"dns_records_removed":true,"degradation":"unreachable","roles_shed":["app-prod","ingress"],"retained_on_node":["Caddy site configuration and certificates for the app-prod role","Managed PHP-FPM pools and instance checkouts","Metrics node exporter package, its Orbit systemd drop-in and its firewall rule for port 9100","Node agent, its systemd unit, binary, and /etc/orbit/agent configuration","Orbit firewall rules for the app-prod role"],"follow_up":"Discard this node, or clear only the leftovers listed above by hand once it is reachable."}
```

The topology shell subsequently confirmed the retained agent unit and binary were still present and the service active (`PID 3015`, `RSS 7624 KiB`, binary SHA-256 equal to the pin). The offline command did not change the machine, and the residue response names the Node agent and its configuration as retained on the Node. The same shell check reported `/etc/orbit/agent` absent because it ran as `orbit`, which cannot traverse app-prod's `0700` `/etc/orbit`; it is not a discrepancy. As root, a converged app-prod holds `/etc/orbit/agent/config.toml` and `ca.pem`, and offline removal leaves them in place.

## Closeout

All discovery attempts were released with `bin/e2e-topology release TASK-58`. The initial `composer check` passed; the apps/e2e policy fix had 81 affected tests pass and `composer check` pass. `bun build apps/agent/scripts/node-agent-viewer.ts --target=bun` and `git diff --check` also passed. The proof ran the viewer from `apps/web/scripts/`; the reviewer moved it to `apps/agent/scripts/`, because the web app's browser type check does not cover Bun scripts.
