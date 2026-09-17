---
title: "herdr"
description: "Run named Herdr terminal sessions on a managed Node and issue short-lived, receive-only observation grants for one pane."
commands:
  - herdr:session:create
  - herdr:session:list
  - herdr:session:show
  - herdr:session:restart
  - herdr:session:destroy
  - herdr:session:adopt
  - herdr:observe
---

Herdr is a headless terminal server. Orbit runs one named Herdr session per Node and session name as a Node-targeted Process, publishes a private receive-only observer for it, and issues short-lived grants that let a browser watch one recorded pane. Orbit owns the Process, private DNS, Caddy, certificates, and grants; Herdr owns terminal rendering.

The [Herdr sessions reference](/reference/herdr-sessions) owns the stored identity, lifecycle, API, and grant contract that these commands follow.

## Before you start

The family is an extension. Its commands are hidden and refuse to run with `extension.disabled` until you enable them on the operator machine:

```bash
orbit extension:enable herdr
```

The Gateway also requires the `herdr` Tool installed on the target Node under the active Homebrew manager before it creates, restarts, or grants observation of a session. Install it once per Node:

```bash
orbit tool:install herdr --node=<node-id> --manager=brew
```

Without that Tool record the Gateway returns `herdr.tool_not_installed` before any runtime work. Session reads and destruction stay available for diagnosis and cleanup.

## Commands

| Command | Result |
| --- | --- |
| [`herdr:session:create`](#orbit-herdrsessioncreate) | Create or ensure one named Herdr session on a managed Node. |
| [`herdr:session:list`](#orbit-herdrsessionlist) | List named Herdr sessions on one Node. |
| [`herdr:session:show`](#orbit-herdrsessionshow) | Show one named Herdr session. |
| [`herdr:session:restart`](#orbit-herdrsessionrestart) | Restart one named Herdr session. |
| [`herdr:session:destroy`](#orbit-herdrsessiondestroy) | Destroy one session, its Process, and its private observer. |
| [`herdr:session:adopt`](#orbit-herdrsessionadopt) | Adopt an existing Herdr session for observation without owning its lifecycle. |
| [`herdr:observe`](#orbit-herdrobserve) | Issue one observation grant that is short-lived and receive-only. |

Every command accepts `--json`. `--node` accepts a numeric Node ID or the registered Node name. A session name has 1 through 48 lowercase letters, digits, or hyphens, starts and ends with a letter or digit, and is otherwise refused with `herdr.session_invalid`. A name the Node does not know fails with `herdr.session_not_found`.

{/* commands */}

## Failure codes

| Code | Result |
| --- | --- |
| `extension.disabled` | The `herdr` extension is disabled on this machine. |
| `herdr.session_invalid` | The session name is outside the accepted form. |
| `herdr.session_not_found` | The Node has no session with that name. |
| `herdr.user_mismatch` | `--user` does not match the Node's managed runtime user. |
| `herdr.session_conflict` | The named session exists with a different specification. |
| `herdr.session_in_use` | Removal refused because live panes exist. |
| `herdr.node_unavailable` | The Node is inactive, unmanaged, or unreachable for mutation. |
| `herdr.tool_not_installed` | The Node has no installed managed `herdr` Tool under its active Homebrew manager. |
| `herdr.grant_invalid` | The grant request lacks a required pane, terminal, viewport bound, or HTTPS origin. |
| `herdr.inspection_failed` | Orbit could not verify the session before observer publication or grant issuance. |
| `herdr.observer_unsupported` | The installed Herdr observe protocol is not 22. |
| `herdr.observer_failed` | Observer publication failed; the session remains. |

`orbit doctor --family=herdr` reports `herdr.process_unhealthy`, `herdr.listener_unhealthy`, `herdr.session_unhealthy`, `herdr.inspection_failed`, and `herdr.node_unreachable` without starting, stopping, or republishing a session.

## Related

- [`extension`](/cli/extension) enables the family.
- [`tool`](/cli/tool) installs the `herdr` package on the Node.
- [`process`](/cli/process) owns the Node Process primitive that a session runs on.
- [`node`](/cli/node) refuses to remove a Node while it owns a Herdr session.
