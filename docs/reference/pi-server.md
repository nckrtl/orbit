---
title: "Pi server"
description: "How the Pi server runs Pi agent sessions on a Node for the Gateway's pi driver: configuration, sign-in, the orbit-worker install, agent tools, API, thread states, and restart behavior."
covers:
  - apps/pi-server/**
  - apps/e2e/resources/proofs/orbit-worker{.sh,.php,-provider.ts}
  - apps/e2e/tests/Unit/E2E/OrbitWorkerProofRecoveryTest.php
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

Sign in once per provider on each Node, as the user that runs the server. On a Node that runs task agents, that user is `orbit-worker`. Run `pi-server login openai-codex` or `pi-server login xai`, choose device-code sign-in, and approve the code from any browser. Pi stores the credential in its own directory, where the server reads it. The Gateway never receives it. `pi-server login anthropic` is refused.

By default the server accepts only subscription sign-ins, such as ChatGPT for Codex models. A provider signed in with an API key, including a key in the server's environment, is not available until `PI_SERVER_ALLOW_API_KEYS=1` is set or `--allow-provider` names it.

## Host setup

Create `orbit-worker` on every Node that runs task agents, before [Install on a Node](#install-on-a-node). The Gateway does not create the account during `node:add`. The account separates agent programs from the managed user's SSH login and token-bearing Git commands. [One user for every task agent](#one-user-for-every-task-agent) explains the choice.

1. Create the user and group `orbit-worker`, with home `/home/orbit-worker` and shell `/bin/bash`. Give the user no password and no sudo. Do not add the user to the managed user's group.
2. Set `/home/orbit-worker` to mode `0700`.
3. Install the `acl` package when `setfacl` is missing.
4. Confirm `sudo -n -u orbit-worker -H true` works as the managed user. Node bootstrap already grants that user passwordless sudo.
5. Add `orbit-worker` to `incus-admin` only on a host where task agents run `incus`.

Set the managed home to mode `0700` when the apps root is outside it. Set it to mode `0711` when the apps root is inside it, so `orbit-worker` can traverse to the checkouts without listing the home. Private directories in that home, including `.ssh`, `.config`, and `.pi`, stay mode `0700`. [Limits](#limits) states what membership of `incus-admin` does to this boundary.

## Install on a Node

Build the binary on a workstation with `bun run build:linux` in `apps/pi-server`. It writes `dist/pi-server-linux-x64` and `dist/pi-server-linux-arm64`. Each is one file that includes the Bun runtime.

The files belong to `orbit-worker`. `orbit-worker` has no sudo, so the managed user runs these steps and `sudo -u orbit-worker -H` does the work inside that account. On a Node that does not already run Pi, step 2 also sets `ORBIT_PI_TOKEN` on the Gateway. On a Node that already runs Pi, leave that Gateway value unchanged. [Roll out orbit-worker on beast](#roll-out-orbit-worker-on-beast) sets it after the copied sessions have been checked.

1. Copy the binary for the Node's architecture to `/home/orbit-worker/.local/bin/pi-server` and make it executable. `sudo install -o orbit-worker -g orbit-worker -m 0755` writes it.
2. Write a random token of at least 32 characters to `/home/orbit-worker/.pi/agent/orbit-token` with mode `600`, as `orbit-worker`.
3. [Connect through CLIProxyAPI](#connect-through-cliproxyapi), or complete device-code sign-in as `orbit-worker`: `sudo -u orbit-worker -H /home/orbit-worker/.local/bin/pi-server login openai-codex`.

Then register the Process from a machine with the Orbit CLI. Replace the address with the Node's WireGuard address and the root with its apps path:

```bash
orbit process:create pi-server \
  --node=NODE \
  --user=orbit-worker \
  --working-directory=/home/orbit-worker \
  --command=/home/orbit-worker/.local/bin/pi-server \
  --command=serve \
  --command=--host=10.44.0.9 \
  --command=--token-file=/home/orbit-worker/.pi/agent/orbit-token \
  --command=--workspace-root=/srv/orbit/apps \
  --restart=always \
  --keep-alive \
  --start
```

`--user` is the [Process user](/reference/processes-and-schedules#owners). The default working directory is the selected account's home. The command sets `--working-directory` explicitly to make the install path clear. Add `--command=--allow-provider=cliproxyapi` when the Node uses CLIProxyAPI. The `pi` driver accepts the Node once this Process is active with desired state `running`. `GET /capabilities` lists the signed-in models. Select Pi for implementers with `ORBIT_TASKS_IMPLEMENTER_AGENT_DRIVER=pi` on the Gateway, and for reviewers with `ORBIT_TASKS_REVIEWER_AGENT_DRIVER=pi`. Both default to `pi`.

## Limits

Three limits bound what `orbit-worker` separates.

`incus-admin` is root-equivalent. A member can start a privileged container, read any home, and observe another user's process. Homes at mode `0700` are the policy for a worker who is not in that group. They are not a hard wall on a host where the worker is in the group. beast adds `orbit-worker` to `incus-admin` so task agents can run `incus`.

An agent is a process of the Pi server and shares its user. It can read `/home/orbit-worker/.pi/agent/orbit-token` and the provider sign-in in that home. The design does not give each agent a separate user. The GitHub token is a different secret: the agent does not receive it. [What the App does not cover](/reference/github-app#what-the-app-does-not-cover) states that enforcement.

The candidate gate runs as the managed user. The baseline and handoff checks run programs that the workspace names, including code an agent wrote. Those programs can read the managed user's home and use its sudo. `orbit-worker` separates the agent, not the code the agent leaves for the check. [The candidate gate runs as the managed user](#the-candidate-gate-runs-as-the-managed-user) explains why.

## Prove the account on Incus

Run the [orbit-worker proof script](https://github.com/nckrtl/orbit/blob/main/apps/e2e/resources/proofs/orbit-worker.sh) from the task workspace on a fresh [allocated Incus lease](/reference/incus-topologies#task-workspace-clones). Replace `TASK-820` with the issue named by your branch:

```bash
bin/e2e-topology acquire TASK-820 .
bash apps/e2e/resources/proofs/orbit-worker.sh TASK-820
bin/e2e-topology release TASK-820
```

The script creates the account by the host setup above and registers a real `pi-server` Process with `--user=orbit-worker`. A deterministic local model response makes Pi run one bash tool call. That call refuses readable managed homes, sudo access, or SSH access as the managed user; writes a workspace file; tests its contents; and runs `incus list` against a daemon inside app-dev.

The Gateway's real task components provision the checkout, run the baseline and handoff checks as the managed user, read its turn receipt, commit its file, and remove the workspace. The proof also reads the committed blob and checks that the checkout remains managed-owned while the file belongs to the worker.

The proof driver calls the task components directly. It does not exercise scheduler review policy, a live model, or GitHub publication. Its disposable monorepo Project declares the repository root as `.` and uses no Route. It installs Incus only inside the disposable guest and never exposes the harness host's socket. Membership of `incus-admin` still has the [root-equivalent limit](#limits); the denied home, sudo, and SSH checks do not prove isolation against that power.

Every proof step uses `--record` labels. `worker-agent-and-gateway-commit` contains the actual Pi tool result, its five `WORKER_*` markers, the handoff check, and the new commit SHA. `worker-commit-readback` verifies the blob and author. `worker-gateway-removal`, `worker-checkout-removal-audit`, `worker-account-cleanup`, and `worker-final-audit` check the database, checkout, scoped Git trust, account, processes, and fixture files after cleanup.

An unexpected result exits nonzero. A cleanup failure keeps the recorded state for diagnosis rather than claiming success. The final Process-list audit runs before state deletion, so a failed read or assertion preserves the recovery file. Repeat the proof on a fresh lease, not by adopting leftover fixtures.

## Roll out orbit-worker on beast

beast is the Node that runs Pi for Orbit's tasks, and task agents there run `incus`. Use the same cutover on any Node that already runs `pi-server` as the managed user. Deploy the Gateway that grants the workspace ACL, runs teardown as `orbit-worker`, and isolates token-bearing `git` before this cutover.

Set `ORBIT_TASKS_WORKER_USER=orbit-worker` on the Gateway to enable checkout ACLs. A Node without that account keeps its existing checkout access during rollout. An agent cannot write a checkout until the ACL exists.

Follow [Roll out a new binary](#roll-out-a-new-binary) until no Pi session you will restart is `working`. Leave the scheduler stopped, and stop the `pi-server` Process. Confirm no `pi-server` process remains.

Save the installed spec and the Gateway token before [Install on a Node](#install-on-a-node). `orbit process:list --node=NODE --json` lists Node Processes. `process:show` does not: it shows a Project definition and requires `--project`. Keep the object whose `name` is `pi-server`, including `id`, `user`, `working_directory`, `runtime_config`, `restart_policy`, and `keep_alive`. A null `user` is the managed user. The command and its token-file path are in `runtime_config`. Record the current Gateway `ORBIT_PI_TOKEN` beside that object. Leave the old token file in place.

Create the account with [Host setup](#host-setup), and on beast add `orbit-worker` to `incus-admin`. Install the binary, the new token file, and the provider sign-in under `/home/orbit-worker`. Do not change `ORBIT_PI_TOKEN` in that install.

Apply the ACL to each existing development checkout as the managed user. The command is the same recursive grant prepare uses, including `.git`, because `git commit` creates `index.lock` in that directory. Then add that checkout's absolute path to `safe.directory` in `orbit-worker`'s global Git config. Git 2.55 ignores the key in the checkout's own config, and the ACL does not change the directory owner. Do not set `*`. The example uses `orbit`. Substitute the managed user and the checkout path:

```bash
setfacl -R -m u:orbit-worker:rwX,u:orbit:rwX -m d:u:orbit-worker:rwX,d:u:orbit:rwX -- /srv/orbit/apps/PROJECT/CHECKOUT
sudo -u orbit-worker -H git config --global --add safe.directory /srv/orbit/apps/PROJECT/CHECKOUT
```

Copy primary-checkout registrations before any task teardown runs as `orbit-worker`. [Primary registration](/reference/instance-setup#primary-registration) is the procedure. A registration left only in the managed user's home is invisible to the helper, and the helper then leaves the bridge in place.

Agents that run Incus proofs also need the primary's `.e2e` directory. It holds the host locks, the topology snapshot state, and the scenario runs. Grant it the same recursive ACL as the primary's `.git`. When the primary is inside the managed home, give `orbit-worker` traverse access on the home and on the primary with `setfacl -m u:orbit-worker:x`. Do not widen their modes.

Confirm a private directory of the managed home, such as `.ssh`, is mode `0700`. When the apps root is inside that home, set the home to `0711`. When the apps root is outside it, set the home to `0700`.

### Move existing sessions

The default session directory is `<agent dir>/orbit-sessions`. For the managed user that is `/home/orbit/.pi/agent/orbit-sessions`. The new Process uses `/home/orbit-worker/.pi/agent/orbit-sessions`. The server does not move the files. Each session is one `<id>.orbit.json` record and one `<timestamp>_<id>.jsonl` transcript. The Gateway's `external_id` is that `<id>`.

The spec and `ORBIT_PI_TOKEN` were saved before install. Do not destroy the Process, and do not change `ORBIT_PI_TOKEN`, until the session copy has been checked.

Copy while `pi-server` is stopped. On the same filesystem, copy the `*.orbit.json` and `*.jsonl` files into `/home/orbit-worker/.pi/agent/orbit-sessions.migrate`. Leave every other file behind. Set the directory to mode `0700` and the files to mode `0600`, owned by `orbit-worker:orbit-worker`. When the destination `orbit-sessions` already contains a file, stop and do not merge over it.

Rename the staging directory to `orbit-sessions` only after the copy is complete. A rename on the same filesystem is one replacement. When the copy is interrupted, delete the staging directory and copy again. The source directory stays in place.

Check the copy before the Process is destroyed. From [`tasks:agents`](/cli/tasks#orbit-tasksagents), take each `pi` thread whose task is not completed or cancelled, and skip an `external_id` that starts with `pending:`. For every other id, the destination has exactly one `<id>.orbit.json` and exactly one file ending in `_<id>.jsonl`. Each file's size and SHA-256 match the source. A missing id, a second transcript, or a checksum mismatch stops the cutover.

When the copy or a checksum fails, delete the staging directory and leave the source. Start the stopped Process. It still exists. Install did not change `ORBIT_PI_TOKEN`, so the Gateway token still matches that Process. `GET /sessions/{external_id}` for one id already stored on that server returns the session. Start the scheduler only after that read. This rollback is available only before destroy.

When the copy matches, destroy the old Process by its saved `id`. [`process:list`](/cli/process#orbit-processlist) `--node=NODE --json` then has no `name` of `pi-server`. Create the replacement with `--user=orbit-worker` and the paths under `/home/orbit-worker`. Create has no update. An error or a lost response does not mean the name is free.

Create saves the row before it installs the unit. When that install fails, the row remains and its status is `failed`. A lost response can also hide a row whose status is `provisioning` or `active`. Read [`process:list`](/cli/process#orbit-processlist) `--node=NODE --json` after every unsuccessful create and after every lost response.

When that list shows the replacement `active` and `user` is `orbit-worker`, set `ORBIT_PI_TOKEN` to the token in `/home/orbit-worker/.pi/agent/orbit-token`, then start the new Process. Any other status uses the rollback below, and the Gateway token stays unchanged.

Do not start the scheduler yet. `GET /sessions/{external_id}` for every id checked above returns the session, not `session_not_found` and not an authentication failure. Start the scheduler with [`process:start`](/cli/process#orbit-processstart) only after those reads. Confirm a session can create a file in a task workspace, and `orbit-worker` cannot read `/home/orbit/.ssh`. Keep the old session directory and the old token file until those reads succeed. Deleting the old binary, the old token, and the old session directory is a separate step after that.

After the old Process has been destroyed, start does not bring it back. Stop a running replacement. This list is the authority, not the create response. When `pi-server` is present, in any status, destroy that `id`. That includes `failed` and `provisioning`. A create of the saved name while that record exists returns `process.name_taken` and changes nothing. List again and confirm the name is absent before creating the saved spec.

Restore `ORBIT_PI_TOKEN` to the value saved before install. Create the old Process from the saved object: the same command, working directory, user, restart policy, and keep-alive, with the old token file. That Process reads the old session directory. Do not start the scheduler until `GET /sessions/{external_id}` succeeds against it. A Gateway token that still names the new file fails authentication against the old server.

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

The file is new, under the session workspace at `.git/orbit/tool-output/`. The server creates the directory with mode `0770` when `.git` is a directory and the process can create it. The file mode is `0660`. Its name starts with the tool, the session id, and the tool call id. A second result does not replace an earlier file. The file contains the measured text only. The notice uses the absolute path.

Those group bits keep the workspace ACL in force, so the managed user and `orbit-worker` can both read the file and delete it. The server does not change the mode of a directory it does not own.

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

The [install steps](#install-on-a-node) copy the binary to `/home/orbit-worker/.local/bin/pi-server`. Replace the file that Process actually runs.

A turn still `working` at the restart fails with the restart error. The next tick resumes it, at most twice for that subtask. Do not post a resolution comment for that failure.

## Test worker file access

Run `bun run test` in `apps/pi-server` on Linux with `setfacl` and passwordless `sudo -n -u nobody` available. The worker ACL test writes tool output as `nobody` inside a temporary checkout owned by the test runner, then checks that the checkout owner can read and rewrite it.

The test copies the Bun runtime and the tool-output module into its temporary fixture. It invokes the runtime by absolute path with a restricted `PATH` and uses the fixture as its working directory. This keeps the worker independent of sudo's executable search path and of access to the test runner's home or source checkout. Cleanup removes both copies and the checkout; the test does not change home permissions or sudo policy.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Claude is unavailable for task agents

Anthropic permits Claude subscription credentials only in its own applications, also when a proxy such as CLIProxyAPI relays them. So the `pi` driver refuses a Claude model, `pi-server login anthropic` refuses to sign in, and task agents have no other runtime that runs Claude. [Tasks: Task agents run on Pi](/reference/tasks#task-agents-run-on-pi) explains that choice. Annotations still use the operator's T3 threads. They are not task agents.

### A long-lived server, not RPC over SSH

A Pi process started over SSH would live only as long as that connection, and a reconnect would lose the live event stream. Reading session files over SSH gives history without live events. So one server on the Node owns the sessions and serves snapshots and streams.

### The server owns the stream cursor

The server already holds the transcript and its order, so it resumes a stream after a cursor. A cursor in the Gateway would need a store of what each browser has seen. A snapshot on every connection would cost more with every conversation and every open tab.

### Large tool output goes to a file

A large tool result fills the model context, and each later call sends that text again. Cutting the result would lose the rest for good. So a large result goes to a file that a later turn can read. The file sits inside `.git`, so diffs and the review tree never include it. When the file cannot be written, the result is an error, because inlining it would bring back the cost. The file mode keeps the workspace ACL, so the managed user can delete what `orbit-worker` wrote.

### One user for every task agent

The managed user holds the SSH login and runs Gateway `git` with the GitHub token. `orbit-worker` has neither. Keeping the agent as the managed user and relying on a prompt was rejected: the agent could read that home and install a program that inherits the token. This separation serves [security fits the real threat model](/mission#principles), subject to the [incus-admin and shared-credential limits](#limits).

One Pi server Process on the Node serves every session, including sessions in development Instances. Every agent therefore shares that user and can read the server token and provider sign-in. A user per task would need a separate Pi server and provider sign-in for every account. Running the server as root and dropping privileges inside a session was rejected, because a fault in that path is root. The systemd Process's `User=` sets the account before the server starts.

The Gateway still connects as the managed user. Workspace ACLs let both accounts edit and remove the same checkout without changing its owner. A user namespace was rejected because every checkout would need a second mount. Making `.git` unwritable would stop Git from creating `index.lock`; excluding only config and hooks would not create a trust boundary because the worker can rename `.git` from the writable checkout root. The boundary is which user runs the program, not whether the agent can change Git metadata.

An ACL does not satisfy Git's ownership check. Prepare and inspect add the exact checkout path to the worker's global `safe.directory`, not `*`; removal deletes that entry. Primary checkouts and bridge worktrees need the same scoped trust and access for teardown. [Checkout access](/reference/instance-setup#checkout-access) and [Primary registration](/reference/instance-setup#primary-registration) own those procedures. Teardown runs as the worker; privileged removal deletes the tree without running checkout programs. [The checkout cannot inherit the token](/reference/github-app#the-checkout-cannot-inherit-the-token) explains the separate Git boundary.

### The candidate gate runs as the managed user

The baseline and handoff checks run as the managed user, not as `orbit-worker`. This replaces the part of the one-user decision (ADR 0193) that also ran the checks as the worker. A Project check can include host-dependent tests. Orbit's own Gateway tests need passwordless sudo, the ACL tools `getfacl` and `setfacl`, and the `caddy` account. `orbit-worker` has no sudo, so those tests failed in every gate, although they passed as the managed user, and every task that changed those areas stopped.

Granting `orbit-worker` sudo was rejected, because sudo would remove the boundary the account exists for. Skipping host-dependent tests in the gate was rejected, because the gate would then pass changes that it did not test. Task agents still run as the worker. The check shares what it creates with the worker through the same workspace ACL as inspection, so the next turn can read the check's logs and reports.

The cost is that the gate runs programs that the workspace names, including code an agent wrote, as the managed user. Such a program can read that user's home, and it could observe a token-bearing `git` process of that user that runs at the same time. The agent itself still cannot. This serves [security fits the real threat model](/mission#principles): the gate must test the real host, and the agent stays separated.
