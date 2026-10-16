---
title: "ADR 0209: Run long operations as durable queued operations"
sidebarTitle: "0209 Durable operations"
description: "In progress. A long operation is a database record that a Horizon job runs as named, resumable steps. A request answers 202 with the record. The web app follows it over Reverb, MCP polls it, and the CLI does both. Instance deploy moves first."
---

# ADR 0209: Run long operations as durable queued operations

A long operation, such as an Instance deploy, becomes an `operations` record that a job on the [Gateway queue](/reference/gateway-queue) runs as named steps. The request that starts it answers `202 Accepted` with the record at once. The database is the truth about the operation; Reverb events only tell a client that the record changed. Instance deploy is the first operation. Rollback, Instance create and remove, and Route convergence each move in a pull request of their own.

## Status

In progress.

Principle: [Agents operate, humans steer](/mission#principles). An agent starts a deploy, loses its context or its connection, and still finds the operation, its current step, and its result with one read. It also serves [One way, one name](/mission#principles): every long operation gets the same record, the same three reads, and the same recovery, instead of a stream, a systemd unit, or a recovery class of its own.

## Context

Today the Gateway runs a deploy inside the HTTP request. `InstanceDeploymentsController::store` returns a streamed NDJSON response and runs the deploy in the PHP-FPM worker. That has four costs:

- **A hard time limit.** PHP-FPM ends a Gateway request after 600 seconds, so the deploy deadline is capped at 570 seconds, although an Instance may have 3,600 seconds of step timeouts.
- **A lost client loses the operation.** The Gateway notices a closed connection only when it writes. It then starts no further step and sends no result. Nothing can reattach to the run; the deployment row only appears when the run ends.
- **MCP holds a request open.** `instance-deploy` waits for the whole deploy and returns its events at the end. One MCP request has one 570-second deadline, so a long deploy and a batch of other tool calls compete for it.
- **Recovery is inferred per domain.** Instance creation, Instance removal, Route removal, and interrupted Activity each have their own way to find and finish work that a killed request left behind: `InstanceCreationRecovery`, `InstanceRemovalMember`, `RouteRemovalResidue`, and `orbit:activity-finalize-interrupted`.

The Gateway now has a Redis queue with Laravel Horizon. Gateway releases already follow the target pattern: a request queues a record and answers 202, a separate process runs it, and the caller shows the record until it finishes ([Deploy a release](/reference/gateway-recovery#deploy-a-release)).

## Decision

The Gateway owns one execution model for every long operation: a record, a job that runs its steps, a sweeper that recovers lost workers, a lock per target, and three reads that every client uses. Instance deploy uses it first.

### The operation record

A request that starts a long operation creates one `operations` row and answers `202` with it. The row holds:

| Field | Contract |
| --- | --- |
| `kind` | What runs, `<family>.<verb>`, such as `instance.deploy` |
| `target_type`, `target_id` | The record it changes, such as `instance` and `15` |
| `input` | The validated request body |
| `status` | `queued`, `running`, `succeeded`, `failed`, or `cancelled`. The last three are terminal |
| `sequence` | A counter that grows with every change of the row or one of its steps |
| `current_step` | The step that runs, or null |
| `failed_step`, `error_code` | The step that ended a failed operation and its error code |
| `result` | What the operation produced, such as the selected release |
| `triggered_by` | The calling Node's name |

Each step gets one `operation_steps` row with its `name`, `position`, `status` (`pending`, `running`, `succeeded`, `failed`, or `cancelled`), start and finish times, a bounded output tail, its error code, whether it is `resumable`, and the `sequence` at which it last changed.

A deploy still writes its `instance_deployments` row with the full log, until [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-deployment-history) replaces that history with operations in step 6 below. A domain record that an operation changes, such as the Instance, stays.

### Runs on the `operations` queue

A job, `RunOperation`, runs the operation on a fourth Horizon queue, `operations`, with one process. A deploy can hold a worker for many minutes, so it must not hold a `tasks` worker. A job runs at most 1,700 seconds, under the connection's `retry_after` of 1,800, so Redis never gives a running job to a second worker. The operation therefore has a deadline of 1,600 seconds: a deploy's deadline becomes its step timeouts plus 900 seconds, at most 1,600, instead of at most 570. An operation that needs more time must be split into more jobs by a decision of its own, as the image builds are ([ADR 0206](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs)).

One process is enough, and more would cost memory the Gateway needs. The lock per target already serializes the operations of one Instance, and a single operator rarely deploys two targets at the same time. Each Horizon worker is a PHP CLI process of about 40 to 50 MB. On 14 Oct 2026 the Gateway, then 2 GB with no swap, was OOM-killed with about 18 PHP CLI processes (about 790 MB) beside 8 PHP-FPM workers (about 715 MB). It now has 4 GB, but three idle deploy workers would add about 150 MB for a case that rarely happens. With one process, an operation on a second target waits in the queue until the first one ends, at most 1,600 seconds. Raising the count is a change to `config/horizon.php` alone, once fleets deploy several targets at once.

Operations need the queue. Without `ORBIT_GATEWAY_QUEUE_CONNECTION`, a start request answers `503` with `operation.queue_unavailable` and records nothing. This is the same rule as task VMs. The maintainer decided on 15 Oct 2026 that deploys keep this rule: a Gateway without the queue cannot deploy, and there is no inline fallback.

### Idempotent, resumable steps

The runner claims a queued operation with one conditional update, so a second copy of the job does nothing. It records the worker's host and process ID on the row, for people. Each attempt holds an attempt lease: an exclusive `flock` on a file per operation under `ORBIT_HOME/locks/operations`. The kernel frees the lease when the attempt's code ends, also by an error that escapes it, and when the worker dies. An error that escapes the attempt fails the operation through its kind. When even that write fails, for example while the database is locked, the error goes to the queue, and the free lease lets the sweeper recover the operation.

The runner starts each attempt's deadline with the 20-second cleanup reserve of an API command. When a step runs out of forward time, the recovery after it still has time to finish, such as reading which release an interrupted activation selected.

The operation kind runs its work as named steps. Each step either is safe to run again or detects that its work is done; it declares this as `resumable`. The runner skips a step that already succeeded, so a resumed operation continues where it stopped. A step can store a small JSON value, such as the release it prepared, for the steps after it.

A step that a person writes, such as a deploy step that migrates a database, is never resumable: Orbit cannot know whether it ran. A step that ended `failed` never runs again either. When an attempt ended before it recorded the outcome, the next attempt fails the operation at that step with `operation.worker_lost`.

### A sweeper recovers lost workers

`orbit:operations-sweep` runs every minute on the Gateway queue. It requeues:

- a `running` operation whose attempt lease is free, because its worker died or its code ended without recording the outcome, and
- a `queued` operation whose job was dispatched more than 10 minutes ago and has not started, but only while the `operations` queue holds no job at all, because Redis can lose a job. With one worker, an operation that waits behind a long one still has its job in the queue, so the sweep never adds a second one.

The runner then decides what the interrupted step means. A resumable step runs again. A step that is not resumable fails with `operation.worker_lost`, and the operation fails at that step. An operation that has started three times fails the same way, so a step that kills its worker cannot loop. The kind finishes its domain record either way, so a lost deploy still ends its deployment row as failed, until step 6 below replaces that row.

When a resumed deploy plans different steps than its first attempt, because someone changed the deploy steps in between, it fails with `operation.plan_changed`.

### One lock per target for the whole lifetime

An operation holds its target from the request to its terminal status through a unique `lock_key` column, such as `instance:15`, which the Gateway clears when the operation ends. A second start for the same target answers `409` with `operation.target_busy` and the running operation's ID in `details.operation_id`. When a worker dies, the operation keeps the lock, so the requeued operation still owns its target.

While its job runs, a deploy also takes the Instance's existing operation lock, so environment changes, Route changes, and the scheduled development deploy still wait for it or receive a busy error as before.

### Cancel

`POST /api/v1/operations/{operation}/cancel` asks an operation to stop. A queued operation becomes `cancelled` at once. A running one stops before its next step and becomes `cancelled`; the running step finishes first. A cancelled deploy records its deployment as failed with `deployment.cancelled`, until step 6 below replaces the deployment row. This maps onto `tasks/cancel` of MCP Tasks.

A deploy ignores a cancel request once its `activation` step succeeded. The new release is then live, and stopping would skip the PHP refresh, the `after_activation` steps such as a queue restart, and the cleanup. The deploy finishes, and its operation keeps `cancel_requested` set. A half-done deploy marked `cancelled` would be worse than a late cancel, and a separate error code would report a failure where nothing failed.

### Delivery

**Reads.** `GET /api/v1/operations/{operation}` is one database read. With `after_sequence`, it returns only the steps that changed after that sequence. It always returns the status, the current step, the current `sequence`, and `poll_after_seconds`: 2 seconds while queued or new, then growing with the run time to 15 seconds, and null once terminal. A terminal operation carries its result, or its failed step and error code. `GET /api/v1/operations` lists operations, filtered by `status`, `kind`, and target, so an agent that lost its context finds what still runs.

**Web app.** The web app subscribes to the operation's private channel `private-operation.{id}`, then fetches the operation over HTTP, then applies events. Each `operation.updated` event carries the operation's `id`, `status`, `current_step`, and `sequence`, never step output. The page drops an event whose sequence is not newer than what it has, and fetches the changed steps with `after_sequence`. A lost event costs one more fetch. The Gateway also sends `operation.created` and `operation.updated` on `orbit` when an operation starts or changes status, so lists stay current. `POST /api/v1/broadcasting/auth` signs `private-operation.{id}` for any caller with Gateway access, as it signs `orbit`.

The Gateway broadcasts from the Horizon job. Status changes broadcast at once; output broadcasts at most once a second for each operation.

**MCP.** No tool call holds a request open, and none streams. `instance-deploy` returns at once:

```json
{"operation": {"id": 42, "status": "queued", "...": "..."}, "poll": {"tool": "operation-show", "after_seconds": 2}}
```

The agent calls `operation-show` with `after_sequence` after `poll.after_seconds`, and again after each reply's `poll_after_seconds`, until the status is terminal. `operation-list` with `status=running` recovers the operations an agent lost. The names map one-to-one onto the experimental MCP Tasks primitive, which `laravel/mcp` does not support yet: the operation ID is the task ID; `queued` and `running` are `working`, `succeeded` is `completed`, and `failed` and `cancelled` keep their names; `poll_after_seconds` is the poll interval; `operation-show`, `operation-list`, and `operation-cancel` are `tasks/get` and `tasks/result`, `tasks/list`, and `tasks/cancel`. The server instructions describe the polling contract.

**CLI.** `orbit instance:deploy` starts the operation, subscribes to its channel, and follows it. Without Reverb, or when the socket fails, it polls `operation:show`. Ctrl-C stops following, not the deploy, and prints the command that shows the operation. `operation:list`, `operation:show`, and `operation:cancel` complete the family.

### What moves now and what follows

This pull request moves the API, MCP, CLI, and web deploy of an Instance onto operations. These follow, each in its own pull request:

1. **Rollback.** Done: `instance.rollback` runs as an operation with the steps `rollback`, `activation`, `php_refresh`, and `process_restart`, and the NDJSON stream code is gone. A rollback needs the queue and can wait behind an operation on another Instance. The maintainer decided on 16 Oct 2026 that a rollback cancels a deploy of the same Instance that no worker has started, and is busy once a worker has started one ([One operation at a time](/reference/deployments#one-operation-at-a-time)).
2. **Instance create and remove.** Their operations replace `InstanceCreationRecovery` and the removal ledger, `InstanceRemoval` and `InstanceRemovalMember`. Removal progress becomes operation steps, and a task workspace removal uses the same operation ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-removal-ledger)).
3. **Route convergence.** Its operation holds the progress of a Route change. `RouteRemovalResidue` stays: it names cleanup owed on a Node that an offline removal did not reach, and that work can outlive any operation ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#an-operation-is-the-only-record-of-its-run)).
4. **The scheduled development deploy.** `orbit:deploy-development-defaults` becomes an operation in a pull request of its own, as the maintainer decided on 15 Oct 2026. [Automatic deployments](/reference/deployments#automatic-deployments) do this: `orbit:auto-deploy` starts a pinned `instance.deploy` operation only for a changed commit, so an unchanged commit still creates no record.
5. **The PHP-FPM limit.** It stays at 600 seconds until no long operation runs in a request. [ADR 0218](/decisions/0218-take-long-commands-off-the-request-path) decides the other commands that still run long in a request and sets the new limit.
6. **The deployment history.** Rollback moved in #1301, so `instance_deployments` can give way to operations in a pull request of its own ([ADR 0218](/decisions/0218-take-long-commands-off-the-request-path#the-deployment-history)).

`orbit:activity-finalize-interrupted` stays: it ends Activity rows of requests that PHP-FPM killed, which operations do not replace.

## Rejected alternatives

- **Temporal or another workflow engine.** It brings durable steps, retries, and signals, but it is a new service with its own database, workers, and SDK to run on every fleet. Two tables, one job, a runner, and a sweeper on the existing Horizon do what Orbit needs.
- **Keep streaming and add a reattach endpoint.** The deploy would still run in PHP-FPM under its 600-second limit, and a killed worker would still lose the run.
- **A systemd unit per operation, as Gateway releases use.** A release must survive a restart of Horizon itself, so it cannot run on the queue. Other operations can, and a unit per operation would need its own lock, logs, and recovery.
- **Long-held MCP requests or streamed tool results.** They hold a PHP-FPM worker for the whole operation and fail with the request deadline. Polling a record costs one short read per poll.
- **Events that carry step output.** Reverb refuses messages over 10,000 bytes and drops events while a client is offline. The database keeps the output tail; events only say what changed.
- **A heartbeat column instead of an attempt lease.** A step that waits on a silent remote command writes nothing, so a heartbeat would need a timer in the worker.
- **A check of the worker process.** The first version compared the worker's process ID and start time. A Horizon worker lives on after its job throws, so an attempt that ended without its outcome looked alive and kept its target locked. The lease ends with the attempt, not with the process.
- **Per-step jobs now.** Each step as its own job would remove the 1,600-second bound, but the Instance's operation lock would then be free between steps. One job per operation keeps the lock and fits every deploy that ran in a request before.
- **Run operations without the queue.** A second path, such as running the job after the response inside PHP-FPM, keeps the 600-second limit and doubles the code that recovery must cover.

## Consequences

- A deploy can run up to 1,600 seconds, and nothing that a client does stops it except `operation:cancel`.
- A lost client, a closed laptop, or a lost MCP context costs nothing: one read finds the operation.
- A Gateway release waits for running deploys, because Horizon finishes running jobs before it restarts. A release during a long deploy takes longer.
- One operation runs at a time in the fleet. A deploy of a second Instance waits until the first ends; its record stays `queued` meanwhile.
- A Gateway without the queue cannot deploy or roll back an Instance until it sets `ORBIT_GATEWAY_QUEUE_CONNECTION`.
- The API contract of `POST /api/v1/instances/{instance}/deploy` changes from an NDJSON stream to `202` with an operation. The CLI, SDK, MCP, and web app change in the same pull request.
- Output of an attempt that a lost worker interrupted is kept only up to the last flush, at most one second of output. The maintainer accepted this loss on 15 Oct 2026, so the runner writes no extra flushes.

## Affects

- Components: apps/gateway, apps/web, apps/cli, packages/php-sdk, apps/docs
- ADRs: [0218](/decisions/0218-take-long-commands-off-the-request-path) extends this record
- Detail: [Operations](/reference/operations); [Instance releases: Deploy through the API](/reference/deployments#deploy-through-the-api); [Realtime events: Operation](/reference/events#operation); [MCP: Operations](/reference/mcp#operations); [Gateway queue: Queues](/reference/gateway-queue#queues); [operation](/cli/operation)
- Verify: `tests/Feature/Domain/Operations`, `tests/Feature/Api/OperationsTest.php`, `tests/Feature/Api/InstanceRollbackOperationTest.php`, and `tests/Feature/Api/InstanceDeploymentsTest.php` in apps/gateway; the operation request tests in packages/php-sdk; the deploy, rollback, and operation command tests in apps/cli; the operation tests in apps/web. On Incus: a deploy that answers 202, finishes on Horizon, survives a killed worker in a resumable step, and fails with `operation.worker_lost` in a deploy step.
