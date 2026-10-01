---
title: "Pi server"
description: "How the Pi server runs Pi agent sessions on a Node for the Gateway's pi driver: configuration, sign-in, agent tools, API, thread states, and restart behavior."
covers:
  - apps/pi-server/**
  - apps/gateway/app/Infrastructure/Tasks/Pi/{PiConnection,PiModel,PiNodeEligibility}.php
---

# Pi server

The Pi server runs [Pi](https://github.com/earendil-works/pi) coding-agent sessions on a Node. The Gateway's `pi` driver creates sessions, starts turns, and reads transcripts through it. The [Tasks reference](/reference/tasks#drivers) describes the driver. The source lives in `apps/pi-server`.

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
| `--allow-api-keys` | `PI_SERVER_ALLOW_API_KEYS=1` | off | Allow every provider signed in with an API key |
| `--allow-provider`, repeatable | `PI_SERVER_ALLOW_PROVIDERS`, comma-separated | none | Allow one named provider although Pi sees an API key, such as a CLIProxyAPI endpoint |
| `--idle-unload-seconds` | `PI_SERVER_IDLE_UNLOAD_SECONDS` | `900` | Unload a session with no turn and no stream after this many seconds |

The Gateway reaches the server at `http://{wireguard_ip}:{ORBIT_PI_PORT}` with the token in `ORBIT_PI_TOKEN`. `ORBIT_PI_PORT` defaults to `3774`.

## Connect through CLIProxyAPI

A Node can reuse the subscription accounts that CLIProxyAPI already holds, as Codex does. Pi then needs no sign-in of its own. Add the endpoint as a provider in `~/.pi/agent/models.json`, with mode `600`:

```json
{
  "providers": {
    "cliproxyapi": {
      "baseUrl": "http://10.44.0.3:8317/v1",
      "api": "openai-responses",
      "apiKey": "!COMMAND THAT PRINTS THE CLIPROXYAPI KEY",
      "models": [
        { "id": "gpt-5.6-luna", "reasoning": true, "contextWindow": 272000, "maxTokens": 128000 }
      ]
    }
  }
}
```

A leading `!` runs the command at request time, so the key stays in the file or secret store that Codex already uses. List each model the Node should run; `GET /v1/models` on CLIProxyAPI shows the available IDs. Start the server with `--allow-provider=cliproxyapi`, because Pi sees an API key for this provider. Set `ORBIT_PI_PROVIDER=cliproxyapi` on the Gateway so plain model names, such as `gpt-5.6-luna`, use it.

The Gateway's `pi` driver refuses every Claude model, also through CLIProxyAPI. See [Claude is unavailable for task agents](#claude-is-unavailable-for-task-agents).

## Sign in to a provider

Sign in once per provider on each Node, as the user that runs the server. Run `pi-server login openai-codex` or `pi-server login xai`, choose device-code sign-in, and approve the code from any browser. Pi stores the credential in its own directory, where the server reads it. The Gateway never receives it. `pi-server login anthropic` is refused.

By default the server accepts only subscription sign-ins, such as ChatGPT for Codex models. A provider signed in with an API key, including a key in the server's environment, is not available until `PI_SERVER_ALLOW_API_KEYS=1` is set or `--allow-provider` names it.

## Install on a Node

Build the binary on a workstation with `bun run build:linux` in `apps/pi-server`. It writes `dist/pi-server-linux-x64` and `dist/pi-server-linux-arm64`. Each is one file that includes the Bun runtime.

On the Node, as the managed runtime user:

1. Copy the binary for the Node's architecture to `~/.local/bin/pi-server` and make it executable.
2. Write a random token of at least 32 characters to `~/.pi/agent/orbit-token` with mode `600`. Set the same value as `ORBIT_PI_TOKEN` on the Gateway.
3. [Connect through CLIProxyAPI](#connect-through-cliproxyapi), or run `pi-server login openai-codex` and complete the device-code sign-in.

Then register the managed Process from a machine with the Orbit CLI. Replace the address with the Node's WireGuard address and the root with its apps path:

```bash
orbit process:create pi-server --node=NODE --command=/home/orbit/.local/bin/pi-server --command=serve --command=--host=10.44.0.9 --command=--token-file=/home/orbit/.pi/agent/orbit-token --command=--workspace-root=/srv/orbit/apps --restart=always --keep-alive --start
```

Add `--command=--allow-provider=cliproxyapi` when the Node uses CLIProxyAPI. The `pi` driver accepts the Node once this Process is active with desired state `running`. `GET /capabilities` lists the signed-in models. Select Pi for implementers with `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER=pi` on the Gateway, and for reviewers with `ORBIT_TASKS_REVIEWER_AGENT_DRIVER=pi`. Both default to `pi`.

## Agent tools

A Pi session has Pi's `read`, `bash`, `edit`, and `write` tools and one Orbit tool, `search_docs`. `search_docs` searches the Laravel ecosystem documentation through [Laravel Boost](https://github.com/laravel/boost). Results match the package versions installed in the Project, so the agent uses framework features that exist in those versions. Pi has no MCP client; the tool sends only the MCP messages this search needs.

| Input | Required | Meaning |
| --- | --- | --- |
| `queries` | yes | Search queries, such as `["queue middleware", "rate limit"]` |
| `packages` | no | Limit the search to these packages, such as `laravel/framework` or `pestphp/pest` |
| `project` | no | Laravel app directory relative to the session workspace, such as `apps/gateway` |

The tool chooses the Laravel app in this order:

1. The `project` directory, when the agent passes it.
2. The session workspace, when it has an `artisan` file.
3. The only `apps/*` directory with an `artisan` file and `vendor/laravel/boost`.

When several `apps/*` directories match, the tool asks for `project`.

The app needs `laravel/boost` installed, so run `composer install` in it first. The tool runs `php artisan boost:mcp` in the app with `php` from the server's `PATH`. It calls Boost's `search-docs` tool over MCP on standard input and output and returns the text. Boost runs only in a local or debug app, so the tool sets `APP_DEBUG=true` when the server's environment does not set it. The server's `PI_SERVER_*` variables stay out of that process. Boost fetches the documentation from `boost.laravel.com`, so the Node needs outbound HTTPS.

The tool stops Boost after the answer, after 60 seconds, or when the turn is interrupted. A failed search returns an error result to the agent. The error names the cause, such as no Laravel app, Boost not installed or not enabled, or a timeout.

## Large tool output

A `read` or `bash` result larger than 8 KiB does not enter the model context. The server writes the full text to a file and stores a short notice in the session. The stream shows that notice. `edit`, `write`, `search_docs`, and an image `read` are unchanged.

8 KiB is 8,192 UTF-8 bytes. `read` measures the selected lines when the call sets `offset` or `limit`, and the whole file otherwise. `bash` measures stdout and stderr in the order the tool read them, without the exit line. A result of 8,192 bytes or fewer is returned in full. There is no line cap on that result.

The file is new, under the session workspace at `.git/orbit/tool-output/`. The server creates the directory with mode `0700` when `.git` is a directory. The file mode is `0600`. Its name starts with the tool, the session id, and the tool call id. A second result does not replace an earlier file. The file contains the measured text only. The notice uses the absolute path.

The notice is at most 8,192 bytes:

```text
<bytes> bytes, <lines> lines, saved to <absolute path>
<preview>
View more with the read tool using offset and limit, or grep on that file.
```

`read` previews the first 20 lines. `bash` previews the last 40. Lines split on newline. A final newline does not add a line. When the preview would make the notice larger than 8,192 bytes, whole lines drop from the end of a `read` preview or the start of a `bash` preview. A line is not split. The file keeps it. The first line then adds `Preview shows N lines.`

A `bash` notice ends with `Exit code: N` when the process exits, including `0`. An abort ends with `Command aborted`. A timeout ends with `Command timed out after N seconds`. A non-zero exit, an abort, and a timeout stay failed tool results. The failure text is the notice.

The files stay on the Node until the workspace clone is removed. Idle unload and a server restart leave them in place. They are not pushed and not copied off the Node. Git ignores paths inside `.git`, so they stay out of diffs and the review workspace tree. A workspace whose `.git` is not a directory returns the full text and writes no file.

Reading the saved file follows the same rule. A slice of 8,192 bytes or fewer returns in full. When the file cannot be written, the result is an error: the byte count, the line count, and the reason. The error does not include the output. A `bash` error ends with the same exit, abort, or timeout line as a notice.

The `read` and `bash` descriptions tell the model about this limit. They name `.git/orbit/tool-output/`, the path, the counts, and the short preview.

## API

Every route requires `Authorization: Bearer <token>`. Errors return `{"error": {"code", "message"}}`.

| Route | Result |
| --- | --- |
| `GET /capabilities` | Pi version and the models this Node can run, as `provider/model` |
| `POST /sessions` | Creates a session from `id`, `cwd`, `model`, `thinkingLevel`, and an optional `appendSystemPrompt`, and returns `201`. Repeating the same create returns `200` |
| `POST /sessions/{id}/messages` | Starts a turn from `key` and `text`, and returns `202`. A repeated key returns `200` with `duplicate: true` and starts no turn |
| `POST /sessions/{id}/interrupt` | Aborts the active turn and returns `202` |
| `GET /sessions/{id}` | Snapshot: session settings, state, error, turn ID, transcript entries, and [token usage](#token-usage) |
| `GET /sessions/{id}/stream` | Newline-delimited JSON: a snapshot or, with `run` and `after`, a resumed start; then `entry` and `state` events, with a `heartbeat` every 15 seconds. See [Stream](#stream) |

| Error code | Status | Meaning |
| --- | --- | --- |
| `unauthorized` | 401 | Missing or wrong token |
| `not_found` | 404 | No route matches the method and path |
| `session_not_found` | 404 | No session has this ID |
| `invalid_request` | 422 | A field is missing or invalid, or the workspace is outside the allowed roots |
| `model_unavailable` | 422 | The model is unknown, or its provider is not signed in with an accepted method |
| `session_exists` | 409 | A session with this ID has different settings |
| `turn_active` | 409 | The session is already working on a turn |

The turn ID is the key of the latest accepted send. The Gateway uses a new key for each turn and reuses it when it retries a send.

## Token usage

The snapshot and each stream `state` event include `usage`. The sums cover every assistant message that has numeric `input`, `output`, `cacheRead`, and `cacheWrite`. A message without that usage is not a call. [Thread token metrics](/reference/tasks#thread-token-metrics) describes how the Gateway stores these numbers on the agent thread.

| Field | Meaning |
| --- | --- |
| `input` | Prompt tokens that are neither a cache read nor a cache write. This field alone is not Orbit's uncached input |
| `output` | Output. A reasoning count on the message is already inside this number |
| `cacheRead` | Input read from cache. This is Orbit's cached input |
| `cacheWrite` | Input written to cache. A cache write is uncached input, so Orbit adds it to `input` |
| `total` | `input + output + cacheRead + cacheWrite` |
| `calls` | Assistant messages included in the sums |
| `peakContext` | Largest prompt on one call: `input + cacheRead + cacheWrite`. That is uncached input plus cached input. Output is excluded |

Orbit stores uncached input as `input + cacheWrite` and cached input as `cacheRead`. Per-call usage in the session file uses the same `input`, `cacheRead`, `cacheWrite`, and `output` fields. `totalTokens` on a call equals those four numbers added.

## Stream

Every stream event carries `run` and `sequence`. The run is a random ID that the server picks each time it loads a session. Sequence numbers start at 0 with each run and grow with each event. A transcript entry is streamed when Pi writes it. Pi writes an assistant message with a tool call when the call starts, and the tool result when the call ends.

Without a cursor, the stream starts with a `snapshot` event, the same as `GET /sessions/{id}`.

To resume, pass the last event the client received as `?run={run}&after={sequence}`. When the run is the session's current run and the sequence is not ahead of it, the stream starts with a `resumed` event:

```json
{"kind": "resumed", "run": "3f9c…", "sequence": 42, "session": {"id": "…"}, "context": []}
```

Then it sends the entries streamed after that sequence and the latest `state` event if it came after it, in sequence order. `context` lists the entries at or before the cursor whose tool calls have no result yet, so the client can name the results that follow.

The server sends a snapshot instead when the cursor is missing or malformed, names another run, or is ahead of the run. A cursor from before a restart or an idle unload names another run.

## Thread states

The server reports one of the Orbit thread states from its own evidence. The Gateway stores that state on the thread.

| Pi evidence | State |
| --- | --- |
| No completed turn | `idle` |
| A turn was accepted and has not settled | `working` |
| The last assistant message stopped normally | `done` |
| The turn ended with a provider error, an interruption, or the output limit | `failed`, with the error |
| The server restarted while the turn was active | `failed`, with error `The Pi server restarted during the turn.` |

Pi has no approvals, so a Pi thread never asks for input. A new turn changes a `done` or `failed` state to `working`.

## Restarts

Transcripts persist as Pi session files. After a restart, the server reloads a session when it is first used. Accepted send keys persist, so a repeated send after a restart still starts no turn. The server records a turn as active before it starts and clears the record when the turn settles. A record still marked active after a restart reports `failed` with the error `The Pi server restarted during the turn.` The Gateway resumes that turn. [Recover a Pi server restart](/reference/tasks#recover-a-pi-server-restart) states how.

## Roll out a new binary

Stop the Gateway scheduler before you replace `pi-server` on a Node. A restart kills every turn that is `working` on that server. Pausing first keeps those turns alive. [Recover a Pi server restart](/reference/tasks#recover-a-pi-server-restart) still heals a turn the wait missed.

1. Stop the Process that runs `php artisan schedule:work` in the Gateway checkout. Find its numeric id with [`process:list`](/cli/process#orbit-processlist). [`process:stop`](/cli/process#orbit-processstop) stops that unit.
2. On the host that runs the Gateway checkout, confirm that no process is running `artisan tasks:tick`.
3. Wait until no Pi session you will restart has live state `working`. Read that state from the Pi server, not from [`tasks:agents`](/cli/tasks#orbit-tasksagents).
4. On the Node, replace the binary the `pi-server` Process runs. Restart that Process with [`process:restart`](/cli/process#orbit-processrestart).
5. Start the scheduler Process again with [`process:start`](/cli/process#orbit-processstart).

[`process:stop`](/cli/process#orbit-processstop) returns when that unit is inactive. The cache lock is a different signal. `tasks:tick` holds `orbit:tasks:tick` for 300 seconds and releases the lock when the command returns. The lock can expire while that command is still running, so an expired lock is not proof the command has exited. Step 2 is that proof.

[`tasks:agents`](/cli/tasks#orbit-tasksagents) and `GET /api/v1/task-groups/{group}/agents` return the stored row. The tick writes that row. While the tick is paused, a finished turn can stay `working` there. Use the list only for the thread id, the driver, and `external_id`. [`tasks:list`](/cli/tasks#orbit-taskslist) with status `running`, then `reviewing`, names the tasks.

For a `pi` thread, `GET /sessions/{external_id}` on that Node is the live snapshot. Wait until `state` is not `working`. The first `snapshot` event on `GET /api/v1/task-groups/{group}/agents/{thread}/stream` is that same snapshot. The stream reads Pi and does not write the stored row. The [agent viewer](/reference/tasks#agent-viewer) shows it.

The [install steps](#install-on-a-node) copy the binary to `~/.local/bin/pi-server`. Replace the file that Process actually runs.

A turn still `working` at the restart fails with the restart error. The next tick resumes it, at most twice for that subtask. Do not post a resolution comment for that failure.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Claude is unavailable for task agents

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. So the `pi` driver refuses a Claude model, `pi-server login anthropic` refuses to sign in, and task agents have no other runtime that runs Claude. [ADR 0190](/decisions/0190-run-task-agents-on-pi-only) records that choice. Annotations still use the operator's T3 threads. They are not task agents.

### A long-lived server, not RPC over SSH

A Pi process started over SSH would live only as long as that connection, and a reconnect would lose the live event stream. Reading session files over SSH gives history without live events. So one server on the Node owns the sessions and serves snapshots and streams.

### The server owns the stream cursor

The server already holds the transcript and its order, so it resumes a stream after a cursor. A cursor in the Gateway would need a store of what each browser has seen. A snapshot on every connection would cost more with every conversation and every open tab.

### Large tool output goes to a file

A large tool result fills the model context, and each later call sends that text again. Cutting the result would lose the rest for good. So a large result goes to a file that a later turn can read. The file sits inside `.git`, so diffs and the review tree never include it. When the file cannot be written, the result is an error, because inlining it would bring back the cost.
