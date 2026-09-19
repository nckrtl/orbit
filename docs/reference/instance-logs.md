---
title: "App instance logs"
description: "How the Gateway reads the application log of an App instance, which file it reads, and what it redacts."
---

# App instance logs

This page tells an operator how the Gateway returns the application log of an App instance. The log is the file the application writes itself, so it shows application errors that a Process log does not. [App Processes and Schedules](/reference/app-processes-and-schedules) owns the logs of Processes and Schedules.

## Read the log

`GET /api/v1/instances/{instance}/logs` returns the end of the log as one newline-separated string in `data.logs`. `lines` sets the number of lines from 1 through 1,000, and the default is 100. The response also names the App instance in `data.id` and `data.name` and repeats `data.lines`. The read does not stream, so the Gateway refuses `follow`.

The Orbit web page shows this log on the App instance page. The CLI and the PHP SDK have no counterpart yet.

## Know which file the Gateway reads

The Gateway reads one file under `storage/logs` in the checkout of the App instance, which is the Laravel convention.

| Order | File | Used when |
| --- | --- | --- |
| 1 | `laravel.log` | The file exists. This is the `single` log channel. |
| 2 | The newest `laravel-*.log` | `laravel.log` is absent. This is the `daily` log channel. |

An App instance without either file has an empty log, and the request still succeeds. The caller never names a path. The Gateway reads only a regular file that sits directly in `storage/logs`, and it skips a symbolic link, so a link in the checkout cannot point the read at another file.

## Know what the Gateway redacts

The Gateway replaces each stored environment value of the App instance with `[REDACTED]` before it returns the log. It skips a value shorter than eight characters, because a short value such as `local` or `true` is an ordinary word and would blank the log. The Gateway then applies the same secret patterns that it applies to Process logs.

The log can still contain data that the application wrote on purpose, such as an email address in an exception message. Node access decides who may read it: the caller needs access to the Node that owns the App instance.

## Handle a failed read

A failed read returns an Orbit error with one of these codes. The log itself is never part of an error.

| Status | Error code | Cause |
| --- | --- | --- |
| 422 | `validation.failed` | `lines` is outside 1 through 1,000, or the request names `follow`. |
| 422 | `instance.checkout_path_invalid` | The recorded checkout path is not a valid storage path. |
| 422 | `instance.wireguard_ip_missing` | The owning Node has no WireGuard address. |
| 502 | `instance.logs_failed` | The Node did not answer, or the read command failed. |
