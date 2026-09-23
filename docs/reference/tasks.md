---
title: "Tasks"
description: "How the Gateway tasks extension stores TaskGroup features, provisions a shared Instance, starts T3 agents, routes idle sessions with Jev, verifies the reviewer's pull request, notifies Coder, and removes the instance on complete."
---

# Tasks

This page tells an operator how the optional Gateway `tasks` extension runs a Commander-style feature group. The Gateway stores the group, provisions its shared Instance, starts T3 agents, and routes task sessions with typed comments and Jev. It then verifies and watches the reviewer's pull request, retains capacity through assistance and merge wait, and removes the instance after completion. [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) owns the extension boundary. [ADR 0110](/decisions/0110-route-task-sessions-with-laravel-ai-jev) owns session routing. [ADR 0113](/decisions/0113-gate-task-completion-on-validation-and-review) owns completion gates.

The extension is off until an authorized Gateway caller enables it. There is no web UI for create. Agents create groups through the [MCP server](/reference/mcp).

[ADR 0112](/decisions/0112-isolate-agent-threads-behind-drivers) defines the `AgentThread` and `AgentDriver` boundary. Orbit stores persistent conversations and delegates runtime communication to a driver. T3 is the first driver.

## Enable the extension

Enable and disable require Gateway access: the active Gateway peer, or a Node with a grant to the Gateway.

| Operation | Route | Effect |
| --- | --- | --- |
| `tasks:enable` | `POST /api/v1/tasks/enable` | Turns the extension on. Idempotent. |
| `tasks:disable` | `POST /api/v1/tasks/disable` | Turns the extension off. Existing rows stay. Further create, add, list, show, and complete return `tasks.disabled`. |
| `tasks:status` | `GET /api/v1/tasks/status` | Returns whether the extension is enabled. |

Create, add, list, show, and complete refuse with `tasks.disabled` and HTTP 409 while the extension is off.

## Model

A **TaskGroup** is one parent feature. A **Task** is an ordered subtask. Each row stores a brief with deliverables and acceptance.

| Field | Record | Meaning |
| --- | --- | --- |
| `title` | both | Short name |
| `brief` | both | Deliverables and acceptance |
| `status` | both | Lifecycle state |
| `position` | Task | Order inside the group, starting at 1 |
| `taskable_type` / `taskable_id` | TaskGroup | Morph. v1 is an Instance only. Null until the scheduler assigns one |
| `reviewer_agent_thread_id` | TaskGroup | Long-lived reviewer thread for the group |
| `implementer_agent_thread_id` | Task | Fresh implementer thread for that subtask |
| `pr_url` | TaskGroup | Pull request opened after the last sign-off |
| `notify_coder` | TaskGroup | Opt-in Coder settle webhook. Create also accepts Commander's `notify_on_settle` |
| `implementer_model` / `reviewer_model` | TaskGroup | Defaults: `gpt-5.6-luna` (Codex instance `codex`) and `claude-opus-5` (Claude instance `claudeAgent`) |
| `tokens`, `line_diff`, `duration_ms` | both | Filled on settle and refreshed when an active group is shown |

Group statuses: `queued`, `reserved`, `running`, `reviewing`, `settling`, `completed`, `failed`, `cancelled`. Task statuses: `pending`, `reserved`, `running`, `reviewing`, `completed`, `failed`, `cancelled`.

`settling` is the reviewable state: the pull request is open or the Gateway has finished the open attempt, and Coder may review.

v1 attaches the group to one Instance. A new decision is required before another morph target is stored.

## Create, add, list, show, and complete

Use these operations after the extension is enabled. Create, add, and complete require Gateway access. List and show accept any authorized peer.

| Operation | Route | Access |
| --- | --- | --- |
| `tasks:create` | `POST /api/v1/task-groups` | Gateway |
| `tasks:add` | `POST /api/v1/task-groups/{group}/tasks` | Gateway |
| `tasks:list` | `GET /api/v1/task-groups` | Collection |
| `tasks:show` | `GET /api/v1/task-groups/{group}` | Collection |
| `tasks:complete` | `POST /api/v1/task-groups/{group}/complete` | Gateway |

Create requires `app_id`, `title`, and `brief`. It may include an ordered `tasks` array of `{title, brief}` objects and either `notify_coder` or `notify_on_settle`. Add appends one subtask at the next position. List accepts optional `app_id` and `status` query filters. Show returns the group and its tasks in position order. Complete marks a `settling` group `completed` and removes its Instance.

MCP tool names follow the API operation identifiers: `tasks-create`, `tasks-add`, `tasks-list`, `tasks-show`, `tasks-complete`, `tasks-enable`, `tasks-disable`, and `tasks-status`.

