---
title: "Instance logs"
description: "How the Gateway reads the application log of an Instance, which file it reads, what it redacts, and how a viewer follows it live."
covers:
  - apps/gateway/app/Actions/Instances/ShowInstanceLogsAction.php
  - apps/gateway/app/Http/Controllers/Api/InstanceLogsController.php
  - apps/gateway/app/Infrastructure/Instances/RemoteInstanceLogReader.php
  - apps/gateway/app/Domain/Logs/{LogReadLimit,LogRedactor}.php
---

# Instance logs

The Gateway returns the end of an Instance's application log. The application writes this file itself, so it shows errors that a Process log does not. [Processes and schedules](/reference/processes-and-schedules) and [Schedules](/reference/schedules) describe the other logs.

## Read the log

`GET /api/v1/instances/{instance}/logs` returns the end of the log as one newline-separated string in `data.logs`. `lines` sets the number of lines from 1 through 1,000, and the default is 100. The response also names the Instance in `data.id` and `data.name` and repeats `data.lines`. The read does not stream, so the Gateway refuses `follow`. It returns at most 4 MiB of the newest lines. When the lines asked for are larger, it returns fewer, and never a line cut at its start.

The web app shows this log on the Instance page. [`orbit instance:logs`](/cli/instance#orbit-instancelogs) returns it in the terminal, and the PHP SDK sends the same request.

## Follow the log live

A viewer can follow the log as the application writes it with a [live log stream](/reference/live-logs). The Node agent reads the same file with the same rules, and the Gateway relays new lines to that viewer only. The web app's log pane and `orbit instance:logs --follow` use a stream. When the Gateway cannot open one, they fall back to this one-shot read.

## Know which file the Gateway reads

The Gateway reads one file under `storage/logs` in the selected app's [application directory](/reference/projects#application-directory). App path `apps/site` means `<checkout>/apps/site/storage/logs` in development or `<production-home>/current/apps/site/storage/logs` in supported single-app production. App path `.` selects the checkout or release root. One-shot reads and live streams use the same app and directory.

API reads accept `?app=NAME`, CLI uses `--app=NAME`, SDK uses `$app` and MCP uses `app`. Single-app omission resolves its name; multi-app omission returns `app.required`, and an unknown name returns `app.not_found`. Responses add `data.app`. Live stream creation also sends the app name; fallback to a one-shot read must keep it. An Instance Route-domain selector and explicit app must agree or return `app.selector_conflict`.

| Order | File | Used when |
| --- | --- | --- |
| 1 | `laravel.log` | The file exists. This is the `single` log channel. |
| 2 | The newest `laravel-*.log` | `laravel.log` is absent. This is the `daily` log channel. |

An Instance without either file has an empty log, and the request still succeeds. The caller never names a path. The Gateway reads only a regular file that sits directly in `storage/logs`, and it skips a symbolic link, so a link in the checkout cannot point the read at another file.

## Know what the Gateway redacts

The Gateway replaces each stored environment value of the Instance with `[REDACTED]` before it returns the log. It skips a value shorter than eight characters, because a short value such as `local` or `true` is an ordinary word and would blank the log. It also skips the values of an exact list of Laravel setting keys, such as `APP_ENV` and `LOG_CHANNEL`, so `production` in `production.INFO` stays readable. [Live logs](/reference/live-logs#redaction) lists the keys. A secret under any other key, such as `LOG_SLACK_WEBHOOK_URL`, is redacted. The Gateway then applies the same secret patterns that it applies to Process logs.

The log can still contain data that the application wrote on purpose, such as an email address in an exception message. Node access decides who may read it: the caller needs access to the Node that owns the Instance.

## Handle a failed read

A failed read returns an Orbit error with one of these codes. The log itself is never part of an error.

| Status | Error code | Cause |
| --- | --- | --- |
| 422 | `validation.failed` | `lines` is outside 1 through 1,000, or the request names `follow`. |
| 422 | `instance.checkout_path_invalid` | The recorded checkout path is not a valid storage path. |
| 422 | `instance.wireguard_ip_missing` | The owning Node has no WireGuard address. |
| 502 | `instance.logs_failed` | The Node did not answer, or the read command failed. |
