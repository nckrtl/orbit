---
title: "Gateway queue"
description: "The Gateway's Redis connection and ACL user, four queues, Laravel Horizon, and how a release moves them."
covers:
  - apps/gateway/app/Domain/Gateway/GatewayQueue.php
  - apps/gateway/app/{Infrastructure/Gateway/GatewayQueue*,Infrastructure/Gateway/GatewayHorizon.php,Console/Scheduling/**,Jobs/RunScheduledCommand.php,Providers/GatewayQueueServiceProvider.php}
  - apps/gateway/config/{horizon,horizon-new-dawn,queue}.php
---

# Gateway queue

The Gateway scheduler dispatches most of its work. Each scheduled command that does work, such as `tasks:tick`, becomes a queued job, and [Laravel Horizon](https://laravel.com/docs/horizon) runs the jobs. A long build therefore never delays a tick, and a slow tick never delays the next minute of the schedule. Problem collection and filing, and document cleanup, run in the scheduler itself, as [Scheduled work](#scheduled-work) lists.

## Connection

The queue connection `gateway` uses the Redis driver. Its Redis server is reached through a [Redis Database connection](/reference/database-connections), such as `gateway-queue` for the Valkey on the `services` Node. Set these keys in `/home/orbit/shared/gateway.env`, then run a release. The release caches them and creates the [`horizon` Process](#horizon). Do not only [apply the env change](/reference/gateway-recovery#apply-an-env-change): the scheduler would then dispatch to the queue before Horizon runs.

| Key | Default | Effect |
| --- | --- | --- |
| `ORBIT_GATEWAY_QUEUE_CONNECTION` | empty | The slug of the Redis Database connection. Empty keeps the [inline schedule](#without-a-queue) |
| `ORBIT_GATEWAY_QUEUE_REDIS_DATABASE` | `1` | The Redis database index of the queue and Horizon. Use an index that no other client of that server uses |

The Gateway reads the host, port, username, and password of that connection from its records when a process first uses Redis. When the key names no Redis connection, the Gateway logs an error and every dispatch to the queue fails. Every key starts with `orbit-gateway:`, and Horizon's own keys with `orbit-gateway-horizon:`. The Redis client is Predis, so the Gateway needs no PHP Redis extension.

After [setup](#set-up-the-queue-user), the `valkey` Docker Process on `services` is the Valkey [Database server](/reference/database-servers) `services-valkey`. It listens on the WireGuard address of `services`, so only WireGuard peers reach it. It writes an append-only file (`--appendonly yes`) to the `services-valkey-data` volume, so queued jobs survive a restart. Every client logs in with a password. The queue and Horizon log in as their own [ACL user](#acl-users), `gateway-queue`, through the Database connection of the same name. That user reaches only the queue's keys.

## Queues

Laravel Horizon runs four queues on the connection. Each has its own supervisor, so a long build or deploy never holds up a short job:

| Queue | Processes | Jobs |
| --- | --- | --- |
| `tasks` | 3 | The scheduled commands below and the publish of an approved commit. The Git mirror's lock keeps one push per Project at a time |
| `task-vms` | 1 | Task VM provision, enroll, prepare, park, resume, and destroy, the host sweep |
| `builds` | 2 | The [base image step chains and refreshes](/reference/compute-drivers#build-the-base-image), the [UpCloud template refresh](/reference/compute-drivers#refresh-the-upcloud-template-on-recipe-changes), trusted [package cache fills](/reference/compute-drivers#shared-package-cache), and [recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance) |
| `operations` | 1 | [Operations](/reference/operations), such as an Instance deploy. One job runs one operation, one at a time |

The `task-vms` jobs run one at a time and in order, so no two jobs change the same VM at once.

A `task-vms` job runs at most 1500 seconds, and any other job at most 1700 seconds. An operation therefore has a deadline of 1600 seconds. A job that a worker lost, for example when its process was killed, runs again after 1800 seconds. An idle worker waits on the queue with a blocking read, so a job starts within about a second when a process is free.

Two `builds` workers let hosts build in parallel; `Bus::chain` orders each build, the database permits one running build per host, and pruning stays on that host and protects running builds.

## Scheduled work

The scheduler dispatches each of these as a unique job. While the job waits or runs, the scheduler dispatches no second one, so a slow tick never stacks up:

| Command | Every | Queue | Unique for |
| --- | --- | --- | --- |
| `tasks:tick` | 10 seconds | `tasks` | 330 seconds |
| `annotations:dispatch` | 10 seconds | `tasks` | 600 seconds |
| `orbit:auto-deploy` | minute | `tasks` | 600 seconds |
| `orbit:vault-sync` | minute | `tasks` | 900 seconds |
| `orbit:desired-fleet-state` | 5 minutes | `tasks` | 600 seconds |
| `orbit:activity-finalize-interrupted` | 5 minutes | `tasks` | 600 seconds |
| `orbit:operations-sweep` | minute | `tasks` | 300 seconds |
| `horizon:snapshot` | 5 minutes | `tasks` | 300 seconds |

The job runs the command in its own PHP process, as the scheduler did, so a command keeps no state from an earlier run. A command that exits nonzero is logged as an error. `tasks:tick` keeps its own lock of 300 seconds. The "unique for" time only matters when a worker is lost: the lock then frees itself after that time.

The [task VM jobs](/reference/compute-drivers#jobs) that the scheduler queues, the host sweep, image reconciliation, and [recipe maintenance](/reference/compute-drivers#nightly-recipe-maintenance), go to the same connection.

These commands stay in the scheduler and never go to the queue, also while it is set:

| Command | Every | Overlap lock |
| --- | --- | --- |
| `problems:collect` | 10 minutes | 15 minutes |
| `problems:file` | hour | 30 minutes |
| `project-documents:probes:reconcile` | minute | 10 minutes |
| `project-documents:cleanup:work` | 5 minutes | 60 minutes |

[Problem collection and filing](/reference/tasks#outer-loop) watch Horizon, so they must not depend on it. A collection usually takes under a minute. While it runs, the rest of that minute's scheduler run waits, so the 10-second dispatches of that minute run late. The [document cleanup](/reference/project-documents) commands stay because their permit belongs to the scheduler's start.

### Without a queue

While `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty, the scheduler runs the commands above itself, with their overlap locks, as before, and the Gateway creates no `horizon` Process. Task VMs need the queue. While task VMs are enabled, or the UpCloud token file is set, each due run of the host sweep, image reconciliation, or recipe maintenance fails with an error that names the key, instead of being skipped. A task VM claim fails the same way.

Operations need the queue too. An Instance deploy or rollback request answers `503` with `operation.queue_unavailable` and records nothing ([Operations](/reference/operations#start-an-operation)).

## Horizon

Horizon runs as the Gateway Node's [Process](/reference/processes-and-schedules) named `horizon`, beside the scheduler Process. It is a systemd Process with restart policy `always`. It runs `php artisan horizon` with the scheduler's PHP binary, as `orbit`, in the scheduler's directory, the stable path `/home/orbit/orbit/apps/gateway`. Read its output with `orbit process:logs <id>`.

The serving phase of a release's [runtime handoff](/reference/gateway-recovery#runtime-handoff) creates the Process and starts it, when `ORBIT_GATEWAY_QUEUE_CONNECTION` is set and no Process of the Gateway Node runs `artisan horizon`. So the first release after you set the key starts Horizon. When the handoff cannot create or start the Process, it fails with `gateway.release_units_failed`. The Gateway never removes the Process. After you empty the key, destroy it with `orbit process:destroy <id>`.

## Dashboard

Open `https://gateway.orbit/horizon` in a browser on a machine that is a WireGuard peer with Gateway access, the same access that the Orbit CLI needs for Gateway commands. The dashboard is [Horizon New Dawn](https://github.com/nckrtl/horizon-new-dawn), a React interface for Horizon. Any other address gets 403.

The dashboard can retry, cancel, and clear jobs, and pause queues, so anyone with Gateway access can change the queue. The Gateway keeps no browser session, so the dashboard has no CSRF token. Laravel's request forgery check runs in origin-only mode instead: a request that changes something passes only when the browser sends `Sec-Fetch-Site: same-origin`. Any other such request gets 403. Bulk actions run as jobs on the `tasks` queue.

Each release publishes the dashboard's compiled assets during `composer install` in [prepare](/reference/gateway-recovery#prepare-a-release), with `php artisan horizon-new-dawn:assets`. The first release with the dashboard runs its migration, which adds the table `horizon_new_dawn_batch_metadata`. The scheduler runs `horizon:snapshot` every five minutes for the dashboard's metrics.

The Redis server needs `maxmemory-policy noeviction`, so that it never drops a queued job to free memory. `services-valkey` has it. It has no `maxmemory`, so it can use the memory of `services`; a limit for the Gateway's use is a follow-up.

## Releases

A release moves Horizon in the serving phase, right after the switch, and the old database jobs in the scheduler phase, after the scheduler's drain. The handoff records the Horizon step as `horizon`:

| Outcome | When | What the handoff does |
| --- | --- | --- |
| `created` | No Process runs Horizon | Creates the `horizon` Process and starts it |
| `terminated` | The Process's unit is active | Runs `php artisan horizon:terminate` and leaves the unit alone |
| `started` | The unit is not active, for example after a failed start, a crash loop, or a stop after a switch back | Installs the unit again when it is missing, and starts it |
| `none` | `ORBIT_GATEWAY_QUEUE_CONNECTION` is empty | Nothing |

`horizon:terminate` reaches only a running master, so a stopped Horizon is started instead. The handoff never restarts an active unit, because a restart ends its running jobs.

`horizon:terminate` sends SIGTERM to the Horizon master. Horizon does not use fast termination: the master waits until each worker has finished its running job, on the release that job started on, and then exits. The Process's restart policy then starts Horizon again, on the new release. A new `task-vms` worker therefore never starts while an old one runs, and task VM jobs stay in order.

The cost is the wait. A release during a base image build waits for one short step, not the whole build. The build is a [chain of jobs](/reference/compute-drivers#build-the-base-image), and the next step runs on the new release. Until the running jobs end, no new job starts on any queue, and `tasks:tick` waits too. The master waits at most the longest supervisor timeout, 1700 seconds, and then exits.

The release does not wait for the new master. A failure of the step fails the handoff with `gateway.release_units_failed`, and the release switches back.

The scheduler phase restarts every other Gateway Process in the Gateway application with systemd, but never the `horizon` Process.

Do not restart Horizon with `orbit process:restart`, and do not stop it with `orbit process:stop` while a job runs. Both stop its unit, and systemd kills a job that still runs 90 seconds after the stop, such as a build. To restart Horizon, run `php artisan horizon:terminate`.

### Switch back to an older release

A release from before Horizon has no `horizon` command. After a switch back to it, the older release's handoff restarts the `horizon` Process, which then fails at each start until systemd gives up. The Process shows the failure in `orbit process:list`. Stop it with `orbit process:stop <id>`. The older release's scheduler runs its own workers on the database queues meanwhile, and jobs left in Redis wait. The next release with Horizon starts the Process again, and Horizon works the jobs left in Redis.

A job left in Redis keeps its unique lock in the Gateway cache. The older release then queues no second copy of that job, for example no second destroy of the same VM, until the lock expires; a lock without an expiry stays. To let the older release queue it again, remove the lock as `orbit` in `/home/orbit/orbit/apps/gateway`:

```bash
php artisan tinker --execute='cache()->forget("laravel_unique_job:App\\Jobs\\TaskVms\\DestroyTaskVm:7");'
```

The key is `laravel_unique_job:` followed by the job class and its unique id, the task VM id for task VM jobs. When the newer release goes live again, it works the jobs left in Redis.

### Jobs across releases

A job queued by one release can run on the next one, and during a release, on the one before. A change to a queued job's class, its constructor properties, or a table that a job reads, must work with both releases for one release. Remove the old form in a later release.

## When Redis is down

The queue depends on its Redis server, but the Gateway keeps running without it:

- The scheduler logs each failed dispatch as an error and frees the job's unique lock, so the next run dispatches it again. The scheduler keeps running.
- A running Horizon reports each failed read and keeps running. It works the queued jobs again as soon as Redis answers.
- A Horizon start fails while Redis is down.
- A request that queues a job, such as a task VM claim, fails with an error.

systemd starts a failed Horizon again after 2 seconds. After several failed starts in a row, systemd stops trying, and the Process shows the failure. Start it again with `orbit process:start <id>`; the next release also starts it.

A job that Redis lost runs again only when its owner queues it again. For example, the tick queues a publish again after its backoff.

## ACL users

After setup, the queue and Horizon log in to the [Valkey Database server](/reference/database-servers#a-valkey-server) `services-valkey` as the ACL user `gateway-queue`. That user can read and change only the queue's keys. The Gateway holds every password, and [Doctor](/reference/database-servers#check-a-valkey-server-with-doctor) checks the users.

| User | Keys | Commands | Used by |
| --- | --- | --- | --- |
| `default` | All | All | The admin user. The [ProxyCli collector](/reference/proxycli#collection) and the Gateway's [snapshot reads](/reference/proxycli#clients) also use it, through the Database connection `services-valkey`, until the collector [gets its own user](#not-built) |
| `gateway-queue` | `orbit-gateway:*` and `orbit-gateway-horizon:*` | The rules in the table below | The queue and Horizon, through the Database connection `gateway-queue` |

[`database:create`](/reference/database-servers#create-an-acl-user-on-a-valkey-server) creates the user from key patterns and allowed commands. Orbit adds `resetchannels`, `-@all`, and the final `-@admin -@dangerous`, so the user has no pub/sub channel and no admin or dangerous command:

| Input | Why |
| --- | --- |
| `--pattern='orbit-gateway:*'`, `--pattern='orbit-gateway-horizon:*'` | The keys of the queue and of Horizon. `orbit-gateway:*` does not match the Horizon prefix `orbit-gateway-horizon:`, so the user names both |
| `--allow=@connection` | On each new connection, Predis logs in with `HELLO`, sends `CLIENT SETINFO`, and selects the queue's database with `SELECT` |
| `--allow=@transaction` | `horizon:snapshot` and the dashboard use `MULTI` and `EXEC` |
| `--allow=@keyspace` | Horizon sets expiry times on jobs and deletes them. `horizon:clear-metrics` finds metrics with `SCAN` |
| `--allow=@string`, `@list`, `@hash`, `@set`, `@sortedset` | The queue keeps jobs in lists and sorted sets, and an idle worker waits with `BLPOP`. Horizon keeps jobs, metrics, and tags in strings, hashes, sets, and sorted sets |
| `--allow=eval` | The queue's Lua scripts push, pop, and move jobs. Valkey checks each `redis.call` in a script against the same rules. `@scripting` would also allow `SCRIPT FLUSH` and `FUNCTION FLUSH` |
| `--allow=time` | The dashboard's Lua scripts for pending jobs call `TIME`. `TIME` is only in `@fast`, which would allow many more commands |

The final `-@admin -@dangerous` removes commands that the categories above hold, such as `KEYS`, `SORT`, `FLUSHDB`, `CLIENT LIST`, and `CLIENT KILL`. The Gateway sends none of them. Key patterns do not limit the database index, and Valkey has no memory limit, so a leaked `gateway-queue` password can still fill the server's memory.

### Set up the queue user

These steps bring the `valkey` Process on `services` under Orbit and move the queue to its own user. Run each command on a machine with Gateway access.

First, adopt the Process as a Valkey Database server. `<id>` is the ID of the `valkey` Process in `orbit process:list --node=services`. Run this when the [dashboard](#dashboard) shows no running build or operation, because Valkey restarts once:

```bash
orbit database:server:create services-valkey --node=services --driver=redis --process=<id> --connection=services-valkey
```

The Gateway takes the password of `services-valkey` as the admin password, writes the ACL file, and restarts Valkey with it. Valkey reads its append-only file again, so the queued jobs stay. The queue and the collector keep logging in as `default` with the same password. [When Redis is down](#when-redis-is-down) describes the Gateway during the restart.

Then create the queue's user and its connection:

```bash
orbit database:create gateway-queue --server=services-valkey --database=1 \
  --pattern='orbit-gateway:*' --pattern='orbit-gateway-horizon:*' \
  --allow=@connection --allow=@transaction --allow=@keyspace --allow=@string --allow=@list \
  --allow=@hash --allow=@set --allow=@sortedset --allow=eval --allow=time
```

Last, as `orbit` on the Gateway, set this key in `/home/orbit/shared/gateway.env`:

```bash
ORBIT_GATEWAY_QUEUE_CONNECTION=gateway-queue
```

Then [run a release](/reference/gateway-recovery#deploy-a-release) of the current commit, as [Connection](#connection) requires. Read the commit as `release_sha` from `orbit gateway:status --json`:

```bash
orbit gateway:release:deploy <SHA>
```

The command follows the release, which must end `verified`. Its handoff runs `horizon:terminate`, so Horizon starts again as `gateway-queue` after its running jobs end ([Releases](#releases)). Both users reach the queue's keys, so no job is lost. When a newer commit passed CI meanwhile, or GitHub does not answer, the manual deploy [pauses automatic releases](/reference/gateway-recovery#pause) with `manual_deploy`. Then run [`orbit gateway:release:auto:resume`](/cli/gateway#orbit-gatewayreleaseautoresume).

### Check the queue user

Run `orbit doctor --family=database_server --node=services`. It reports no issue when the server runs with its ACL file and the live users match the records. Open the [dashboard](#dashboard), including its pending jobs. Then read the Horizon log with `orbit process:logs <id>`, where `<id>` is the `horizon` Process of the Gateway Node. A command that the user may not run shows there as an error with `NOPERM`.

To switch back, set `ORBIT_GATEWAY_QUEUE_CONNECTION=services-valkey` and run a release of the current commit again. Horizon then logs in as `default` and works the same jobs. Do not use `gateway:release:rollback` for this: it switches code only, and every release reads the same env file.

## Operations

These commands inspect and control Horizon on the Gateway.

| Task | Command |
| --- | --- |
| See Horizon | `orbit process:list --node=<gateway>` and `orbit process:logs <id>` |
| Restart on the current release | `php artisan horizon:terminate` |
| Pause and continue every queue | `php artisan horizon:pause` and `php artisan horizon:continue` |
| Count waiting jobs | `php artisan queue:monitor gateway:tasks,gateway:builds,gateway:operations` |
| Retry failed jobs | `php artisan queue:retry all` |

Run each `php artisan` command as `orbit` in `/home/orbit/orbit/apps/gateway`.

## Not built

Doctor does not report an old `last_tick_at` yet. Until it does, check [`orbit tasks:status`](/cli/tasks#orbit-tasksstatus) when task groups stop moving.

A Horizon that is down while its Process should run is ordinary Process drift. Doctor reports it as `process.state_mismatch`, or `process.crash_loop` when systemd keeps restarting it. Problem collection runs in the scheduler, so it records that issue within one collection cycle, and `problems:file` files it once two collections 10 minutes apart saw it. A Horizon stopped with `orbit process:stop` is not drift and is not reported.

The ProxyCli collector has no ACL user of its own. It logs in as `default` through `services-valkey`, and so do the Gateway's snapshot reads. Step 3 of the [AI router build](/decisions/0212-turn-proxycli-into-the-ai-router-extension#build-order) renames their keys from `orbit:proxycli:*` to `orbit:ai-router:*`. Nick creates their connection on `services-valkey` with the pattern `orbit:ai-router:*` after step 3 and before step 4. Step 4 binds it ([ADR 0221](/decisions/0221-run-valkey-database-servers-with-orbit-owned-acl-users#the-collector-gets-its-own-user-later)).

## Why it works this way

The scheduler used to run its work, and the task VM workers, as foreground commands. A foreground command holds the scheduler's run for that minute, so a slow tick or a VM job could hold a publish for minutes. With dispatch, the scheduler's run takes milliseconds, and each queue's workers decide when a job starts.

Problem collection is the exception, because it is how a stopped Horizon gets recorded. As a queued job, it would stop with Horizon, and the failure it should report would go unseen. In the scheduler, a collection costs late 10-second dispatches once every 10 minutes, and a release's [scheduler drain](/reference/gateway-recovery#runtime-handoff) waits for a running collection. Both are short and need no extra machinery.

Horizon needs Redis. The fleet already runs Valkey on the `database` role, so the Gateway uses that registered connection instead of a second server on its own host. Orbit's records keep its address, as for every other Database connection.

### An ACL user for the queue

The queue has its own ACL user because Valkey is shared. As `default`, a fault in the queue or Horizon, or a leaked queue password, could read or delete the collector's keys. A second Database connection carries the user, because one connection holds one username and one password for all its clients. The user lives on a [Database server](/reference/database-servers#valkey-starts-only-from-orbits-acl-file), so the Gateway holds its password and Doctor checks it.