## Web task board

Open **Tasks** in the web navigation to see all tracked task groups. Each card shows its title and the Project’s saved code of three capital letters, followed by the task number, such as `ORB-13`.

Codes are unique across Projects. Edit a code in the Project properties; changing it updates card labels without changing task IDs or URLs.

Cards show separate added and deleted line counts when available, an uppercase status outside Todo, and elapsed duration in minutes and hours.

Select a card to read the task brief, its status, tokens, line diff, duration, and its subtasks. Subtasks use their own Todo, In progress, and Done board. Pending subtasks appear in Todo; reserved, running, and reviewing subtasks appear in In progress. Completed, failed, and cancelled subtasks appear in Done with their outcomes visible. Cards retain their sequence numbers and briefs. Subtask cards show that subtask's tokens and line diff when the Gateway has observed them.

Select a subtask to open its own detail page with its title, brief, status, Project, shared Instance, tokens, line diff, and duration. The subtask detail omits the subtasks board. Use the parent task breadcrumb to return to the board.

The board refreshes every ten seconds. Todo contains queued groups waiting for the scheduler. In progress contains reserved, running, reviewing, and settling groups. Settling means awaiting completion after review and merge. Done contains completed, failed, and cancelled groups; each card keeps its outcome visible. Failed and cancelled do not mean successful completion.

The board is read-only. The Gateway still owns scheduling and concurrency. When the extension is disabled, the page explains that tasks are unavailable. Request errors remain visible instead of appearing as an empty board.

### Tokens and line diff

When the parent task is open, Tokens is the total for the current implementer of each subtask plus the shared reviewer. Line diff is the whole feature branch against the Project default branch. A subtask shows its current implementer's metrics. Showing an active group refreshes these values through the selected drivers and shared checkout. Missing runtime metrics remain unknown; failed reads preserve stored values.

## Scheduler and ceilings

After a successful create, the Gateway scheduler claims the oldest queued group that still fits the Node ceiling. It does not poll Nodes and it does not apply a per-Project ceiling.

Active groups are those in `reserved`, `running`, `reviewing`, or `settling`.

| Ceiling | Limit |
| --- | --- |
| Active groups per Node | 10 |

The Node ceiling applies once `taskable` points at an Instance on that Node. A group without an Instance is not held by a Project ceiling.

A fitting claimed group moves from `queued` to `reserved`. InstanceProvisioning assigns the shared Instance on an active Linux `app-dev` Node with capacity and a WireGuard address. The selected driver must allow the Node. T3 requires an active `t3-code` Process whose desired state is `running`, matching the managed T3 service. This recorded state is the placement signal, not an HTTP health probe. A [development node exclusion](/reference/development-node-exclusions) removes that Node from the choice before the driver check and the ceiling. If no remaining Node fits, provisioning returns no Instance and the group remains `queued` without a workspace.

When the assignment fits the Node ceiling, the group becomes `running`. AgentSpawner starts the shared reviewer and first implementer through the selected driver. The Gateway stores their Orbit thread IDs only after creation and the opening turn succeed.

A spawn that returns no thread id marks the group `failed` and logs which spawn refused. The failing subtask is marked `failed` too. This applies to both opening spawns and to the implementer of any later subtask, so no group stays `running` with a null thread id. Create answers with the failed group rather than raising, so one group cannot break an unrelated create.

`tasks:tick` (`php artisan tasks:tick`) then observes those stored reviewer and implementer threads and routes them. It does not poll Nodes for capacity. It observes the current reviewer and active subtask implementer, excluding earlier attempts and unrelated conversations.

## Shared Instance

One fresh Instance belongs to the group. Every subtask reuses it. The instance name and feature branch are `task-{group id}`. When `origin/task-{group id}` is missing, the provisioner creates that branch from the Project `default_branch` and checks it out in the shared workspace.

| Intent | When | Result |
| --- | --- | --- |
| `visitable: false` | Orbit monorepo feature work (`app.slug` is `orbit`) | Isolated checkout on the feature branch. No Route and no public URL. The instance stays `source_resolved` |
| `visitable: true` | A real Project | Usual development provisioner and inspect subdomain. The instance becomes `active` |

The provisioner honors `visitable`. It does not invent a Route for a non-visitable workspace because an active Instance still requires exactly one Route.

For Orbit monorepo work, `source_resolved` confirms the checkout and branch. Before returning the workspace for agent start, the task provisioner runs the Project’s ordered [setup list](/reference/instance-setup) through pinned SSH, using the same command supervisor as `instance:setup`.

