---
title: "ADR 0218: Take long commands off the request path"
sidebarTitle: "0218 Request limit"
description: "In progress. Each command that can still run for minutes inside a Gateway request becomes an operation, moves to a queued job, or stays with explicit timeouts. An operation becomes the only record of its run. Then the PHP-FPM request limit drops from 600 to 300 seconds."
---

# ADR 0218: Take long commands off the request path

[ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations) made Instance deploy and rollback operations and plans to move Instance create and remove, and Route convergence. About thirty other commands can still run for minutes inside a PHP-FPM request. This ADR decides each of them. A command that changes something, and whose time Orbit cannot bound, becomes an operation. A command whose slow part is background work moves to a queued job. Every other command stays in the request with explicit timeouts. An operation becomes the only record of its run, so the deployment history and the removal ledger give way to operations. When nothing long runs in a request, the Gateway's request limit drops from 600 to 300 seconds.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). An agent that adds a Node, transfers an Instance, or moves a role can lose its connection and still find the operation, its current step, and its result with one read. It also serves [One way, one name](/mission#principles): every long command uses the same record, the same reads, and the same recovery, and no command keeps a run record of its own beside the operation. No principle exception is proposed.

## Context

- **ADR 0209 left the limit at 600 seconds.** Its step 5 keeps the PHP-FPM limit until no long operation runs in a request. Deploy and rollback are operations. Instance create and remove, and Route convergence are planned as operations.
- **Other commands still run for minutes in a request.** Activity from 26 Aug to 16 Oct 2026 shows, as the longest runs: `instance:register` 522 s, `instance:create` 471 s, `node:add` 330 s (failed) and 187 s (succeeded), `instance:destroy` 252 s, `tasks:update` 245 s, `instance:transfer` 242 s (failed) and 172 s (succeeded), `tasks:cancel` 199 s, `tasks:create` 196 s, `tasks:complete` 192 s, `node:role:relocate` 191 s, `node:role:add` 148 s, `route:update` 139 s, and a fleet `doctor` 126 s.
- **The command deadline is the only bound of many commands.** Each SSH and process call defaults to a 900-second timeout. Only the request's 570-second command deadline stops such a call. So a command that is short in practice has no bound of its own.
- **A long request is expensive.** The Gateway pool has 8 PHP-FPM workers, and a long request holds one of them. PHP-FPM checks the limit at least every 200 seconds, a third of the limit, because its heartbeat is the smallest over all pools of the master. So a request can live up to 800 seconds. Activity marks a killed request only after 900 seconds. All tool calls of one MCP request share one 570-second deadline.
- **One endpoint runs work after its response.** The runtime wake of a hibernated Instance runs `composer install`, `vp install`, and Process starts in the PHP-FPM worker after the response, with no command deadline and no Activity entry.
- **Several values derive from the 600 seconds:** the Caddy timeouts, the command deadline, the Activity sweep, the request term of Node locks, the stale-claim window of roles, the smoke limit of an API request, and the 540-second limit of setup and teardown lists.
- **One operations worker.** The `operations` queue has one Horizon process, by design ([ADR 0209](/decisions/0209-run-long-operations-as-durable-queued-operations)). Between 15 Oct 2026 17:48 UTC and 16 Oct 03:34 UTC, 45 deploys ran on it. The longest wait in the queue was 88 seconds, and the 90th percentile 14 seconds. Task workspace removals come in bursts: on 9 Oct 2026, 10 removals of 52 to 196 seconds each ran within 10 minutes.
- **Commands keep their own run records.** `instance_deployments` keeps the deploy and rollback history, 50 rows per Instance, with a log of up to 128 KiB per run, next to the `instance.deploy` and `instance.rollback` operations. `instance_removals` and `instance_removal_members`, the removal ledger, keep removal progress. `instance_transfers`, `project_updates`, the clone and registration evidence columns, and `database_servers.failed_step` keep the progress of other commands.
- **Older CLIs misread a 202.** The PHP SDK accepts every 2xx response. An older CLI that gets an operation where it expects a record exits 0 and prints the operation ID as the Node or Instance ID. Managed Nodes get a new CLI in the [fleet rollout](/reference/self-update), about 38 seconds for 5 Nodes once CI published the release. Operator machines update by hand.

## Decision

When this ADR is built, the Gateway runs no long command inside a request. Each command that still runs long today is decided below: it becomes an operation, moves to a queued job, or stays with explicit timeouts. The operation is the only record of its run. Then the request limit drops to 300 seconds.

### Which commands become operations

A command becomes an operation when it changes a Node, a Cluster, an Instance, a Route, a Project, or a Database server, and its time depends on something Orbit does not bound: role convergence, steps that a person wrote, lock waits that add up, or work that grows with fleet data such as the Routes of a Cluster. A command stays when an explicit budget of at most 180 seconds bounds its slow part and a retry continues the stopped work. Tools and Processes stay for this reason: a stopped download or image pull keeps its files. A read never becomes an operation. A lost read costs nothing, and the caller reads again.

| Command | Kind | Target | Longest seen | Why |
| --- | --- | --- | --- | --- |
| `node:add` | `node.add` | `node` | 187 s, failed 330 s | apt, bootstrap, and role convergence |
| `node:remove` | `node.remove` | `node` | 83 s | Fleet Metrics and WireGuard changes, one probe per role |
| `node:converge` | `node.converge` | `node` | No API runs | Lock waits of up to 420 s before the first artifact |
| `node:role:add`, `metrics:enable` | `node_role.add` | `node` | 148 s | Role convergence and the fleet Metrics reconcile |
| `node:role:remove`, `metrics:disable` | `node_role.remove` | `node` | 94 s | Role teardown and the fleet Metrics reconcile |
| `node:role:relocate` | `node_role.relocate` | `node`, the destination | 191 s | Two convergences and a 31-second DNS wait |
| `metrics:exporter:enable`, `metrics:exporter:disable` | `metrics_exporter.enable`, `metrics_exporter.disable` | `node`, the exporter Node | 65 s | Fleet Metrics reconcile: 120 s per command, 300 s per download |
| `analytics:update` | `analytics.update` | `node`, the analytics Node | Never run; `node:role:add` of `analytics` took 148 s | Role convergence with an image pull |
| `cluster:router:set`, `cluster:router:unset` | `cluster_router.set`, `cluster_router.unset` | `cluster` | 101 s for 6 Routes | Grows with the Routes of the Cluster |
| `cluster:node:add`, `cluster:node:remove` | `cluster_node.add`, `cluster_node.remove` | `cluster` | 1 s without Routes | Grows with the Routes of the Node |
| `cluster:update` with `state` or `tld` | `cluster.update` | `cluster` | 49 s, failed | Grows with the Routes of the Cluster; Cluster 4 has 39 |
| `instance:transfer` | `instance.transfer` | `instance` | 172 s, failed 242 s | A killed request leaves the source paused |
| `instance:clone` | `instance.clone` | `instance`, the new one | 89 s | Clone, certificates, and a database copy of up to 600 s |
| `instance:register` | `instance.register` | `instance`, the primary | 522 s | Grows with worktrees, plus the setup list with `--setup` |
| `instance:setup` | `instance.setup` | `instance` | 70 s | A list that a person wrote, up to 540 s |
| `instance:dependencies:update` | `instance_dependencies.update` | `instance` | Never run | Two package steps of 600 s each by design |
| `instance:analytics:enable`, `instance:analytics:disable` | `instance_analytics.enable`, `instance_analytics.disable` | `instance` | 130 s | Route convergence and the fleet Metrics reconcile |
| `database:server:create` | `database_server.create` | `database_server` | 12.5 s | An image pull and two readiness waits of 120 s |
| `project:update` that the Instances must follow | `project.update` | `project` | 15 s | One Git fetch and one Route convergence per Instance |

