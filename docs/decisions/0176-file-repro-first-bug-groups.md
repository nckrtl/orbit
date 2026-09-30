---
title: "ADR 0176: File recurring production problems as repro-first bug groups"
sidebarTitle: "0176 File repro-first bug groups"
description: "In progress. The Gateway records recurring Doctor, Activity, log, and assistance signals, then files at most three Backlog bug tasks a day for an operator to edit and start."
---

# ADR 0176: File recurring production problems as repro-first bug groups

The Gateway watches recurring production failures and files each one as a Backlog bug task. An operator edits that task and moves it to Todo. The scheduler does not start it before then.

## Status

In progress.

Principle: this decision serves [agents operate, humans steer](/mission#principles) and [deterministic first](/mission#principles). Code decides which signals recur and files a draft. A person edits the reproducing test and starts the task.

## Context

Production failures are visible, but nothing turns a repeated failure into a task. [Doctor](/cli/doctor) compares expected state with live state and stores no findings. The request writes one Activity row. `activity_log` stores `command`, `error_code`, and `exit_code` for each command. The resource id is not a column. It is only in `properties.path`. Gateway log errors carry `request_id`. The `schedules` table is empty, and no alert manager is configured.

Those signals are a poor fit for a page on every hit. After client refusals are set aside, a few days of Gateway traffic held a handful of distinct server-class Activity failures and a handful of log errors. The same command can also fail dozens of times in one hour. Doctor drift can show on one run and be gone on the next, including a snapshot race that heals itself. An assistance reason is already a person asking for help, and the same wording can recur across tasks.

The Tasks engine already starts work in Backlog, checks a `command` deliverable with `fails_on_base`, and expects the Orbit repository to begin a group with a docs subtask. There is no `test` deliverable type. The outer loop has to file a draft a person can edit, not a task an agent starts on a placeholder command.

## Decision

The Tasks extension owns an outer loop, specified in the [Tasks reference](/reference/tasks#outer-loop). `problems:collect` records fingerprints every 10 minutes. `problems:file` files tasks hourly. Both run only while Tasks is enabled, both use an overlap lock, and each run is bounded. `TaskSchedule` registers them.

### Fingerprints

`problem_fingerprints` stores one row per problem. The columns are `fingerprint`, `source`, `first_seen`, `last_seen`, `occurrences`, `evidence`, `task_group_id`, `muted_until`, and `filed_at`. `task_group_id` points at the top-level task, or is null. `filed_at` is when the current episode was filed, or null, and the operator cannot edit it. The fingerprint is unique and at most 255 characters. A longer key keeps its source prefix and a short hash of the full key.

The evidence sample is small: at most five request ids, five Activity ids, five Activity paths, one redacted log excerpt of at most 500 characters, the latest Doctor expected and observed values, the newest 20 observation times, and up to 200 open assistance task ids. Expected and observed stay the bounded values Doctor already returns. The sample does not store raw Doctor output. This narrows [No stored reports](/cli/doctor#no-stored-reports) for that sample only.

| Source | Fingerprint |
| --- | --- |
| Doctor | `doctor\|code\|resource_type\|resource_id` |
| Activity | `activity\|command\|error_code` |
| Log | `log\|exception class\|first app frame` |
| Assistance | `assist\|normalized reason` |

A null Doctor resource id is `none`. Activity counts only server-class rows: `gateway.unhandled`, `activity.interrupted`, a code that ends in `_failed` or `.unavailable`, `http.` plus a status of 500 or more, or a nonzero `exit_code`. A nonzero exit with no error code uses the segment `exit`. Client refusals such as `validation.failed`, `http.404`, `http.409`, and `http.422` do not count unless they also match those tests. The path stays in the sample and out of the key.

A log record counts when its level is ERROR or higher, it names an exception class, and the trace has a frame under the Gateway `app/` directory. The frame in the key is that path relative to the Gateway root, a colon, and the function, with no line number. An `HttpExceptionInterface` below status 500 is ignored, and so is `ValidationException`. A trace with no app frame is ignored. The excerpt keeps `request_id` and is redacted with the Gateway log redactor.

An assistance reason is lowercased, and each UUID and digit run becomes `#`. One open request on a task counts once. The same task counts again only after the flag clears and a new request is stored.

One `problem_collector_state` row stores the Activity cursor, the log path, inode, and byte offset, and the Doctor resume key. The first collector run sets the Activity cursor and the log offset at the end of the current data and does not count those past rows.

### Ready to file

Readiness uses only the current episode: the observation times still stored on the row. Doctor is ready when two of those times are at least 10 minutes apart. A miss does not delete the row or reset the episode.

Activity, log, and assistance are ready when the episode count is 10 or more, or when the count is at least 3 and those times fall in two different 15-minute UTC blocks or on two UTC dates. A block is the UTC quarter hour that contains the time.

Filing clears the episode after the brief is built. `occurrences` becomes 0, and `first_seen`, `last_seen`, and the observation times are cleared. Hits while the task is open start another episode. The filer clears that episode in the same write as `muted_until`. Only a hit after the task ended can pass the ready test once the mute ends. A hit after the merge and before the deploy still counts, and the operator cancels that draft.

### Filing stays off

The filer skips a fingerprint while `muted_until` has not passed, or while the linked task is `backlog`, `todo`, `reserved`, `running`, `reviewing`, or `settling`.

The first time that task shows `completed` or `failed`, and `muted_until` is empty, one write sets `muted_until` to 7 days after the task's `updated_at` and clears the episode. A `cancelled` task uses 14 days in that same write. A missing linked task uses the 7-day deadline from the run that notices the gap, and that write clears the episode too. A stored deadline is not moved. The clear sets `occurrences` to 0 and drops `first_seen`, `last_seen`, the observation times, the request ids, Activity ids, paths, and the log excerpt. Open assistance task ids stay. A crash stores neither the deadline nor the clear.

Filing a new task clears `muted_until`, sets `filed_at`, and clears the episode so the brief owns it. The brief is built from the episode before that clear. Hits while the task remains open are cleared only when the task ends, as the write above describes. Open assistance task ids stay, so a request that is still open is not counted again.

`failed` uses the same 7 days as `completed`. The agent did not start, and filing that key again in the same hour would spend the daily cap on a task that never ran.

### The filed task

The filer creates at most 3 tasks per day, in the Gateway application timezone, highest `occurrences` first. Equal counts use the earlier `first_seen`, then the fingerprint string. Each run loads at most 50 ready, unsuppressed rows. The cap counts fingerprint rows whose `filed_at` falls on that day's date. An edit to the brief does not change the count.

The filer inserts the task and its subtasks and updates the fingerprint in one database transaction. The update sets `task_group_id` and `filed_at`, clears `muted_until`, and resets the episode. A crash rolls every write back, so the next run does not file a duplicate.

Each task is for the Project with slug `orbit` and starts in `backlog`. The brief starts with `Filed by the outer loop.` and then has Symptom, Fingerprint, First seen, Last seen, Count, Evidence, and Suspected entry point. A missing Orbit Project files nothing.

Symptom is cut at 1,000 characters. The suspected entry point is cut at 500. Expected and observed are cut at 200. The fingerprint stays at most 255. If the brief is still over 8,000 characters, Evidence lines are dropped until it fits. `tasks:create` refuses a longer brief with `validation.failed`. If create still fails, the filer skips that row, leaves `filed_at` unset, does not count it toward the cap, and continues. Each subtask brief copies the cut symptom and stays under 8,000 characters.

The first subtask is the docs subtask. Its deliverable id is `docs` and its type is `review`. The second subtask reproduces the failure and then fixes it. Its deliverable id is `test`, its type is `command`, and `fails_on_base` is true. The filer fills a placeholder command, directory, and path. The operator replaces those fields with the real test, edits the brief, and moves the task to Todo.

### Bounds and failures

Each collector run handles at most 200 Doctor issues, then resumes, 500 Activity rows, 1 MiB of log bytes on a record boundary, and 200 assistance rows. Doctor runs through `RunDoctorAction` for every Node and every family. A peer access grant does not drop Nodes. The log file is `storage/logs/laravel.log` when that path is a regular file, otherwise the newest `storage/logs/laravel-*.log`. Unread bytes in a rotated file are finished before the collector switches.

Each collector source commits its fingerprint updates and its cursor in one database transaction. Activity commits the last id. The log commits the path, inode, and offset. Doctor commits the resume key. Assistance commits the open task ids. A crash rolls that source back, so the same rows are not counted twice.

The collector overlap lock expires after 15 minutes. The filer lock expires after 30 minutes. A failure in one source does not skip the others. The same exception class for one command is reported at most once an hour.

## Rejected alternatives

- Page a person on every server-class row: a burst of the same command in one hour would flood the board, and a one-run Doctor race would file a task for drift that is already gone.
- File straight to Todo: the reproducing test is a placeholder, and an agent would start before a person can replace it. Backlog is the existing gate.
- Wait for schedule rows or an alert manager: the schedules table is empty, and no alert manager is configured. The four signals above are the ones the Gateway already has.
- Put the resource id in the Activity key: that id is only in `properties.path`, so the same command and error code would split into one task per resource.
- Merge a log error and its Activity row into one fingerprint: the keys differ, and a log error with no Activity row would disappear.
- Let a model choose what to file: the thresholds are code. The person steers by editing the draft and by cancelling noise.
- Store raw Doctor reports or full log traces: raw output exposes paths and credentials. The sample keeps bounded Doctor values and a short redacted excerpt.
- Count historical Activity and log rows on the first run: that backlog would take the daily cap on the day the loop starts. The first run records cursors at the end.
- Keep the filed episode in the ready test: when the 7-day or 14-day wait ends, the old counts would file the same key with no new failure.
- Clear the episode only when the task is filed: hits while it sits in Backlog, Todo, or Settling would pass the ready test when the mute ends, with no failure after the fix.
- Count the daily cap from the brief: the operator edits that brief in Backlog on the filing day, so the count would move. `filed_at` does not.
- Refile as soon as a filed task reaches `failed`: the task never ran, and the next hourly pass would file the same key again.

## Consequences

- Recurring Doctor drift, server-class command failures, application log errors, and repeated assistance reasons become Backlog tasks with a docs subtask and a reproducing command.
- An operator must replace the placeholder test and move the task to Todo. Until then the scheduler leaves it alone.
- A cancelled task keeps that fingerprint quiet for 14 days. A completed or failed task keeps it quiet for 7 days.
- The board gains at most three of these tasks per day, counted from `filed_at`. A busy day past that cap waits for the next day. When the linked task ends, the mute write also clears the episode, so hits from before that end do not file the key. Hits after the merge and before the deploy can still file a draft, and the operator cancels it.
- Doctor's caller-facing report is unchanged. The fingerprint sample is the only stored Doctor state, and it is limited to code, resource, and bounded expected and observed values.
- The collector and filer need the Tasks extension. While the extension is off, they do not run.

## Affects

- Components: apps/gateway
- ADRs: none
- Detail: [Tasks: Outer loop](/reference/tasks#outer-loop), [doctor: No stored reports](/cli/doctor#no-stored-reports)
- Verify: feature tests for fingerprint keys, the ready thresholds, suppression, the daily cap, cursor bounds, and the Backlog task shape