Orbit requires a nonempty list; missing configuration sets the instance error code to `tasks.workspace_setup_unconfigured`. Configure a `bootstrap` step with command `bin/bootstrap` and step timeout 845 seconds. Bootstrap installs dependencies, seeds compatible main caches, runs Pest TIA, and runs quality checks in all five projects. A failed setup keeps the group queued for retry on the same instance; the instance reports `task-bootstrap` as its failed step. The scheduler starts no agent on failure. Cancellation during preparation remains cancellation.

The Gateway reads successful main cache publications from the Git common directory of `ORBIT_TASKS_CACHE_REPOSITORY`, which defaults to its monorepo checkout. It transfers only the fifteen known publication files through SSH stdin. The destination seeds private caches after validating dependency, runtime, checksum, and ancestry compatibility. Missing or incompatible publications allow a normal first run. Setup does not refresh shared main caches; the operator merging or deploying Orbit owns that refresh. `ORBIT_MAIN_CACHE_STORE` selects the transported publication directory for the seed tools.

Task preparation retains a private diagnostic log at `.git/orbit-task-setup/bootstrap.log`; command output is not returned through the API. Each attempt runs the current setup list from the start, within an 850-second command budget. A failed command stops the list. Step commands must be safe to retry. Ordinary instance creation and task preparation share the Project’s commands, but task failure retains the allocated checkout and its private caches instead of rolling it back.

## Agent viewer

The task group page shows an Agents section below Subtasks. Vertical tabs select the shared reviewer or an implementer. A subtask page shows its implementer conversations and the shared reviewer. Finished conversations remain available. Activity and connection health have separate labels; a disconnected viewer retains the last known activity state.

`GET /api/v1/task-groups/{group}/agents` lists persisted threads, including driver, external ID, state, observation time, errors, and metrics. `GET /api/v1/task-groups/{group}/agents/{session}/stream` streams normalized conversation data for an Orbit thread ID. Both routes require Gateway access and an enabled tasks extension. Runtime credentials stay server-side. A missing original Node leaves the link visible but unavailable for streaming.

Snapshots replace the browser transcript. The browser supplies an opaque `Last-Event-ID` on reconnect. T3 obtains a fresh full snapshot on each connection, then sends entry, state, and metric changes. Viewer connections do not write thread state or observation errors; polling owns persisted observations and rejects concurrent stale writes. Connections rotate periodically and close when the viewer is left. The external runtime owns transcripts; Orbit cannot recover a deleted remote conversation.

## Agent threads and drivers

An `AgentThread` is one persistent conversation. It records the driver, external conversation ID, original Node, task links, role, model, and effort. Task and TaskGroup thread pointers refer to Orbit thread IDs. Existing T3 session links migrate with their IDs and ownership preserved. The external runtime retains the transcript. The integer `reviewer_agent_thread_id` and `implementer_agent_thread_id` fields replace external string pointers. The migration preserves old record IDs and imports missing legacy links.

It is forward-only; reverting to an older Gateway requires restoring a database backup or a reviewed forward migration. Ownership conflicts are checked before schema changes. Take a backup before migrating. If a database without transactional DDL stops partway through a schema change, restore that backup before retrying; do not rerun against the partial schema.

| State | Meaning |
| --- | --- |
| `Idle` | Ready without an active turn or reported outcome |
| `Working` | Executing a turn |
| `AskingForInput` | Waiting for a question or approval response |
| `Done` | Latest turn completed successfully |
| `Failed` | Latest turn failed |

Completion and failure remain visible until a new turn starts. Task completion still requires the scheduler workflow and review. Failed observations preserve the last known state and metrics and mark them unavailable. Connection health does not change a thread to idle or failed. Unavailable observations use the outage grace period and cannot advance a task from cached state.

`ORBIT_TASKS_AGENT_DRIVER` selects the registered driver for new groups and defaults to `t3`. Existing groups and threads keep their recorded driver. The Gateway registers drivers; callers cannot supply arbitrary runtime URLs. Unsupported driver operations fail explicitly. An unknown configured driver rejects group creation with `tasks.agent_driver_unavailable` before any group is stored.

The Gateway sends normalized conversation snapshots, entries, states, input requests, and metrics to the web app. Reconnect cursors belong to the selected driver. The browser renders Orbit data without parsing runtime-specific events. Laravel AI continues to select scheduler actions through Jev.

### T3 driver

Agents run on the T3 server of the Node that owns that Instance. The Gateway posts a flat command to `http://{wireguard_ip}:{ORBIT_T3_PORT}/api/orchestration/dispatch` with `headers: []` on every body. `ORBIT_T3_PORT` defaults to `3773`. `ORBIT_T3_TOKEN` is an optional bearer for that Node's T3 server. A successful dispatch needs a sequence. Commands that have no thread, including `project.create`, may omit `threadId`. `project.create` `defaultModelSelection` and `thread.create` `modelSelection` send options as `{id, value}` objects, never a bare map such as `{effort: high}`.

