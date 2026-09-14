# Herdr sessions

This page tells an operator how Orbit manages a named Herdr session on a managed Node and how Commander requests a private receive-only observation grant for one recorded pane.

Orbit owns Process lifecycle, private DNS, Caddy, Orbit certificate authority (CA) Transport Layer Security (TLS), WireGuard publication, the receive-only WebSocket adapter, and short-lived observation grants. Herdr owns terminal rendering and the local snapshot and terminal-observation commands that the adapter consumes.

## Create a session

The operator first enables the optional Herdr command extension, then names one session on one managed Node and records the Unix identity that must own the Herdr server and socket.

```bash
orbit extension:enable herdr
orbit herdr:session:create commander-tasks --node=beast --user=nckrtl --publish-observer
```

The Gateway composes one node-targeted systemd Process for the headless Herdr server, publishes a private receive-only observer when `--publish-observer` is set, and stores the session identity. The Process runs as the Node's managed runtime user. The `--user` value must match that recorded user; the Gateway refuses a mismatch before it creates a Process.

Observer publication requires a successful session snapshot with Herdr observe protocol 22. The Gateway repeats this capability check before it issues each grant. Inspection failure or protocol drift fails closed and produces no observation grant.

The CLI hides every `herdr:*` command and refuses its execution while the extension is disabled. Extension state is local to the operator machine. Trusted services can use the authenticated Gateway API without enabling the CLI extension.

Repeating an identical create returns the same session. The Gateway does not replace or restart a compatible running Herdr server. A changed user, Process specification, or observer listen address with the same Node and session name returns `herdr.session_conflict` and leaves the running server in place.

## Stored identity

The API and PHP software development kit (SDK) return this identity for each session.

| Field | Meaning |
| --- | --- |
| `node` | Registered Node name |
| `session` | Named Herdr session |
| `user` | Recorded Unix identity that owns the server and socket |
| `process_id` | Node-targeted Process ID |
| `observer_url` | Private `wss://` URL when publication succeeded |
| `status` | Session lifecycle status |
| `herdr_version` | Observed Herdr version, or null when inspection cannot read it |
| `protocol` | Observed Herdr observe protocol number, or null when inspection cannot read it |
| `health.process` | Distinct Process health |
| `health.listener` | Distinct observer publication and listen health |
| `health.session` | Distinct Herdr-session identity health |

Workspace, pane, and terminal IDs stay scoped to this Node and named session. Orbit does not persist terminal output.

## Observe a pane

Commander authorizes its own task view, then asks Orbit for a grant scoped to one Node, one named session, one recorded pane, and one expected terminal. The grant permits only `terminal.observe` for bounded viewport dimensions, one HTTPS browser origin, a short expiration, and a one-time nonce.

```bash
orbit herdr:observe commander-tasks \
  --node=beast \
  --pane=w1:p1 \
  --terminal=term-abc \
  --cols=120 \
  --rows=40 \
  --origin=https://tasks.commander.test
```

The Gateway returns a private WebSocket Secure (WSS) URL that includes the grant. The browser never receives Secure Shell (SSH) credentials, a general Orbit API token, arbitrary pane discovery, or an input-capable connection. The Node adapter validates the Orbit signature against a local public key set that Orbit publishes. It also requires the browser's `Origin` header to match the grant and confirms that the pane still owns the expected terminal before it streams.

Reconnection asks Orbit for a new grant. Herdr emits a fresh complete terminal frame followed by incremental American National Standards Institute (ANSI) frames. Orbit validates and forwards those frames without storing terminal output.

## Commands

The CLI sends each operation through the Gateway.

| Command | Result |
| --- | --- |
| `orbit extension:enable herdr` | Enable and reveal the local Herdr CLI extension. |
| `orbit extension:disable herdr` | Disable and hide the local Herdr CLI extension. |
| `orbit herdr:session:create NAME --node=ID-or-name --user=USER [--publish-observer]` | Create or ensure one named session on a managed Node. |
| `orbit herdr:session:list --node=ID-or-name` | List sessions on one Node with identity and health. |
| `orbit herdr:session:show NAME --node=ID-or-name` | Show one session. |
| `orbit herdr:session:restart NAME --node=ID-or-name [--handoff]` | Restart the server explicitly, using Herdr live handoff when the operator asks and Herdr reports support. |
| `orbit herdr:session:destroy NAME --node=ID-or-name [--accept-termination]` | Destroy the session, Process, and private observer. |
| `orbit herdr:observe NAME --node=ID-or-name --pane=PANE --terminal=TERMINAL --cols=COLS --rows=ROWS --origin=HTTPS-ORIGIN` | Issue one short-lived receive-only grant. |

`--node` accepts a positive Node ID or the registered Node name. Every command also accepts `--json`.

## Lifecycle

The Gateway keeps a compatible running Herdr server in place unless the operator asks for an explicit restart or removal. The listener runs as the Unix user that owns the Herdr session and socket.

