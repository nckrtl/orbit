---
title: "App instance queue"
description: "How the Gateway reads the Laravel Horizon queue of an App instance, what it returns, and what it never returns."
---

# App instance queue

This page tells an operator how the Gateway reports the queue of an App instance that runs Laravel Horizon. The report shows the state of Horizon, the workload of each queue, and the newest pending, completed, or failed jobs. [App Processes and Schedules](/reference/app-processes-and-schedules) owns the Process that runs Horizon, and [App instance logs](/reference/instance-logs) owns the application log.

## Read the queue

`GET /api/v1/instances/{instance}/queue` returns one report. `state` selects the job list: `pending`, `completed`, or `failed`, and the default is `pending`. `limit` sets the number of jobs from 1 through 50, and the default is 50. The Orbit web page shows this report on the App instance page. The CLI and the PHP SDK have no counterpart yet.

| Field | Meaning |
| --- | --- |
| `available` | False when the App instance has no queue to report. Every other field but `state` is then absent. |
| `status` | `running`, `paused` when every master supervisor is paused, or `inactive` when none runs. |
| `jobs_per_minute`, `recent_jobs`, `recently_failed_jobs` | The counters the Horizon dashboard shows. |
| `processes` | The worker processes across all supervisors. |
| `totals` | The number of pending, completed, and failed jobs Horizon retains. |
| `queues` | Each queue with its `length`, its `wait_seconds`, and its `processes`. |
| `jobs` | The newest jobs in `state`: `id`, `name`, `queue`, `status`, `pushed_at`, `completed_at`, `failed_at`, `exception`, and `url`. |
| `dashboard_url` | The Horizon dashboard on the domain of the App instance. Each job `url` opens that job in it. |

## Know when a queue is available

The Gateway reports a queue when the App instance owns a systemd Process whose command is `artisan horizon`, and the application has Horizon installed. An App instance without that Process answers `available: false`, and the Gateway makes no remote call for it. A Docker Process does not count, because the Gateway cannot run PHP in its checkout the way that Process does.

## Know how the Gateway reads it

The Gateway opens an SSH session to the Node and runs one fixed PHP script in the checkout. It runs as the user of the Horizon Process, with the PHP binary that Process names, so it sees the same Redis connection and configuration that Horizon sees. The script reads Horizon's own repositories and changes nothing. It does not call the application over HTTP, so the authorization gate of the Horizon dashboard does not apply, and a production App instance reports the same way as a development one.

The caller supplies only `state` and `limit`. The Gateway validates both and passes each as one command argument.

## Know what the Gateway never returns

A job payload can hold customer data, so the script never prints one. For a failed job it prints only the first line of the exception, because the lines after it are a stack trace with arguments. The report is printed inside the application, so the Gateway treats it as untrusted: it casts and bounds every value again and passes on only the fields in the table above. It builds a job `url` only from an `id` of letters, digits, and hyphens.

## Handle a failed read

A failed read returns an Orbit error with one of these codes. A report is never part of an error.

| Status | Error code | Cause |
| --- | --- | --- |
| 422 | `validation.failed` | `state` is not one of the three states, or `limit` is outside 1 through 50. |
| 422 | `instance.wireguard_ip_missing` | The owning Node has no WireGuard address. |
| 502 | `instance.queue_failed` | The Node did not answer, the script failed, or it printed no report. |