When `project.create` collides on an occupied workspace root, T3's receipt is `Active project '{uuid}' already exists for workspace root '{path}'`. HTTP dispatch may wrap that as `EnvironmentInternalError` / `orchestration_dispatch_failed` without the phrase. The Gateway parses the project id from that phrase when it appears in the error body, a nested cause, or a header, and otherwise adopts the active project for that workspace root from `GET /api/orchestration/snapshot`. After a successful `thread.create`, the Gateway starts the first turn. A refused `thread.turn.start` is retried once and logged at error. The spawn then returns null and stores no thread id.

Each subtask gets a fresh implementer (`instanceId=codex`, `model=gpt-5.6-luna`, `reasoningEffort=low`). The group keeps one reviewer thread (`instanceId=claudeAgent`, `model=claude-opus-5`, `effort=high`). The T3 provider instance is selected from the model: Claude model names use `claudeAgent`; other configured models use `codex`. Role supplies default model and effort. The instance is fixed at `thread.create`. Subtasks run in position order. At most one Task in a group is `running`. Opening starts only the first pending subtask. The next pending subtask becomes `running` only after reviewer sign-off completes the current one and no sibling is `running`. The scheduler refuses a second running task and does not spawn another implementer.

For tasks outside the verification pilot, when an implementer is idle, done, or asking for input, the Gateway reads `composer check` from tool activity: the command ran, the exit code is 0, and no edit, write, or patch follows it. It also reads `composer.json` at the workspace root, which must define a `check` script. Jev is asked only whether the agent is blocked, from the implementer thread and the task and group briefs. A pending input fails on its own.

When every item passes, the Gateway sets the task to `reviewing` and sends `please review` to the reviewer thread. If that send fails, the next tick sends it again before the reviewer is asked for an outcome comment. While that thread is still idle, or its snapshot is still the turn from before the handoff, the Gateway waits. It asks for an outcome only after a newer review turn stops.

After review findings are relayed, the Gateway waits for a newer implementer turn to stop and requires a new composer-check run before handing back to the reviewer. The implementer does not post a `ready_for_review` comment.

A reviewer posts `changes_requested` or `approved`. The Gateway relays the findings to the implementer and returns the task to `running` only after that send succeeds. A failed send stays in `reviewing` and is retried. An approval is checked in code before advancing. After the last subtask, the group moves to `settling` and remains active until its expected pull request is merged.

`thread.turn.start` sends the T3 0.0.42 message struct `{messageId, role: user, text, attachments: []}` plus `modelSelection`. A flat string message is rejected by T3.

## Session routing

A scheduler tick checks every in-progress task in running and reviewing groups. In-progress tasks have status `running` or `reviewing`. The tick checks the normalized AgentThread state of each attached reviewer or implementer thread, including sessions recorded only in `agent_threads`. Tasks without attached sessions are skipped. Pending, completed, failed, and cancelled tasks do not ask Jev for decisions.

AgentThread state is authoritative. A `working` thread (including a starting T3 session) defers its task until a later tick. The Gateway does not inspect that task's messages or pending requests, check workspace commits, or call Jev. Other snapshot fields cannot override an active status. The tick still checks the remaining sessions and other in-progress tasks.

For each eligible stopped implementer outside the verification pilot, the tick reads `composer check` from tool activity. The run must name `composer check`, exit 0, and have no edit, write, or patch activity after it. An assistant message does not count. `composer check-platform-reqs` and other commands that start with `check` do not count.

The tick also reads `composer.json` at the workspace root over SSH. The `check_script` item passes only when `scripts.check` is a non-empty command or list. Composer resolves abbreviated command names, so without that script `composer check` runs the built-in `check-platform-reqs` command and exits 0. A missing file, invalid JSON, or a missing or empty `check` script fails `check_script`, even when the transcript shows a passing run. Jev is asked only whether the agent is blocked, and that answer passes only when Jev chooses `no` with a confidence at or above `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` (default `0.75`).

TypeSafe reports confidence as the margin between the two choices, so `0.75` needs a `no` probability of at least `0.875`. A pending input fails `waiting_for_input` in code and skips that question. A thread state the rubric does not recognize waits without a model call. When every item passes, the Gateway sets the task to `reviewing`. [ADR 0114](/decisions/0114-judge-task-completion-as-separate-checks) owns this rubric.

A stopped reviewer with `changes_requested` is relayed, and the task returns to `running`. An `approved` comment is checked in code: the commit equals HEAD, the branch is `task-{group id}`, the tree is clean, the commit is new for the subtask, and the final pull request verifies. A missing outcome comment asks Jev only whether the reviewer is blocked. `assistance_requested` and `resolution` still update the assistance flag and keep their history. Status stays the current phase for those two comments.