| Event | Result |
| --- | --- |
| Tool install or update of the Herdr package | Changes package files only. It does not start, stop, or restart a managed session. |
| Ordinary session ensure or Doctor inspection | Leaves a compatible running server in place. |
| `herdr:session:restart --handoff` | The current Herdr command contract reports no supported handoff, so the Gateway restarts the owned Process. |
| Removal | Inspects live panes first. The Gateway refuses `herdr.session_in_use` while any pane is live unless the operator passes `--accept-termination`. |
| Observer publication failure | Records listener health. It does not destroy or restart the Herdr session. |
| Node removal | The Gateway refuses `node:remove` while the Node owns a Herdr session. [Node provisioning](node-provisioning.md#remove-a-node) owns that guard. |
| Offline decommissioning of an unreachable Node | Deletes those session and Process records without remote cleanup. |

## Doctor

Doctor inspects Herdr as the explicit `herdr` family. It reports Process, listener, and session findings as distinct codes and never starts, stops, or republishes a session.

| Code | Kind | Meaning |
| --- | --- | --- |
| `herdr.process_unhealthy` | Drift | The owned Process is missing or does not match its desired runtime state. |
| `herdr.listener_unhealthy` | Drift | The private observer is unpublished or failed while the session remains. |
| `herdr.session_unhealthy` | Drift | Herdr identity, protocol, or session status does not match the stored session. |
| `herdr.inspection_failed` | Unverifiable | Doctor could not complete a required Herdr inspection. |
| `herdr.node_unreachable` | Unverifiable | The Node cannot be inspected. |

```bash
orbit doctor --node=<node-id> --family=herdr
```

## API

Authorized callers use these resources.

| Method and path | Result |
| --- | --- |
| `GET /api/v1/herdr/sessions?node_id=ID` | List sessions on one Node. |
| `POST /api/v1/herdr/sessions` | Create or ensure one session. |
| `GET /api/v1/herdr/sessions/{session}` | Show one session. |
| `POST /api/v1/herdr/sessions/{session}/restart` | Restart one session. |
| `DELETE /api/v1/herdr/sessions/{session}` | Remove one session. |
| `POST /api/v1/herdr/sessions/{session}/observation-grants` | Issue one observation grant. |
| `GET /.well-known/jwks.json` | Publish the Orbit observe signing keys. |

Create accepts `node_id`, `session`, `user`, and `publish_observer`. Restart accepts `handoff`. Destroy accepts `accept_termination`. A grant accepts `pane`, `terminal`, `cols`, `rows`, and `origin`.

## Failure codes

The Gateway returns these Herdr-specific codes.

| Code | Result |
| --- | --- |
| `herdr.user_mismatch` | `--user` does not match the Node's managed runtime user. |
| `herdr.session_conflict` | The named session exists with a different specification. |
| `herdr.session_in_use` | Removal refused because live panes exist. |
| `herdr.node_unavailable` | The Node is inactive, unmanaged, or unreachable for mutation. |
| `herdr.grant_invalid` | The grant request is missing a required pane, terminal, viewport bound, or HTTPS browser origin. |
| `herdr.inspection_failed` | Orbit could not verify the Herdr session before observer publication or grant issuance. |
| `herdr.observer_unsupported` | The installed Herdr observe protocol is not 22. |
| `herdr.observer_failed` | Observer publication failed; the Herdr session remains. |

## Herdr observe contract

Orbit records this Herdr command contract. A Herdr CLI change that keeps the same commands and output fields remains compatible.

### Herdr commands

The managed server command is `herdr --session {name} server`. Orbit uses the Homebrew Core executable `/home/linuxbrew/.linuxbrew/bin/herdr`.

Session inspection is `herdr --session {name} api snapshot`. Orbit reads the `session_snapshot` result and its `version`, `protocol`, and `panes` fields. Each pane provides `pane_id` and `terminal_id`. Herdr exposes no supported live-handoff command in this contract.

The adapter invokes only `herdr --session {name} terminal session observe {terminal} --cols {cols} --rows {rows}` for streaming. It binds to loopback, runs as the session's Unix user in a hardened systemd service, and is reachable through the Orbit-managed Caddy site. Any browser application data closes the connection; the adapter never invokes a Herdr input or control command.

### Grant enforcement

An observation grant is a JSON Web Token (JWT) that Orbit signs with RS256 and expires after 60 seconds. The token carries `iss=orbit-gateway`, `aud=herdr-observe`, `sub=terminal.observe`, `node`, `session`, `pane`, `terminal`, `cols`, `rows`, `origin`, `jti`, `iat`, and `exp`. The adapter rejects input, control, arbitrary pane selection, an expired grant, a wrong Node or session, an origin mismatch, a pane-to-terminal mismatch, and a reused `jti`. The mode-0600 replay store survives adapter restarts until each grant expires.

## Incus coverage

Orbit proves this Commander contract with Gateway, CLI, and standalone adapter tests: two managed Nodes, two named sessions, scoped origin-bound grants, complete and incremental terminal frames, replay rejection across adapter restarts, and the absence of SSH or input capability. This repository does not run a disposable Incus topology that starts real Herdr sessions and streams panes into a browser.

[App processes and schedules](app-processes-and-schedules.md) owns the node-targeted Process primitive. [Tools](tools.md) owns Herdr package installation. [Private DNS](private-dns.md) owns hostname answers. [ADR 0069](../decisions/0069-allow-node-process-targets.md) owns Process targeting. [ADR 0004](../decisions/0004-verify-only-doctor-boundary.md) owns Doctor.
