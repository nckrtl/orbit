---
title: "ADR 0158: End interrupted Activity"
sidebarTitle: "0158 End interrupted Activity"
description: "Proposed. An Activity whose request was killed before it recorded an outcome ends as failed with activity.interrupted, at shutdown after a fatal error or by a scheduled sweep 900 seconds after it started."
---

# ADR 0158: End interrupted Activity

An Activity stays `running` only while its request can still finish. When PHP stops a request with a fatal error, the Gateway ends the Activity at shutdown. When the request is killed outright, the Gateway scheduler ends it after the PHP-FPM request limit plus a margin has passed. Both write `failed` with the error code `activity.interrupted`.

## Status

Proposed.

## Context

[ADR 0152](/decisions/0152-sample-successful-read-activity) keeps the rule that a request that can change something writes a `running` Activity when it starts and its outcome when it ends. The outcome is written by the request itself, in the command activity middleware.

A request does not reach that code in these cases:

- PHP-FPM kills a request after 600 seconds.
- The kernel OOM killer ends a worker, as it did on 2026-09-23.
- A Gateway deploy restarts PHP-FPM with requests in flight.
- A fatal error, such as exhausted memory, stops PHP before the middleware runs again.

On 2026-09-25 production held 82 Activity rows still `running`, the oldest from 2026-09-14. Nothing would ever end them, so `activity:list`, `activity:show`, and the MCP activity tools showed operations that were no longer running.

Setting the API command deadline below the PHP-FPM limit lets a slow command fail and record its outcome. It does not help a worker that is killed.

A signal that kills the process, such as SIGKILL from the OOM killer or PHP-FPM, runs no PHP code. PHP still runs shutdown functions after a fatal error or a client abort.

No Gateway request can run longer than the 600-second PHP-FPM limit. Streamed deployments and rollbacks, Role relocations, and `instance:register`, the longest recorded operation at 522 seconds, are all bounded by it. Reads have no `running` row since ADR 0152.

## Decision

- An interrupted Activity ends with status `failed` and error code `activity.interrupted`. Its `duration_ms` and `exit_code` stay null, because the Gateway does not know them. No new status is added, so clients that know `succeeded` and `failed` need no change.
- When the middleware writes a `running` row, it arms a shutdown finalizer and disarms it once the outcome is recorded. After a fatal error or a client abort, the finalizer ends the row if it is still `running`. The Gateway's exception handler runs it first when it reports a fatal error, and grants it extra memory after a memory error, because building log context can exhaust memory again and stop the shutdown functions that follow.
- The Gateway scheduler runs `orbit:activity-finalize-interrupted` every five minutes without overlap. It ends every row still `running` 900 seconds after it started, 300 seconds past the PHP-FPM limit. One run ends at most 500 rows, oldest first. It only changes rows that are still `running`, so it can run again safely.
- One global bound covers every command, because the PHP-FPM limit covers every request. `orbit.activity_interrupted_after` holds the bound, and a test keeps it at least 60 seconds above the PHP-FPM limit.
- The rows already stuck in production end on the first scheduled run after deploy. No manual database change is needed.

## Rejected alternatives

- A new `interrupted` status: rejected because every client and filter would need to learn it, while `failed` with a stable error code already says what happened.
- Per-command bounds: rejected because the PHP-FPM limit bounds every request the same way. A bound for each command needs upkeep and can only be shorter.
- A sweep at Gateway boot or deploy only: rejected because the OOM killer and PHP-FPM kill workers without a deploy.
- Delete stuck rows: rejected because the attempt, its caller, and its target are still useful audit history.
- Rely on shutdown handling alone: rejected because SIGKILL runs no PHP code.

## Consequences

- `activity:list` and the MCP activity tools no longer show phantom running operations older than 15 minutes.
- A killed request shows `running` for up to 20 minutes before the sweep ends it: 15 minutes of bound and up to five minutes until the next run.
- `activity.interrupted` says the Gateway lost the request. The operation may have finished on the target Node, so an operator checks the target before running it again.
- When a Gateway runtime lets a request run longer than 900 seconds, the bound must grow with it. The configuration test fails when the PHP-FPM limit passes it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: keeps the Activity lifecycle of [ADR 0152](/decisions/0152-sample-successful-read-activity) and ends rows that lifecycle leaves open.
- Detail: [activity](/cli/activity), [PHP runtime](/reference/php-runtime)
- Verify: `apps/gateway` test `InterruptedActivityTest`