Jev reads one role's evidence for each blocked question: the group title and brief, the task title and brief, and that role's thread state and recent entries. The implementer question does not include the shared reviewer thread, and the reviewer question does not include the implementer thread. Pull request, commit, and CI fields stay with the code checks. Rubric reminder turns are removed before Jev reads the thread. The agent's reply stays. [ADR 0117](/decisions/0117-judge-the-blocked-question-on-role-evidence) owns this evidence.

The Gateway sends one reminder that names every failed code item. It starts and ends with fixed sentences:

| Role | Starts with | Ends with |
| --- | --- | --- |
| Implementer | "Orbit could not confirm the brief is complete." | "If it is, reply with a short summary of what changed and the composer check result. If something outside the brief stops you, say what it is." |
| Reviewer | "Orbit could not confirm the review is complete." | "If something outside the review stops you, say what it is." |

The reminder does not say that the thread is blocked and does not ask for an `assistance_requested` comment. A blocker the agent names in its reply fails the next evaluation.

The next idle evaluation asks for assistance when any item still fails. Repeated reminder-send failures ask for assistance on the fifth failure; a successful Jev answer does not reset that send counter. The same pending input does not count as that next evaluation. A `Failed` thread asks for assistance without a reminder.

A missing Jev answer, one without a confidence, a missing or empty `TYPESAFE_API_KEY`, or a failed or unreachable TypeSafe request counts as a communication failure for that task and asks for assistance on the fifth consecutive failure. The tick continues with the other tasks. The recorded reason names the failure but never includes the provider's response. The assistance reason names each remaining item, and a Jev item includes its choice and confidence.

[ADR 0120](/decisions/0120-verify-task-evidence-before-review) defines the opt-in verification pilot below. Pilot tasks use captured execution results and required Noul questions in place of the three transcript-derived check items. Runtime state, pending input, the blocker question, and independent review still apply.

Typed comments are the workflow record. They preserve the full body, author, timestamp, task and thread context, and reviewer attempt metadata. They do not supply verification evidence. The pilot runner owns that record. `assistance_requested` flags the task and group, retains the active slot, and is notified once. A non-empty `resolution` comment preserves the history, resets the completion and communication attempts, and continues the blocked AgentThread idempotently; failed delivery leaves the task visibly blocked.

Each observation includes normalized activity state, availability, errors, pending request IDs, and recent assistant and user text. It also reports new workspace commits, the pull request URL, and any available CI summary. The driver resolves pending requests from its runtime data. Missing or unavailable current conversations skip classification. The scheduler waits `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` (default `120`), then escalates once per continuous outage. Recovery resets the grace period and alert marker.

Legacy scheduler actions:

| Action | Effect |
| --- | --- |
| `drain_approval` | Driver approval response accepting the current request |
| `drain_user_input` | Driver question response continuing the current brief and refusing scope expansion |
| `continue_implementer` | Driver follow-up on the implementer with its recorded model |
| `relay_review_to_implementer` | Driver follow-up on the implementer including the last reviewer excerpt |
| `mark_subtask_done` | Existing settleImplementer, acceptReview, and next-subtask spawn paths |
| `settle_group` | Existing settle path: use the verified PR, write metrics, and notify Coder when CLEAN-ready |
| `escalate_coder` | HMAC Coder webhook with the observation and the low-confidence or failed Choice |
| `noop` | No driver action and no Coder notification |

Confidence below `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` (default `0.75`) becomes `escalate_coder`. A missing `TYPESAFE_API_KEY` fails closed with a clear error and never invents a next action.

Gateway uses `laravel/ai` Classification with its official TypeSafe provider in `config/ai.php`. The package client posts to TypeSafe. Tests use the package fake and never call the network.

Run the tick with `php artisan tasks:tick` while the extension is enabled. One Gateway lock protects observation and routing. A held lock skips that invocation. After routing releases the lock, the tick tries to start one queued group. A separate process-held lock permits one startup at a time across scheduled ticks and task creation requests. A busy startup leaves other groups queued without blocking observation of running tasks. Groups that are reserved, running, reviewing, settling, assisted, or awaiting merge count toward the limit of 10.

The Gateway registers `tasks:tick` every ten seconds when the tasks extension is enabled. Laravel runs each invocation in the background so a slow bootstrap does not hold the schedule. LIVE Ops must run Laravel's `php artisan schedule:work` process for this schedule to advance sessions; this feature does not provision that process or a fleet cron.

## Task verification pilot