ADR 0209 moved `instance:rollback` and plans `instance:create`, `instance:destroy`, and the `route:*` writes. Under the naming rule below, its Route kinds are `route.create`, `route.update`, `route.destroy`, `route_target.set`, and `route_target.unset`. Two more commands need no kind of their own. `instance:rename` with a new domain starts `route.update`. `node:add` with a TLD runs its Route convergence inside `node.add`.

### Kind names, targets, and deadlines

A kind name follows its CLI command. The last segment is the verb, and the segments before it, joined with `_`, are the family: `node:role:add` becomes `node_role.add`. This matches `instance.deploy`. `metrics:enable` and `metrics:disable` start `node_role.add` and `node_role.remove`, because they do that work.

The request validates the input and runs the checks that refuse at once, such as `destructive_consent_required` and `node.has_instances`. When the command creates the target, the request also creates it. Then the request starts the operation. So `node:add` saves the Node as `provisioning` before it answers 202, and a refusal is still a synchronous 4xx.

An operation's `node_id` is the serving Node of its target. A Cluster or Project target has none, so only callers with Gateway access read it.

A command can take input that needs no remote work, such as `cluster:update` with only a new name or `project:update` with only a new `merge_check`. For such input the command answers 200 with the record. For input that needs remote work, the same command answers 202 with an operation. The OpenAPI document lists both answers.

Each kind caps its run with one `CommandDeadline::within()` call, as `instance.deploy` caps itself with its deploy deadline. The cap is 570 seconds, the command deadline these commands have today, so no command gets less time than now, and none gets more without a decision. Waiting for a lock counts against the cap. The ADR 0209 kinds that are not built yet use the same cap. This ADR decides four exceptions:

- `instance.create`, `instance.register` with `--setup`, and `instance.destroy` add the total of their setup or teardown list, at most 540 seconds. Today the list gets only what the rest of the command leaves of the request.
- `instance.clone` adds its database copy timeout of 600 seconds.
- `instance_dependencies.update` uses the whole 1,600-second operation deadline, because its two package steps have 600 seconds each.
- `instance.rollback` keeps the 900 seconds that it has as an operation since [#1301](https://github.com/nckrtl/orbit/pull/1301).

### Commands that move elsewhere

| Command | Moves to | Longest seen | Why |
| --- | --- | --- | --- |
| `tasks:create` and `tasks:update` with status `todo` | The claim in `tasks:tick` | 196 s, 245 s | The tick claims these groups anyway. The request becomes a database write, and the next tick claims the group. A tick starts every 10 seconds unless one still runs |
| `tasks:cancel`, `tasks:complete` | `instance.destroy`, started by `tasks:tick` | 199 s, 192 s | The slow part is the workspace removal; see [Task workspaces use instance.destroy](#task-workspaces-use-instancedestroy) |
| The runtime wake of a hibernated Instance | One unique queued job per Instance on the `tasks` queue, with a budget capped at 300 seconds and at most one wake at a time | No Activity entry | It runs after the response, with no deadline. The wake page and the failure page stay |
| `gateway:desired-fleet-state` | A cache read; the scheduled resolver fills the cache every 5 minutes | 1.1 s, about 70 s with a cold cache | `gateway:status` and the CLI version header already read only the cache |

The runtime wake does not use the `operations` queue: a browser that waits for its app must not wait behind a deploy. It must not starve the `tasks` queue either, whose 3 processes also run `tasks:tick`, `orbit:auto-deploy`, `orbit:operations-sweep`, and `orbit:activity-finalize-interrupted`. The wake job enforces two limits itself, with no new supervisor:

- **Budget.** Let B be `min(orbit.hibernation.cold_wake_timeout_seconds, 300)` seconds. The existing setting is environment-overridable, defaults to 1,800, and has a minimum of 60. It becomes the total wake budget, not a fresh allowance for each restore step. Both warm and cold wakes run inside one `CommandDeadline::within(B)`. Inspection, Composer, Vite+, Process starts, readiness, and marker writes share that deadline. Readiness keeps its own `orbit.hibernation.wake_timeout_seconds` cap (60 seconds by default), but gets only the smaller of that cap and the remaining total budget. Its loops, sleeps, and probes must respect both deadlines; wrapping SSH calls alone does not bound the loops. The job derives `$timeout = B + 30`, below the supervisor's 1,700 seconds. At the cap these are 300 and 330 seconds. Tests cover a lower configured budget as well as the cap.
- **Concurrency.** The job uses Laravel's `WithoutOverlapping` job middleware with one key for every wake, so at most one wake runs at a time. A second wake job is released and tries again after 10 seconds. The lock expiry is derived as `$timeout + 30` (B + 60, at most 360 seconds), so it outlives the worker timeout. A slow cold wake delays a warm wake behind it by up to B, at most 300 seconds of work, plus queue scheduling; more wakes ahead add more delay. A killed worker can leave the lock until its expiry. No priority or fairness guarantee is added.
- **Retry window.** Each job stores one `retryUntil` timestamp at dispatch, 15 minutes after dispatch. Releases never extend it. Laravel uses it instead of the supervisor's `tries` of 1. With more than three cold wakes each needing the full 300 seconds, jobs still waiting can exhaust that window before starting. Expiry is checked when a worker next reserves the job; it is not a timer that interrupts a running wake at minute 15. The job then fails, releases its per-Instance uniqueness lock, and records a queue-wait error in the existing wake failure store, even if its handler never ran. Work-budget exhaustion and worker timeout use the same failure path. A cold marker stays until a wake succeeds, and a retry can use the dependency caches.
- **Uniqueness and pages.** One queued or running job per Instance prevents browser polls from adding duplicates. Waiting keeps the existing 401 progress page, state `pending`, and one-second polling. A failure is stored for up to 120 seconds and consumed once: the next intercepted request gets the existing 503 failure page, state `failed`, with the reason and Try again link. That request also queues a fresh wake, as today; the failed page stops polling. Today the progress script consumes the failure in a fetch and then navigates with another GET, which can hide the one-shot error. The wake PR must display the fetched failure page and stop polling on state `failed`, so the waiting user sees the reason before choosing Try again. Try again adds `orbit-wake-retry=1` and returns to progress for that wake, subject to any new failure. It does not reset or extend the expired job. Once the awake marker exists, Caddy passes requests to the app. The implementation tests queue expiry before the handler, work timeout, uniqueness release, and the browser transition from progress to the one-shot failure and Try again.

So only one of the 3 `tasks` processes does wake work, for at most B + 30 seconds per job. Contending jobs release promptly, leaving the other processes available for the tick, automatic deploys, and sweeps. The [Gateway queue](/reference/gateway-queue#queues) table and [hibernation wake reference](/reference/app-dev-runtime-hibernation#wake) change in the wake implementation PR, including the setting's new total-budget meaning.

### Commands that stay in the request

A command stays when explicit timeouts bound its worst case to 180 seconds or less. That is well inside the new forward budget of 250 seconds: the 270-second command deadline minus the 20-second cleanup reserve. A command that stays never relies on the 900-second defaults or on the command deadline. `doctor` is the one exception. It reads every Node, so it may run until the command deadline; the deadline then ends its remaining probes, and they report a failed inspection.

Every bound in the table below is a bound on one remote step, and it lives in one place. For an [ADR 0211](/decisions/0211-ship-node-changes-as-remote-programs) remote program, this ADR requires the bound in the program's `Timeout` header, with no duplicate step timeout at its call sites. `RemoteProgramCatalog` reads the header. Separately, the runner API allows a caller to request less time: `SshProgramRunner` takes the smaller of that request and the header, and the command deadline still caps the work ([Remote programs](/reference/remote-programs)). That API capability does not move the step's declared bound out of its header. A step that is still an inline script keeps its timeout at the call site until it moves into a program; the ADR 0211 ratchet lists those files. A pull request of step 5 that changes an inline script moves it into a program and puts the bound in the header.

The Node's source lock is a blocking lock today, and operations hold it for many minutes. A command that stays takes it with a wait of at most 30 seconds and answers 409 while an operation holds it.

| Commands | Longest seen | Bound to add |
| --- | --- | --- |
| `doctor` | 126 s for a fleet run, 37 s for one Node | None. Each probe already has its own cap |
| `tool:install`, `tool:update`, `tool:remove`, `tool:adopt`, `tool:scan` | 38 s, 8 s, 6 s (failed), 1 s, 4 s | A budget of 150 s per command; apt waits at most 60 s for the dpkg lock. A download that the budget stops keeps its files, so the same command continues it |
| `process:create`, `process:update`, `process:start`, `process:stop`, `process:restart`, `process:destroy`, `process:logs` | 68 s, never run, 6 s, 17 s (failed), 26 s, 6 s, 15 s | 30 s per SSH call and 120 s per image pull, at most 180 s in total; the timed source lock for `vp-dev`. Docker keeps pulled layers, so a retry continues |
| `schedule:*` | 4.9 s | Already explicit, at most 150 s |
| `firewall:allow`, `firewall:deny`, `firewall:remove`, `firewall:live:list` | 2 s, 1 s, 1 s, 10 s | 30 s per SSH call |
| `node:settings`, `node:wireguard-key`, `node:metrics`, `node:rename`, `node:access:*` | 1 s, never run, 10 s, 0.1 s, 0.1 s | 30 s per SSH and local call, and a timed lock instead of a blocking one |
| `env:sync`, `env:import`, `env:update`, `instance:update`, `instance:database:*`, `instance:logs`, `instance:queue`, `instance:release:list`, `instance:dependencies:scan`, `instance:rename` without a new domain | 1.1 s, 0.9 s, 0.1 s, 0.1 s, 0.1 s, 10 s (failed), 34 s, 12 s, 66 s, 0.1 s | 30 s per SSH call of `env:sync`, `env:import`, and `instance:release:list`; the timed source lock for `instance:dependencies:scan` and `instance:rename` |
| `database:create`, `database:destroy`, `database:user:create`, `database:update`, `database:server:destroy`, `database:query`, `database:schema`, `database:tables`, `database:describe` | 0.4 s or less | A 60-second timeout on statements, except the copy that clone uses; a statement timeout for `database:query`; one call for `database:schema`; SSH timeouts in `database:update` |
| `metrics:status`, `metrics:credentials:reset` | 13 s, never run | A 20-second limit on each Grafana call |
| `vault:*`, `conn:*`, `github:app:*`, `analytics:credentials:*`, `instance:analytics:stats`, `proxycli:*` | 16 s | A total budget of 120 s for `vault:sync`, whose scheduled run continues the rest; `conn:pairing:revoke` marks expired links locally instead of calling T3 for each; `github:app:*` reads a bounded number of pages |
| `extension:enable`, `extension:disable` | 0.05 s | The hooks of [ADR 0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension) amendment A3 keep their fleet work in the request, with explicit timeouts of at most 180 s, and follow the `doctor` rule below |
| `tasks:subtask:create`, `tasks:subtask:update`, `tasks:create` in backlog | 0.7 s, 3.5 s, 11 s | The deliverable check reads the file tree once per request, and Git gets 20 s per call |
| `tasks:subtask:cancel`, `tasks:check:cancel`, `tasks:comment:create`, the push of approved work in `tasks:cancel` and `tasks:complete`, `project:create`, `project:document:*` | 7.3 s or less | 20 s per Git call and per agent call; the object store already has 30 s per call |
| `tasks:agent-stream` | 20 s per connection | None. A connection ends after 20 s, and the browser reconnects |
| `gateway:release:smoke` | 1.6 s | Already derived from the command deadline: at most 90 s by default |
| `gateway:release:deploy`, `gateway:release:rollback`, `fleet:rollout:resume` | 0.1 s | None. The work already runs in a systemd unit |

The fleet work of an extension hook grows with the fleet, as `doctor` does. So both follow one rule: when a run passes 180 seconds, it works on its Nodes in parallel in the request. Neither moves to the queue.

Every other endpoint is a database read or write.

### An operation is the only record of its run

The maintainer decided on 16 Oct 2026: an operation is the only record of a long run. The pull request that converts a command replaces that command's own progress or history records with the operation. It does not keep them beside it. Data of a converted command falls into three classes:

1. **Run progress and run history** go into the operation: how far a run got, what it produced, and why it failed. These become steps, step output, `state`, `input`, and `result`.
2. **Domain state** stays on the domain record: the current state of a record that other code reads, such as the status of a Node, a role, or an Instance, the serving state of a Route, a Node's footprint, the dependency inventory, or a desired setting. A pointer from a domain record to the operation that changes it is domain state too.
3. **Work owed beyond an operation** stays as a small record of its own. Such a record stays when it names work that another run must finish at a time Orbit cannot bound, such as cleanup on a machine that is down, or when guards must keep refusing until that work is done. It names the work and the target, it is not a copy of an operation, and the run that finishes the work deletes it.

| Record | Command | Class | Outcome |
| --- | --- | --- | --- |
| `instance_deployments` | Deploy, rollback | 1 | Replaced; see [The deployment history](#the-deployment-history) |
| `instance_removals`, `instance_removal_members` | Instance removal | 1 | Replaced; see [The removal ledger](#the-removal-ledger) |
| The Node files of a removal: journal, receipt, and quarantine under `.orbit-removals` | Instance removal | 3 | Stay on the Node. The removal ID and member keys that name them pass from a failed `instance.destroy` to its retry |
| `instances.provisioning_step`, the `failed_step` and `error_code` of a create, and `InstanceCreationRecovery` | `instance:create` | 1 | Replaced by `instance.create`. `failed_step` keeps only the value `setup` |
| `failed_step` `setup` and `first_setup_pending` on an Instance | `instance:setup`, `instance:create` | 2 | Stay. They say that setup is incomplete, which can last for weeks |
| `route_removal_residues` | `route:destroy --offline` | 3 | Stays. It names cleanup owed on a Node that may be down for weeks; `node:converge` finishes it, and Doctor reports it |
| `instance_transfers` status, steps, and identical-request check | `instance:transfer` | 1 | Replaced by the operation |
| Open transfer debt: a source cleanup after cutover, or a rollback that did not finish | `instance:transfer` | 3 | Stays as a debt record that blocks removal, rename, and Route removal until a retry clears it |
| `project_updates` | `project:update` | 1 | Replaced. A rollback that is still owed becomes debt of class 3 |
| `provisioning_step` values `clone-*` and the clone input columns | `instance:clone` | 1 | Replaced. `clone_candidate_id` stays as lineage, and `active` stays as the Instance status |
| Registration relocation state, request ID, and digest checks | `instance:register` | 1 | Replaced. The worktree set and repository identity stay, because removal and transfer read them |
| `nodes.failed_step`, `nodes.error_code`, `database_servers.failed_step`, `database_servers.error_code` | `node:add`, `database:server:create` | 1 | Replaced. The statuses stay |
| `node_roles.failed_step` with its `converge:` or `remove:` prefix | Role commands, `cluster:router:*` | 2 and 1 | The prefix stays, because Caddy and Ingress read it to decide whether a role still serves. The step and code move to the operation |
| The Router replacement phase | `cluster:router:set` | 2 | Stays, because every Caddy build and DNS publication reads it |
| Route transition columns, `node_footprints`, Metrics degradation settings, the Plausible version pin, the dependency inventory | Several | 2 | Stay |
| The prior state that a failure restores, such as a Node's earlier network and the earlier Plausible version | `node:add`, `analytics:update` | 1 | Moves from memory into operation `state`, so the next attempt can still undo the change after a worker is lost |
| What stays on an unreachable machine after `--offline`, and role follow-ups | `node:remove`, `node:role:remove`, `node:role:relocate` | 1 | Goes into the operation `result`. Today it is lost when the response is read |

Leftovers of an offline role removal on a Node that stays managed, and an unfinished relocation, are work of class 3 that no record holds today. They are outside this ADR.

Activity stays. A start request still writes its Activity entry. The output of a failed remote command moves from the Activity entry to the step output of the operation.

Role claims follow the same rule. A claim that an add or remove takes under the Node's role lock needs no time window, as today. A claim that relocation or a Cluster Router change holds outside the lock records its operation, and it is stale when that operation has ended. `orbit:bootstrap` keeps its claims as today, because it sets the Node to `provisioning` first. This replaces the time window `NodeRole::StaleClaimSeconds`, which assumes that relocation and Cluster Router changes end with their request.

#### The deployment history

A pull request of its own replaces `instance_deployments`. The `instance.rollback` operation of ADR 0209, which it waits for, landed in [#1301](https://github.com/nckrtl/orbit/pull/1301). Deploy and rollback write their rows through `OperationDeploymentHistory`, which goes away with the table.

- **What the events hold.** The `events` JSON of a deployment holds phase markers, which match the step names, and the output of the run: in order, tagged `stdout` or `stderr`, with a truncation marker, and with failure warnings kept past the 128 KiB cap. It has no timestamps. Today's 8 KiB step tail loses the head of long steps, such as the 33 to 37 KiB of `composer install`, and the stream tags. The step log below keeps both. Only the free label of an output event, such as `checkout`, is dropped.
- **Result.** `instance.deploy` and `instance.rollback` carry the release, branch, commit, and selected release in `result`. A pinned commit stays in `input`. `result.deployment_id` goes away.
- **Step log.** Each operation step gets a `log` with its full output, tagged `stdout` and `stderr`. The logs of one operation share a cap of 128 KiB. At the cap, the log keeps the head and the failure warnings, as the deployment log does today. Only an explicit read returns it: `GET /api/v1/operations/{operation}?log=1`, `operation:show --log`, and `operation-show` with `log`. Polls and MCP replies keep the 8 KiB tail, so they stay small. Every kind gets the log, not only deploys.
- **One read.** `instance:deployment:list` and `instance:deployment:show` go away in the API, CLI, SDK, MCP, and web app. `operation:list` with the Instance as target lists every kind, and `operation:show` shows one operation with its steps and log. The web app gets an Operations pane on the Instance and an operation page in place of the Deployments pane. The realtime events `deployment.created` and `deployment.updated` go away; the `operation.*` events cover them.
- **Time order.** `operation:list` and every history reader order by `created_at`, then ID. Migrated rows are older than the operations that exist today, but they get higher IDs, so an order by ID would be wrong.
- **Automatic deploys.** [Automatic deployments](/reference/deployments#automatic-deployments) read operations. "Was this commit tried" and the failure count read the `instance.deploy` operations that `orbit:auto-deploy` started. A failure counts only when a worker started the operation and it ended `failed`; a cancelled, superseded, or never-dispatched operation does not count. The transient-failure rule takes the boundary from the failed step's name. A manual deploy or a rollback created after the resume time pauses the Instance. `instances.auto_deploy_resumed_after`, an ID, becomes `auto_deploy_resumed_at`, a time. `auto_deploy_state.pause.deployment_id` becomes `operation_id`, and a release alert names `operation-<id>`. The blocked commits in `auto_deploy_state` stay domain state.
- **Migration.** Each existing row becomes a finished operation: kind `instance.deploy` or `instance.rollback`, the Instance as target, its serving Node as `node_id`, no `lock_key`, the result above, and one step per recorded phase with its log. The events have no timestamps, so migrated steps have no start or finish time. A row that an operation already points to through `result.deployment_id` updates that operation instead. The stored `duration_seconds` is always 0 because of a sign error, so the migration does not copy it; the duration is `finished_at` minus `started_at`.
- **Retention.** Today the history keeps 50 rows per Instance, and operations keep everything. The Gateway keeps the newest 50 finished operations of each target, ordered by `created_at`, then ID, and deletes older ones. It never deletes an operation that a domain record points to, such as the failed removal of an Instance that is still `removing`.

#### The removal ledger

The `instance.destroy` pull request of ADR 0209 replaces `instance_removals` and `instance_removal_members` with the operation.

- The mode goes into `input`. The accepted inventory, its digest, the removal ID, and one key per member go into `state`. Each member's five steps become operation steps, such as `0.source_preparation`. The Route outcome and the finalization receipt go into step output and `result`.
- Every Instance in an accepted removal keeps status `removing` and points to its removal operation. The guards, the database triggers, and the "Route unavailable" site read that pointer instead of an open member row.
- An operation is terminal once it fails. A retry is a new `instance.destroy` that adopts the inventory, the removal ID, the member keys, and the finished steps of the operation that the Instance points to. The Node's removal files keep their names, so the retry can prove that it owns them.
- `ForgetTaskVmWorkspacesAction`, the second writer of the ledger, becomes steps of the task VM branch.
- Create cleanup and the removal step of a Route target change run the same step code inside their own operation. They do not start a nested `instance.destroy`, which would wait for the one worker that runs them. So this pull request comes after the `instance.create` and Route convergence pull requests, and it changes their callers too.
- A production removal records the home it keeps in `result`.

### Task workspaces use instance.destroy

The maintainer decided on 16 Oct 2026: a task workspace removal uses the same `instance.destroy` operation as every other removal. There is one removal path and one record.

- `instance.destroy` takes the task VM branch itself when a live task VM holds the workspace: guest teardown, transcript archive, VM delete, and workspace records. Then it queues `DestroyTaskVm`. Otherwise it takes the generic branch. So `instance:destroy` of a task workspace also archives its transcripts and deletes its VM.
- `tasks:cancel` and `tasks:complete` end the group in the request and answer at once. They start no operation. Only `tasks:tick` and `DestroyTaskVm` start the `instance.destroy` of a task workspace, under rule 3 of [Keep deploys moving on one worker](#keep-deploys-moving-on-one-worker). Every Tasks call site in the table below hands its removal to them. When the removal fails, the group gets the reason "Workspace removal failed" with the operation's error code.
- A parked VM never holds the worker. For a parked VM, the tick queues `ResumeTaskVm` and starts `instance.destroy` only when the VM is ready. A removal that a person starts on a parked VM refuses with `task_vm.parked` and queues the resume, as today.
- The push of approved work stays in Tasks and runs before the removal starts.
- `ParkIdleTaskVmsAction` does not park a VM while an `instance.destroy` of its workspace is queued or running.
- The removal backoff reads the failed `instance.destroy` operations of the workspace instead of its cache entry.
- `DestroyTaskVm` stays a `task-vms` job. It removes the Node offline and retries without limit, so it is work owed beyond an operation. When its VM is gone but a workspace is left, it starts `instance.destroy` for that workspace and retries on its own backoff, instead of writing the ledger itself.

The call sites that change, all in the `instance.destroy` pull request:

| Call site | Runs in | Today |
| --- | --- | --- |
| `CancelTaskGroupAction` | `tasks:cancel` request | Removes the workspace, an Instance that a racing claim attached, and an unattached leftover on a repeated cancel |
| `CompleteTaskGroupAction` | `tasks:complete` request and `tasks:tick` | Removes the workspace under `TaskExecutionLock` |
| `TaskSettling::tickPullRequest` | `tasks:tick` | Completes a group whose watched pull request ended |
| `TaskSettling::completeMergedGroup` | `tasks:tick` | Merge cleanup with a backoff per Instance |
| `TaskSettling::removeAbandonedWorkspaces` | `tasks:tick` | Sweeps ended groups, with a 60-second start budget and a backoff |
| `TaskClaimAdmission::removeEndedWorkspace` | `tasks:tick`, and today the `tasks:create` and `tasks:update` requests | Removes a workspace whose group ended while its claim ran |
| `RemoveTaskWorkspaceAction::remove` and `removeWithTaskVm` | The callers above | The shared entry: task VM branch or the generic forced remover |
| `DeleteTaskVmAction`, `ForgetTaskVmWorkspacesAction` | The callers above and `DestroyTaskVm` | VM delete, and the second writer of the ledger |
| `DestroyTaskVm` | `task-vms` job | The dead-VM path deletes the VM and forgets its workspaces through `DeleteTaskVmAction`, which writes a removal without SSH |
| `ParkIdleTaskVmsAction`, `DestroyEndedTaskVmsAction` | `tasks:tick` | Read the removal-failed reasons |

Two callers outside Tasks use the remover too and change in the same pull request: `CreateInstanceAction` for failed-create cleanup and `ConvergeRouteTargetSetAction`.

Task workspace provisioning stays in the claim of `tasks:tick`. It does not use `instance.create`, because a claim starts a group and must not wait behind deploys. It keeps writing the Instance's provisioning columns, which `instance.create` no longer writes. It is the one long run without an operation, and the maintainer decides it separately.

### Keep deploys moving on one worker

The `operations` queue keeps one worker. Memory is one reason: each worker costs about 50 MB. Order is the other: Instances behind one Route deploy one after another, and ADR 0217 rejected a second worker. Three rules keep other work from holding up deploys. They need no new queue, worker, or priority.

1. **No operation spans the fleet.** `doctor` stays a read in the request. The fleet rollout stays in `orbit-fleet-converge.service`, and problem collection stays in the scheduler.
2. **Each operation is capped.** A kind of this ADR caps its run at 570 seconds, as [Kind names, targets, and deadlines](#kind-names-targets-and-deadlines) says. A deploy therefore waits at most about 10 minutes behind most operations. Behind the longest it waits up to about 27 minutes: another deploy, a dependency update, or a create, registration, removal, or clone that runs a full list or copy. ADR 0217's stall alert fires after 30 minutes, so one long operation ahead does not raise it.
3. **Background work yields.** Work that no person or agent waits for starts one operation at a time, and only while the `operations` queue holds no job, waiting or running. The check and the start run under one cache lock, so `tasks:tick` and `DestroyTaskVm` never both start one. A task workspace removal is such work, also after `tasks:cancel` or `tasks:complete`, because the group ends at once. `DestroyTaskVm` follows the same rule when it starts a removal. A burst of ended groups therefore queues no burst of removals: the tick starts the next removal only when the worker is idle. A deploy that arrives can still wait for the one removal that runs, 52 to 196 seconds in the 9 Oct burst, at most 1,110 seconds when it runs a full teardown list. A burst never adds more. A command that a person or agent runs starts its operation at once, in order. Automatic deploys are the one exception: they start as they do now, even while the queue holds a job.

Horizon already flags a wait of more than 300 seconds on the `operations` queue. If such waits become common, the next step is a queue of their own for deploys that the same worker reads first. Horizon supports this order. It is not built now.

### The queue is required

Every command that this ADR or ADR 0209 turns into an operation needs the Gateway queue, as deploys already do. Without `ORBIT_GATEWAY_QUEUE_CONNECTION`, its start request answers `503` with `operation.queue_unavailable`, and no fallback runs it in the request. A new Gateway sets up its queue right after its first Node:

1. Provision the Node with `php artisan orbit:node-provision`. It runs outside PHP-FPM and needs no queue.
2. Create the Valkey Process on that Node with `process:create`, which stays a request.
3. Register the Process as a Redis Database connection with `database:create`.
4. Set `ORBIT_GATEWAY_QUEUE_CONNECTION` to that connection, and run a release, which starts Horizon.

The [quickstart](/quickstart) gets these steps in the first pull request that needs the queue for a quickstart command: the `instance.create` pull request of ADR 0209, or the `node.add` pull request.

### MCP

No MCP tool call holds a request open for long remote work.

- Each converted tool returns at once with `{operation, poll}`, as `instance-deploy` does. The generators find these tools from the API. A controller that answers 202 with an operation marks its endpoint. `bin/docs-openapi` writes the mark into the OpenAPI document, and `bin/mcp-tools` copies it, so no tool is written by hand.
- The agent polls `operation-show` with `after_sequence` after `poll.after_seconds`, until the status is terminal. `operation-list` with `status=running` finds lost operations, and `operation-cancel` stops one. The contract of [Operations](/reference/operations#follow-an-operation-by-polling) does not change.
- The server instructions describe this once for every tool that starts an operation, instead of naming `instance-deploy` and `instance-rollback`. A session that started before keeps its old instructions, but the `poll` field of each reply names the next call.
- One MCP request gets the new command deadline of 270 seconds, and an `execute_tools` batch shares it. Most tools that remain take seconds, so a typical batch fits. An agent sends `doctor` and other tools that can take minutes as separate requests.

### CLI

Each converted command starts its operation and follows it with the shared follow code, as `instance:deploy` does: over Reverb, otherwise by polling. Ctrl-C stops following, not the operation, and prints the command that shows it. The exit status is 0 when the operation succeeded, 1 when it failed or was cancelled, and 130 after Ctrl-C. `--json` prints the final operation. A command that creates a record, such as `node:add`, `instance:clone`, `instance:register`, or `database:server:create`, prints that record when its operation succeeds. `operation:list` filters by any target, not only by Instance.

### Older CLIs

A CLI never runs ahead of its Gateway, because `orbit self-update` installs the Gateway's own release. An older CLI can run behind it.

- **The CLI follows any operation.** From the first pull request of this ADR on, the CLI follows any 202 answer that carries an operation, for every command, with the shared follow code. So a command that converts needs no CLI change to work, and the CLI release that contains this pull request handles every conversion of this ADR.
- **The Gateway knows the CLI from headers it already gets.** The SDK identifies CLI requests with `X-Orbit-Client: cli`. SDK versions before 2026-10-07 did not send `X-Orbit-Client-Version`; commit `5fbc424f` added it. Current CLIs supply a version, including a present non-release value from `git.version` in a source checkout. `AnnounceDesiredCliVersion` already reads the version header to announce a newer release. A release version has the form `0.N.0`, where N is the commit count of its commit, and `CliReleaseNotice` in the CLI already compares versions by N. No new header is added.
- **Only converted endpoints refuse.** Each endpoint that this ADR converts names its minimum CLI version, at first the CLI release that contains the first pull request. A route middleware beside `AnnounceDesiredCliVersion` refuses a request when `X-Orbit-Client` is `cli` and `X-Orbit-Client-Version` is absent or is a release older than that minimum. It refuses before the controller writes anything. A CLI with a present non-release version is allowed, so source checkouts keep working. A release at or above the endpoint minimum is allowed. Non-CLI requests, including MCP and the web app, keep their existing behavior regardless of the version header. `instance:deploy` and `instance:rollback` are never refused: they are operations already, every CLI since ADR 0209 follows them, and they are the emergency path.
- **The refusal.** The Gateway answers `409` with `client.update_required` and `details.minimum_version` and `details.client_version`. For an absent header, `details.client_version` is null. An older CLI prints the error message and exits 1: "This command now runs as an operation and requires Orbit CLI 0.N.0 or newer. Run `orbit self-update`, then run the command again." The message works for both a missing and an old release version. Tests cover absent, present non-release, below-minimum, and supported release versions, plus non-CLI requests and the deploy/rollback exemptions.
- **Order.** The first pull request lands before any other command converts, so its CLI release exists, and the fleet rollout has given it to managed Nodes, before the first endpoint refuses anything. Rollback converted before it, in #1301, as deploy did.

This refuses an unsupported combination instead of keeping an old path, as [No exceptions and no legacy](/mission#principles) asks.

### The new limit: 300 seconds

The Gateway's PHP-FPM request limit drops from 600 to 300 seconds.

- Every command that stays has an explicit worst case of at most 180 seconds, and a fleet `doctor` took 126 seconds. A forward budget of 250 seconds covers them with 70 seconds to spare.
- The release smoke keeps its 90-second default, which needs a limit of at least 170 seconds.
- Once nothing long runs in a request, a request that ends normally frees its worker anyway. The limit then only matters for a request that hangs, and 300 seconds halves the time that such a request lives.

| Value | Today | New | Rule |
| --- | --- | --- | --- |
| `request_terminate_timeout` | 600 | 300 | The limit N |
| `max_execution_time` | 600 | 300 | N. It counts CPU time on Linux, so it is no bound on wall time |
| Caddy `read_timeout`, `write_timeout` | 600 | 300 | N |
| `orbit.command_timeout` | 570 | 270 | N − 30 |
| Forward budget in a request | 550 | 250 | `command_timeout` − the 20-second cleanup reserve |
| `orbit.activity_interrupted_after` | 900 | 480 | More than 4N/3 + 60, because PHP-FPM checks the limit at least every N/3 |
| `NodeLocks::RequestSeconds` | 600 | 300 | N. The Gateway renews the lock before each command, and no command in a request runs longer than the forward budget, so the term never ends under a live command |
| Smoke limit of an API request | 90 | 90 | The smaller of the configured value and N − 80 |
| `NodeRole::StaleClaimSeconds` | 660 | Removed | A claim outside the role lock is stale when its operation has ended |
| Setup and teardown lists | 540 | 540 | No list runs in a request any more; the kinds that run one add its total to their cap |
| CLI and SDK timeout | 900 | 900 | Above N |

The limit pull request adds the limit N once, in `config/orbit.php`. The PHP-FPM renderer, the Caddy renderer, `command_timeout`, `activity_interrupted_after`, `NodeLocks::RequestSeconds`, and the smoke limit derive from it, and a test checks each derivation. Today 600 is written separately in each of these places.

The 900-second defaults of SSH and process calls, the 1,200-second console term of Node locks, and the 1,600-second operation deadline do not change.

### Before the limit drops

The limit pull request comes last. Before it merges:

1. Every pull request of steps 1 to 5 in [Build order](#build-order) has been released.
2. No endpoint runs work after its response in PHP-FPM.
3. Activity shows no request that ran longer than 180 seconds in the 7 days before.
4. The refusal of older CLIs is live.

The release of the limit reloads PHP-FPM, which ends the requests in flight. Release it while no long request runs.

### Build order

Each step is one pull request. Steps 2b to 5 start after step 1, and each step also waits for the steps in its "Waits for" column. Pull requests that share files merge one after another; the others merge in any order.

| Step | Pull request | Waits for | Shares files with |
| --- | --- | --- | --- |
| 1 | Foundation: the CLI follows any operation, the refusal middleware for older CLIs, generic MCP server instructions, `operation:list` by any target, the kinds table in [Operations](/reference/operations) | Nothing | Every conversion after it |
| 2a | ADR 0209 `instance.rollback`, merged in #1301 | Done | 2b, 3 |
| 2c | ADR 0209 `instance.create` | 1 | 2b, 4a, 4c |
| 2d | ADR 0209 Route convergence; `route_removal_residues` stays | 1 | 2b, 4h, 4i, 4k, 4l |
| 2b | ADR 0209 `instance.destroy`, with the removal ledger and the Tasks call sites of [Task workspaces use instance.destroy](#task-workspaces-use-instancedestroy) | 2c, 2d | 2a, 2c, 2d, 3, 4a, 4b, 5a |
| 3 | The deployment history: `instance_deployments` gives way to operations, the step log, time order, retention, and ADR 0217's reads | 1 | 2a, 2b |
| 4a | `instance.setup` and `instance.register` | 2b | 2b, 2c |
| 4b | `instance.transfer`, with its debt record | 1 | 2b |
| 4c | `instance.clone` | 2c | 2c |
| 4d | `instance_dependencies.update` | 1 | The common files |
| 4e | `node.add` and `node.remove`, with the web app's Node form | 1 | 4f |
| 4f | `node_role.add`, `node_role.remove`, `node_role.relocate`, `metrics:enable`, `metrics:disable`, `analytics.update`, and claims owned by operations | 1 | 4e, 4g, 4h, 5e |
| 4g | `node.converge` and `metrics_exporter.*` | 1 | 4f |
| 4h | `cluster_router.set`, `cluster_router.unset` | 2d, 4f | 2d, 4f, 4i |
| 4i | `cluster.update`, `cluster_node.add`, `cluster_node.remove` | 2d | 2d, 4h |
| 4j | `database_server.create` | 1 | The common files |
| 4k | `instance_analytics.*` | 2d | 2d |
| 4l | `project.update` | 2d | 2d |
| 5a | `tasks:create` and `tasks:update` stop claiming in the request | 1 | 2b, 5g |
| 5b | The runtime wake as a queued job | 1 | None |
| 5c | `gateway:desired-fleet-state` reads the cache only | 1 | None |
| 5d | Program headers, or call-site timeouts for inline scripts, for Tools and Processes, and the timed source lock | 1 | None |
| 5e | The same bounds for Node, firewall, and Metrics commands | 1 | 4f |
| 5f | The same bounds for database, Vault, Conn, and GitHub App commands | 1 | None |
| 5g | The same bounds for the Tasks endpoints that stay, `project:create`, and `env:sync` and `env:import` | 1 | 5a |
| 5h | The same bounds for the extension hooks of ADR 0212 amendment A3 | ADR 0212 build step 4 | None |
| 6 | The limit | Every step above | None |

Every conversion edits the same common files: the kind registry `OperationKinds`, the kinds table in [Operations](/reference/operations), the descriptions in `bin/docs-openapi`, the CLI step labels, and the Activity special cases in `RecordCommandActivity`. Each pull request incorporates current main before it merges. It regenerates `docs/openapi.json`, `apps/gateway/resources/mcp/tools.json`, `apps/web/src/api/schema.d.ts`, and `docs/generated/context.json` instead of merging them by hand.

## Rejected alternatives

- **Keep 600 seconds.** The limit then hides missing bounds. A hung call holds one of 8 workers for up to 800 seconds, and Activity marks it only after 900.
- **A limit of 120 or 180 seconds.** 120 closes the OPcache restart gap of a Gateway release, because no request then outlives the 180-second `opcache.force_restart_timeout`. But 120 cuts the smoke default, and both need tighter bounds on `doctor`, schedules, and Process restarts. Neither gains much once no long command runs in a request.
- **Tools and Processes as operations.** They are short, and they come in parallel bursts across Nodes: 86 `tool:update` calls ran in 2 minutes on 10 Sep 2026. On one worker, that burst takes about 6 minutes and holds every deploy. A stopped download keeps its files, so a retry continues it. The Valkey Process of the queue is itself a Process, so `process:create` must work without the queue.
- **`doctor` as an operation, or one request per Node.** It only reads, so a lost run costs nothing. As an operation, it holds the one worker for 2 minutes. One request per Node loses the checks that span Nodes, such as a Route on its Router.
- **A second operations worker, or a separate queue for Node work.** Two workers let Instances behind one Route deploy at once. A separate queue needs the dispatcher, the job, and the sweeper to know several queues, and moves the wait to other work.
- **Put deploys on a queue of their own that the same worker reads first, now.** Horizon supports it, but the sweeper must then count two queues. Live waits do not justify it yet.
- **One debounced job for the fleet Metrics reconcile.** It shortens every command that reconciles inline, at 16 call sites, but it is a new mechanism. On one worker, the reconciles inside operations never overlap anyway.
- **The runtime wake as an operation or a systemd unit.** As an operation, a browser waits behind a deploy. A unit per wake needs its own lock, logs, and recovery, which the queue already gives.
- **A new header that says the CLI follows operations.** It is a second version signal beside `X-Orbit-Client-Version`, and it refuses every CLI that predates it, also for `instance:deploy` and `instance:rollback`.
- **Refuse older CLIs on every endpoint that starts an operation.** It blocks deploy and rollback, the emergency path, on CLIs that already follow them.
- **A supervisor of its own for the runtime wake.** It isolates the wake fully, but each supervisor costs a supervisor and a worker process, about 100 MB, while idle. A budget and one lock keep the wake on the `tasks` queue without that cost.
- **Keep a synchronous or streaming path for older CLIs.** It keeps work in PHP-FPM, so the limit cannot drop, and it doubles the code that recovery covers.
- **Answer older CLIs with the target record and put the operation under another key.** It breaks the rule that a start answers with the operation, and the MCP and OpenAPI generators rely on that rule.
- **Break older CLIs, as the deploy change of ADR 0209 did.** An older CLI prints an operation ID as a Node or Instance ID and exits 0, which misleads automation.
- **Keep `instance_deployments` beside the operation.** Two records of one run drift, and ADR 0217 reads a second history.
- **Keep `instance:deployment:list` and `instance:deployment:show` as reads of operations.** That gives one record two names and two reads.
- **A larger step tail instead of a log.** Every poll then carries up to 37 KiB of `composer install` output.
- **A log in a file or object store.** The database is the truth about an operation, and a second store needs its own backup and cleanup.
- **Replace `route_removal_residues`.** It names cleanup owed on a Node that may be down for weeks. A terminal operation cannot keep refusing, and nothing then tells `node:converge` what to remove.
- **A nested `instance.destroy` inside another operation.** The calling operation holds the only worker, so the nested one waits for its own caller.
- **Wait for a parked task VM inside `instance.destroy`.** The resume takes up to 540 seconds, and the one worker waits idle meanwhile.

## Consequences

- Every long command survives a lost client, a closed laptop, or a lost MCP context. One read finds it.
- No command keeps a run record beside its operation. Operations also keep the full log of each step, with a cap, so a deploy log stays readable after the history table is gone.
- A Gateway needs its queue for every command that runs as an operation. A new Gateway sets it up right after its first Node.
- A deploy can wait behind another operation: about 10 minutes behind most kinds, and up to about 27 minutes behind the longest. Background removals never queue ahead of it, but a deploy can wait for the one removal that runs.
- A task workspace removal may wait behind deploys and other commands. Cleanup is not urgent, so that is acceptable.
- A second command on the same Node or Cluster answers `409` with `operation.target_busy` instead of waiting for a lock: up to 120 seconds for the role lock, and up to 420 seconds before `node:converge` starts.
- A Gateway release waits for the running operation job, because Horizon finishes running jobs first. That wait is at most about 10 minutes for most kinds, and up to about 27 minutes for the longest.
- `tasks:cancel` no longer refuses when the removal fails. The group ends, and the removal reports its failure through its operation and the group's reason.
- PHP-FPM ends a hung request after at most 400 seconds instead of 800, and Activity marks it after 480 seconds instead of 900. An MCP batch gets 250 seconds of forward work instead of 550.
- An older CLI must self-update before it can run a converted command. `instance:deploy` and `instance:rollback` keep working on every CLI since ADR 0209.
- `doctor` and the extension hooks are the commands in the request whose time grows with the fleet. When a run passes 180 seconds, it works on its Nodes in parallel in the request.
- `instance:deployment:*`, its MCP tools, SDK requests, and realtime events go away, and so does `deployment_id` in a deploy result and in an automatic-deploy pause. Scripts that read them must read operations.
- Retention now applies to operations: the newest 50 finished operations of each target stay.
- Task workspace provisioning remains a long run without an operation until the maintainer decides it.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web, apps/docs
- ADRs: [0209](/decisions/0209-run-long-operations-as-durable-queued-operations), which this ADR extends: it fulfils step 5, changes step 3, and replaces the deployment history; [0212](/decisions/0212-turn-proxycli-into-the-ai-router-extension), whose amendment A3 must fit the new budget; retired 0217, now [automatic deployments](/reference/deployments#automatic-deployments), which read operations once the deployment history is gone; [0211](/decisions/0211-ship-node-changes-as-remote-programs), whose program headers hold the step bounds of the commands that stay
- Detail: [Operations](/reference/operations); [Remote programs](/reference/remote-programs); [Deployments: History](/reference/deployments#history); [Instance removal](/reference/instance-removal); [Tasks](/reference/tasks); [Gateway queue](/reference/gateway-queue); [Activity: Interrupted requests](/cli/activity#interrupted-requests); [MCP: Endpoints](/reference/mcp#endpoints); [PHP runtimes: Process management](/reference/php-runtime#process-management); [Node provisioning](/reference/node-provisioning); [App-dev runtime hibernation: Wake](/reference/app-dev-runtime-hibernation#wake)
- Verify: each conversion adds tests for its kind in `tests/Feature/Domain/Operations` and its API test in apps/gateway, and command tests in apps/cli. The limit pull request updates `CommandActivityTest`, `InterruptedActivityTest`, `NativeGatewayWebConvergerTest`, `GatewayReleaseResponseFixturesTest`, and `McpServerTest`. On the live fleet, Activity shows no request longer than 180 seconds in the 7 days before the limit pull request.
