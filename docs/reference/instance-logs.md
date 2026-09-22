---
title: "Instance logs"
description: "How the Gateway reads the application log of an Instance, which file it reads, and what it redacts."
---

# Instance logs

This page tells an operator how the Gateway returns the application log of an Instance. The log is the file the application writes itself, so it shows application errors that a Process log does not. [Project Processes and Schedules](/reference/app-processes-and-schedules) owns the logs of Processes and Schedules.

## Read the log

`GET /api/v1/instances/{instance}/logs` returns the end of the log as one newline-separated string in `data.logs`. `lines` sets the number of lines from 1 through 1,000, and the default is 100. The response also names the Instance in `data.id` and `data.name` and repeats `data.lines`. The read does not stream, so the Gateway refuses `follow`.

The Orbit web page shows this log on the Instance page. The CLI and the PHP SDK have no counterpart yet.

## Know which file the Gateway reads

The Gateway reads one file under `storage/logs` in the checkout of the Instance, which is the Laravel convention.

| Order | File | Used when |
| --- | --- | --- |
| 1 | `laravel.log` | The file exists. This is the `single` log channel. |
| 2 | The newest `laravel-*.log` | `laravel.log` is absent. This is the `daily` log channel. |

An Instance without either file or its log directory has an empty log, and the request still succeeds. The caller never names a path. The Gateway opens each directory from the filesystem root through the recorded checkout and `storage/logs` without following links. A linked or non-directory ancestor fails the read. Production reads use the recorded concrete release, not the `current` link.

The Gateway skips symbolic links and special files when selecting a log. It reads the verified open file, so replacing a checked pathname cannot redirect the read. A candidate replaced before it can be opened fails the read. Before redaction, the read retains at most the last 65,536 bytes of the requested lines.

## Know what the Gateway redacts

The Gateway replaces each stored environment value of the Instance with `[REDACTED]` before it returns the log. It skips a value shorter than eight characters, because a short value such as `local` or `true` is an ordinary word and would blank the log. The Gateway then applies the same secret patterns that it applies to Process logs.

The log can still contain data that the application wrote on purpose, such as an email address in an exception message. Node access decides who may read it: the caller needs access to the Node that owns the Instance.

## Handle a failed read

A failed read returns an Orbit error with one of these codes. The log itself is never part of an error.

| Status | Error code | Cause |
| --- | --- | --- |
| 422 | `validation.failed` | `lines` is outside 1 through 1,000, or the request names `follow`. |
| 422 | `instance.checkout_path_invalid` | The recorded checkout path is not a valid storage path. |
| 422 | `instance.wireguard_ip_missing` | The owning Node has no WireGuard address. |
| 502 | `instance.logs_failed` | The Node did not answer, a directory path was unsafe, a selected file changed before opening, or the read command failed. |
