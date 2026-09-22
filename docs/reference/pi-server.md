---
title: "Pi server"
description: "How the Pi server runs Pi agent sessions on a Node for the Gateway's pi driver: configuration, sign-in, API, thread states, and restart behavior."
---

# Pi server

The Pi server runs [Pi](https://github.com/earendil-works/pi) coding-agent sessions on a Node. The Gateway's `pi` driver creates sessions, starts turns, and reads transcripts through it. [ADR 0116](/decisions/0116-run-task-implementers-on-pi) records the decision. The source lives in `apps/pi-server`.

## Configure the server

The server reads command-line flags first, then the environment. Orbit's systemd Processes pass only arguments, so every setting has a flag. The token has only a file flag, because arguments are visible to other users and are stored in the Process definition. The server refuses to start without a bind address and a token.

| Flag | Variable | Default | Meaning |
| --- | --- | --- | --- |
| `--host` | `PI_SERVER_HOST` | required | Address to listen on, normally the Node's WireGuard address |
| `--port` | `PI_SERVER_PORT` | `3774` | Port to listen on |
| `--token-file` | `PI_SERVER_TOKEN_FILE` or `PI_SERVER_TOKEN` | required | Bearer token of at least 32 characters |
| `--agent-dir` | `PI_SERVER_AGENT_DIR` | `~/.pi/agent` | Pi's directory, which holds the provider sign-ins |
| `--session-dir` | `PI_SERVER_SESSION_DIR` | `<agent dir>/orbit-sessions` | Session transcripts and Orbit's session records |
| `--workspace-root`, repeatable | `PI_SERVER_WORKSPACE_ROOTS`, colon-separated | none | When set, a session's workspace must be inside one of these directories |
| `--allow-api-keys` | `PI_SERVER_ALLOW_API_KEYS=1` | off | Allow providers signed in with an API key |
| `--idle-unload-seconds` | `PI_SERVER_IDLE_UNLOAD_SECONDS` | `900` | Unload a session with no turn and no stream after this many seconds |

The Gateway reaches the server at `http://{wireguard_ip}:{ORBIT_PI_PORT}` with the token in `ORBIT_PI_TOKEN`. `ORBIT_PI_PORT` defaults to `3774`. A Node record that carries `pi` settings uses its own `token` and optional `url` instead and never falls back to `ORBIT_PI_TOKEN`. The Node settings API does not set `pi`.

## Sign in to a provider

Sign in once per provider on each Node, as the user that runs the server. Run `pi-server login openai-codex` or `pi-server login xai`, choose device-code sign-in, and approve the code from any browser. Pi stores the credential in its own directory, where the server reads it. The Gateway never receives it. `pi-server login anthropic` is refused.

By default the server accepts only subscription sign-ins, such as ChatGPT for Codex models. A provider signed in with an API key, including a key in the server's environment, is not available until `PI_SERVER_ALLOW_API_KEYS=1` is set. Claude models do not run on Pi: Anthropic permits subscription sign-ins only in its own applications.

## Install on a Node

Build the binary on a workstation with `bun run build:linux` in `apps/pi-server`. It writes `dist/pi-server-linux-x64` and `dist/pi-server-linux-arm64`. Each is one file that includes the Bun runtime.

On the Node, as the managed runtime user:

1. Copy the binary for the Node's architecture to `~/.local/bin/pi-server` and make it executable.
2. Write a random token of at least 32 characters to `~/.pi/agent/orbit-token` with mode `600`. Set the same value as `ORBIT_PI_TOKEN` on the Gateway.
3. Run `pi-server login openai-codex` and complete the device-code sign-in.

Then register the managed Process from a machine with the Orbit CLI. Replace the address with the Node's WireGuard address and the root with its apps path:

```bash
orbit process:create pi-server --node=NODE --command=/home/orbit/.local/bin/pi-server --command=serve --command=--host=10.44.0.9 --command=--token-file=/home/orbit/.pi/agent/orbit-token --command=--workspace-root=/srv/orbit/apps --restart=always --keep-alive --start
```

The `pi` driver accepts the Node once this Process is active with desired state `running`. `GET /capabilities` lists the signed-in models. Select Pi for implementers with `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER=pi` on the Gateway.

## API

Every route requires `Authorization: Bearer <token>`. Errors return `{"error": {"code", "message"}}`.

| Route | Result |
| --- | --- |
| `GET /capabilities` | Pi version and the models this Node can run, as `provider/model` |
| `POST /sessions` | Creates a session from `id`, `cwd`, `model`, `thinkingLevel`, and an optional `appendSystemPrompt`. Repeating the same create returns `200` |
| `POST /sessions/{id}/messages` | Starts a turn from `key` and `text`. A repeated key returns `200` with `duplicate: true` and starts no turn |
| `POST /sessions/{id}/interrupt` | Aborts the active turn |
| `GET /sessions/{id}` | Snapshot: session settings, state, error, turn ID, transcript entries, and cumulative token usage |
| `GET /sessions/{id}/stream` | Newline-delimited JSON: a snapshot, then `entry` and `state` events, with a `heartbeat` every 15 seconds |

| Error code | Status | Meaning |
| --- | --- | --- |
| `unauthorized` | 401 | Missing or wrong token |
| `session_not_found` | 404 | No session has this ID |
| `invalid_request` | 422 | A field is missing or invalid, or the workspace is outside the allowed roots |
| `model_unavailable` | 422 | The model is unknown, or its provider is not signed in with an accepted method |
| `session_exists` | 409 | A session with this ID has different settings |
| `turn_active` | 409 | The session is already working on a turn |

The turn ID is the key of the latest accepted send. The Gateway uses a new key for each turn and reuses it when it retries a send.

## Thread states

The server reports one of the Orbit thread states from its own evidence. The Gateway stores that state on the thread.

| Pi evidence | State |
| --- | --- |
| No completed turn | `idle` |
| A turn was accepted and has not settled | `working` |
| The last assistant message stopped normally | `done` |
| The turn ended with a provider error, an interruption, or the output limit | `failed`, with the error |
| The server restarted while the turn was active | `failed`, with a restart error |

Pi has no approvals, so a Pi thread never asks for input. A new turn replaces a `done` or `failed` state with `working`.

## Restarts

Transcripts persist as Pi session files. After a restart, the server reloads a session when it is first used. Accepted send keys persist, so a repeated send after a restart still starts no turn. The server records a turn as active before it starts and clears the record when the turn settles. A record still marked active after a restart reports `failed`.