The pilot is disabled by default. It supports the Orbit monorepo and local PHP test evidence. Enable it only after the held-out evaluation in [ADR 0120](/decisions/0120-verify-task-evidence-before-review#smallest-experiment-and-release-condition) passes. The [live diagnostic](/decisions/0120-verify-task-evidence-before-review#live-noul-diagnostic) passed on a small author-labeled corpus; independent labels and review remain required. There is no default Noul threshold or established production error rate.

Set `ORBIT_TASKS_VERIFICATION_APP_IDS` to the comma-separated App IDs in the pilot and `ORBIT_TASKS_VERIFICATION_NOUL_THRESHOLD` to an evaluated probability greater than `0.5` and at most `1`. New tasks for those Apps require one to three criteria. Existing tasks retain their contract. Removing an App from the setting does not remove verification from its existing pilot tasks. A missing threshold refuses pilot task creation with `tasks.verification_not_calibrated` (409).

Include `verification` in each task supplied to `tasks-create`, or in `tasks-add`. Each criterion has this shape:

```json
{
  "id": "failed-check",
  "requirement": "A failed check prevents review.",
  "question": "Does the named test demonstrate that a failed check prevents review?",
  "true": "It observes no review handoff after a failed check.",
  "false": "It does not observe that outcome.",
  "environment": "local"
}
```

IDs must be unique within the task. Criteria cannot be edited through the API. Only `local` is supported. A criterion requiring Linux topology evidence must wait for the topology slice; a mocked test cannot satisfy it. Tasks whose acceptance is entirely deterministic belong in the code-only evaluation baseline.

After implementing the task, call `tasks-verify` with the group and task IDs and the following body. Use the exact Pest test name, including its `it ` prefix and any dataset suffix.

```json
{
  "run_key": "33c532b6-a58a-4848-964c-cb20b683b78b",
  "evidence": [{
    "criterion_id": "failed-check",
    "project": "apps/gateway",
    "path": "tests/Feature/Tasks/FailedCheckTest.php",
    "test": "it prevents review after a failed check"
  }]
}
```

The example shows the reference format. Evidence files must fit the limits below; choose a focused test file that demonstrates the requirement. Each criterion needs exactly one reference. The API accepts no commands, checkout paths, success claims, or uploaded results.

| Operation | Route | Result |
| --- | --- | --- |
| `tasks:verify` / `tasks-verify` | `POST /api/v1/task-groups/{group}/tasks/{task}/verify` | Runs checks and returns the captured result |
| `tasks:verification` / `tasks-verification` | `GET /api/v1/task-groups/{group}/tasks/{task}/verification` | Returns the newest run, or null before the first run |

Both operations require Gateway access and an enabled tasks extension. Verification requires a running pilot task with an assigned Instance and no pending assistance. A different group returns 404. A changed request using an existing run key returns `tasks.verification_key_conflict` (409). A second active run returns `tasks.verification_running` (409).

Gateway sends its runner over pinned SSH to the assigned Node. The runner copies tracked and untracked source into an isolated checkout and copies installed dependencies. It leaves the agent's Git index unchanged. Scratch checkouts use Orbit runtime storage because Node `/tmp` filesystems can be too small for installed dependencies.

Profile `orbit-composer-v2` runs `composer validate --strict`, `composer check`, and `composer test:affected` in CLI, Docs, Gateway, E2E, and PHP SDK order. Tests run through Pest TIA once per project. Gateway's quality check does not run a second test suite. The runner seeds a private TIA cache from the history already supplied to the task checkout by worktree bootstrap, then preserves it across disposable snapshots. It keeps the source branch and default-branch reference so Pest can reuse and update that graph. A concurrent check of the same checkout is refused. Missing or invalidated history can still require all tests to run.

The runner then reruns each distinct referenced test file with Pest's JUnit output and `--no-tia`. Only an exact, unambiguous passing test with assertions supplies evidence. JUnit output from the parallel full suite is not used because the inspected Pest version failed to merge one real Gateway report.

Source files, deletions, modes, internal symlink targets, branch, HEAD, installed dependency manifests, PHP version, and the runner profile form the input identity. Gateway also binds the result to the task, attempt, Instance, Node, checkout, criteria, model, and threshold. Changed inputs require a new run. These checks assume trusted installed tools and dependencies; they do not attest all machine state or resist hostile code running as the same OS user.

The local runner has an 840-second budget; SSH has 880 seconds and the run reservation has 900 seconds. The Gateway command deadline is 900 seconds. A caller must allow that duration; after an uncertain disconnect, read the newest result or repeat the same run key. A duplicate key observes the existing run. An expired run becomes `interrupted` and cannot authorize review. Checks execute outside the scheduler tick, which waits for that task and continues other tasks.

Each evidence file is limited to 12,000 UTF-8 bytes. Total criteria and evidence are limited to 24,000 JSON bytes. Oversized or unresolved evidence does not pass and is never silently truncated. Keep tests small enough for the pilot. Logs remain under `$ORBIT_HOME/task-checks` in a private run directory on the Node; the API returns command results and log references, not raw logs or source. Operators remove these logs after diagnosis; automatic retention is not implemented.

Jev receives the required questions together as Nouls using `jev-1.13.0`, with a three-second timeout. Each question checks only its criterion and named test. Jev does not certify command execution, test correctness, or Linux behavior. Missing evidence skips classification. Probabilities below the configured threshold remain unverified. Provider errors remain errors and never become a passing answer.

| Status | Meaning |
| --- | --- |
| `running` | Checks or classification are in progress |
| `complete` | Processing finished; inspect `checks_passed`, `answers`, and `error`. This does not mean ready or approved |
| `error` | Transport, runner, evidence-budget, or provider error prevented evaluation |
| `interrupted` | The reservation expired or the task or inputs differ from the run |

The result includes command outcomes, source fingerprint, criterion digest, evidence references, Noul probabilities, threshold, model, check duration, and semantic token usage and duration. The newest started run supersedes any earlier pass. If inference fails after passing checks, repeating the same run key can retry inference up to three total attempts after a fresh input check. It does not rerun checks. Valid answers, including negative ones, are reused for identical inputs; repeated requests cannot retry a negative answer until it becomes favorable.

Before review, the scheduler consumes the newest result, checks current inputs, and requires every criterion to pass. It saves the consumed run ID on the review handoff. The reviewer receives that ID and the criteria. Missing or negative evidence uses the existing reminder and assistance flow. Provider errors use the communication-failure flow. Review delivery retains the existing retry behavior; a lost transport acknowledgment can repeat the message, so this is not an exactly-once delivery guarantee.

The next runtime-proof slice will give each applicable task group its own ephemeral Incus topology. Code will bind executed Linux proof to that group and its current attempt. Jev may check whether the observations cover the requirement. Allocation, reset, cancellation, failure inspection, and cleanup must be verified together before that slice is enabled.

### Calibrate Jev

The default test suite fakes Jev. To measure the blocked question against real Jev, set `TYPESAFE_API_KEY` in the environment and run the calibration suite from `apps/gateway`:

```bash
composer test:calibration
```

The suite reads the observations in `apps/gateway/tests/Fixtures/JevCalibration`. Each fixture uses the observation shape from the Coder escalation webhook and names its role and expected choice. The suite fails when Jev picks another choice or when a passing fixture scores below the configured threshold. It prints each choice, confidence, and probability pair. It refuses to run without a key, and CI does not run it.

## Pull request and settle metrics

The reviewer creates the final GitHub pull request and supplies its URL in the final `approved` comment. Before advancing the task, the Gateway verifies that exact URL against the Project repository, the instance branch (`task-{group id}`), and the repository's default target branch. The PR head must equal the approved commit and current workspace HEAD; a later unreviewed head is rejected even when it includes the approved commit. The Gateway stores the verified URL as the group's `pr_url`. Invalid references, missing credentials, or an unavailable GitHub API leave the task in review and use the existing reminder and assistance flow.

Settling uses the stored PR and watches for its merge. Existing settling groups keep their stored URL. A settling group without a URL requests assistance and remains incomplete. The Gateway does not create a replacement PR.

The Gateway then writes settle metrics. Active groups also refresh these fields when an authorized caller shows the group.

| Field | Record | Source |
| --- | --- | --- |
| `tokens` | Task | Cumulative tokens reported by the current implementer's driver. Unknown until reported; failed reads preserve stored values |
| `line_diff` | Task | Reported insertions plus deletions for the current implementer. Failed reads preserve stored values |
| `lines_added`, `lines_deleted` | Task | Separate checkpoint insertion and deletion counts; null before observation |
| `duration_ms` | Task | Elapsed milliseconds from `started_at` to `settled_at`, or to now while the subtask is still open |
| `tokens` | TaskGroup | Sum of Task `tokens` values plus the reviewer thread's reported tokens, or `0` at settle when none are stored |
| `line_diff` | TaskGroup | Insertions plus deletions of `git diff --numstat {default_branch}...HEAD` in the shared checkout, or `0` when git cannot run. This is the whole feature branch, not the sum of subtask session diffs |
| `lines_added`, `lines_deleted` | TaskGroup | Separate branch insertion and deletion counts; null before a successful observation |
| `duration_ms` | TaskGroup | Elapsed milliseconds from `started_at` to settle, or to now while the group is still active, or `0` when `started_at` is empty |

For T3, token totals use `totalProcessedTokens` when present and otherwise `usedTokens`. Per-thread line counts come from checkpoints. Other drivers supply metrics with the same meaning or leave them unavailable.

## Coder settle webhook

When `notify_coder` is true, settle POSTs an HMAC-signed JSON body to Coder. This is Commander's `notify_on_settle` path.

| Environment key | Meaning |
| --- | --- |
| `ORBIT_CODER_WEBHOOK_URL` | HTTPS endpoint that receives the settle POST |
| `ORBIT_CODER_WEBHOOK_SECRET` | HMAC-SHA256 secret. The Gateway never returns it |
| `ORBIT_TASKS_GITHUB_TOKEN` | GitHub token with repository and pull-request read access for final approval verification and merge watching |
| `ORBIT_TASKS_OBSERVATION_GRACE_SECONDS` | Seconds before one alert for an observation outage. Defaults to `120` |
| `ORBIT_TASKS_AGENT_DRIVER` | Registered driver key for new groups. Defaults to `t3` |
| `ORBIT_T3_PORT` | T3 HTTP port. Defaults to `3773` |
| `ORBIT_T3_TOKEN` | Optional bearer for that Node's T3 server |
| `nodes.settings.t3.token` | Required bearer projected with each node when node-scoped T3 credentials are enabled. A projected node never falls back to `ORBIT_T3_TOKEN`; missing configuration fails closed. |
| `nodes.settings.t3.url` | Optional full base URL for that node's T3 server. When absent, the node's WireGuard address and `ORBIT_T3_PORT` are used. |
| `TYPESAFE_API_KEY` | TypeSafe Jev key for task-session Classification. Missing key fails closed |
| `ORBIT_TASKS_JEV_CONFIDENCE_THRESHOLD` | Minimum Choice confidence before execute. Defaults to `0.75`, measured as the margin between the two choice probabilities. Below this, the tick escalates |

The Gateway skips the webhook when the URL or secret is missing. A refused Coder response does not fail settle.

The signed payload is `{unix timestamp}.{raw JSON body}`. Senders use these headers:

| Header | Value |
| --- | --- |
| `X-Orbit-Timestamp` | Unix seconds used in the signature |
| `X-Orbit-Signature` | `sha256=` plus the hex HMAC of `timestamp.body` |
| `Content-Type` | `application/json` |

The JSON body contains `event` (`task_group.settled`), `task_group_id`, `title`, `tokens`, `line_diff`, `duration_ms`, and `pull_request_url`.

An `escalate_coder` Choice posts the same HMAC headers with `event` `task_group.escalated`. That body adds `reason`, `confidence`, `thread_id`, and the structured observation. The scheduler does not post Coder webhooks for drains, continues, relays, or noops.

An `assistance_requested` comment or unresolved workflow omission posts `event` `task_group.assistance_requested` with the task-group id, title, and reason. The task and group remain flagged until a non-empty resolution is delivered.

## Complete and cleanup

After Coder review and PR merge, an authorized Gateway caller runs `tasks:complete` (`POST /api/v1/task-groups/{group}/complete`, MCP tool `tasks-complete`). That marks the group `completed` and removes the shared Instance through the existing Instance remover, including any visitable Routes.

Complete is the documented cleanup path. The Gateway GitHub App receives no merge webhook. A second complete is idempotent. Completing a group that is not `settling` or already `completed` returns `tasks.not_settling` (HTTP 409). Operators may still call `DELETE /api/v1/instances/{instance}` directly; that leaves the group `settling` until complete runs.

## Out of this slice

These items stay unimplemented here and need a later feature PR.

- Commander data migration and retiring Commander
- Creating or changing tasks through the web UI
- Per-Project model overrides
- Tom-on-Mini routing
 - Fleet TypeSafe key mint (Ops after CLEAN)

## Cancel a stuck group

Call `tasks-cancel` with `{ "group": 123 }` to cancel a `queued`, `reserved`, `running`, `reviewing`, or `failed` group. The API operation is `tasks:cancel`. Cancellation removes the shared Instance and clears both taskable fields before returning the group as `cancelled`. Repeating cancellation is safe and also cleans up an Instance still attached to a group already marked `cancelled`. Subtask records and agent thread identifiers stay as history.

A route-free Instance in `source_resolved` uses the Ops database cleanup contract: delete the Instance row and retain its checkout on disk. Other Instances use the existing forced Instance remover, including Route cleanup. Removal errors propagate and leave the group attached for retry. Cancellation does not interrupt the external agent conversation.

A `settling` or `completed` group returns HTTP 409 with `tasks.not_cancellable` (an MCP error result). Use `tasks-complete` for a settling group after review and merge.
