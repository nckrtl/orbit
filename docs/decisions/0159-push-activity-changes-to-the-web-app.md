---
title: "ADR 0159: Push Activity changes to the web app"
sidebarTitle: "0159 Push Activity changes to the web app"
description: "Proposed. The Gateway broadcasts short Activity notices on the orbit channel. The web app lists stored Activity newest first, filters that list, and stays usable on a phone."
---

# ADR 0159: Push Activity changes to the web app

The Gateway broadcasts `activity.created` and `activity.updated` on the `orbit` channel as short notices, never including `properties`. The web app adds an Activity page to the main navigation that lists stored Activity newest first, filters that list, and refetches it, and one open row, from those notices. The same controls stay usable on a phone.

## Status

Proposed.

This extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) with Activity notices that carry a fixed field list instead of the full record. It uses the notice shape of [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime). It keeps the read sampling of [ADR 0152](/decisions/0152-sample-successful-read-activity): a sampled read that stores a row emits `created` only. It extends [ADR 0158](/decisions/0158-end-interrupted-activity): a row ended by the interrupted-Activity sweep or the shutdown finalizer emits `updated`.

## Context

Operators read the command log with `activity:list`. That command returns the newest rows, at most 200, and cannot page backward or filter by status, command, or Node. The web app has no Activity page, so the log is not in the main navigation. Open tabs already subscribe to the private `orbit` channel. Polling the list on a fixed tick would add another read for every tab, and each of those reads can store its own Activity.

Reverb refuses a message over 10,000 bytes. An Activity's `properties` hold operation input. They can pass that limit, and they are the fields the redaction rules exist to contain. A subscriber of `orbit` already has Gateway access and can call `activity:show`.

The page has to be able to read the log without feeding itself. `activity:list` and `activity:show` are successful reads. [ADR 0152](/decisions/0152-sample-successful-read-activity) stores each of them once a minute. A broadcast of that row would make the page read again.

The write shapes differ. A command that can change something stores a `running` row and then updates it. A read that is kept is stored once, when it ends, with its outcome. The interrupted-Activity sweep and the shutdown finalizer update a `running` row from outside the request that started it.

## Decision

The Gateway owns the notices and the list filters. The web app owns the page and sends those filters. The CLI and the SDK expose the same filters.

### Notices

- The Gateway broadcasts `activity.created` and `activity.updated` on the existing private `orbit` channel, through the same broadcaster as other record events. A broadcast failure is logged and does not fail the write.
- The envelope `id` is the Activity id. The notice `data` is exactly `{id, request_id, command, status, caller_node_id, target_node_id, error_code, duration_ms, occurred_at}`.
- `occurred_at` is the row's recorded time, the same value `activity:show` returns. `caller_node_id`, `target_node_id`, `error_code`, and `duration_ms` are null when the row has no value.
- The notice never includes `properties`, `caller_ip`, `subject_type`, `subject_id`, `exit_code`, a path, or request input.
- There is no `activity.deleted`. The Gateway does not delete Activity rows.

| Write | Event |
| --- | --- |
| A new row is stored | `activity.created` |
| A `running` row then records `succeeded` or `failed` | `activity.updated` |
| A read stored once at the end, including a sampled read, a failed read, a `schedule:*` read, and a `:credentials` read | `activity.created` only |
| The interrupted-Activity sweep or the shutdown finalizer ends a `running` row | `activity.updated` |
| A row whose command starts with `activity:` | No broadcast, on insert or update |
| A write that changes only `properties` | No broadcast |

The Gateway broadcasts after the row is saved. A read that writes no row broadcasts nothing. One request emits `created` at most once and `updated` at most once. The sweep and the finalizer save `failed` with `activity.interrupted` first, then broadcast. A failed broadcast leaves that outcome in place.

### List paging and filters

`GET /api/v1/activities` stays newest first by id. It still omits the list request's own row. The response shape is unchanged.

| Query | Rule |
| --- | --- |
| `before_id` | A positive integer. Return rows whose id is smaller. The next older page passes the smallest id from the page it has. |
| `status` | `running`, `succeeded`, or `failed`. |
| `command` | The exact command name, from 1 to 255 characters. |
| `caller_node_id` | A positive integer. Return rows whose caller Node is this id. A null caller does not match. |
| `target_node_id` | A positive integer. Return rows whose target Node is this id. A null target does not match. |

The caller and target parameters are the node filters. Supplied filters combine. `limit` stays from 1 through 200 and still defaults to 25. Fewer rows than `limit` means there is no older page. An unknown command or Node id returns an empty list. An invalid filter returns `validation.failed` with HTTP 422, as an invalid `limit` does.

`orbit activity:list` sends those query parameters. `--before-id` maps to `before_id`, `--status` to `status`, `--command` to `command`, `--caller` to `caller_node_id`, and `--target` to `target_node_id`. The CLI rejects an invalid value before the request:

| Option | Code |
| --- | --- |
| `--before-id` | `activity.before_id_invalid` |
| `--status` | `activity.status_invalid` |
| `--command` | `activity.command_invalid` |
| `--caller` | `activity.caller_invalid` |
| `--target` | `activity.target_invalid` |

`--limit` and `--request-id` keep `activity.limit_invalid` and `activity.request_id_invalid`.

### Web page

- The main navigation lists Activity after Tasks and before Quota. The list is `/activity`. One row is `/activity/{id}`.
- On a phone, that navigation is the menu the header button opens, and Activity is one entry in it. The list, the filters, the control that loads older rows, and the open detail stack in one column. Each of those can be reached by tap. Back from a detail returns to the same filters and the same page.
- The page requests the newest 25 rows. It loads older rows with `before_id` set to the smallest id on the page.
- The page has four filters. Each unused filter is omitted. The list endpoint's rules apply, including an empty result for an unknown command or Node id.

| Filter | Query |
| --- | --- |
| Status | `status` of `running`, `succeeded`, or `failed` |
| Command | `command`, the exact name, from 1 to 255 characters |
| Caller Node | `caller_node_id` |
| Target Node | `target_node_id` |

- Changing a filter clears `before_id` and loads the newest page that matches the new filters.
- While the `orbit` socket is live, `activity.created` and `activity.updated` refetch the list query the page is showing. That query keeps the filters. On an older page it also keeps `before_id`. `activity.updated` for the open row also refetches that row with `activity:show`. The page waits 100 milliseconds and then refetches each of those queries once, so a sweep that ends many rows refetches the list once. A refetch that an event starts replaces a request already in flight.
- The open row always comes from `activity:show`, including its first load. The list payload is not the detail view. `activity:show` is where the page reads `properties` and the other fields the notice omits.
- While the socket is live, the list and the open row refetch every 5 minutes, as a safety net for a lost notice. That refetch keeps the filters and the open cursor. When the socket first subscribes, the web app refetches them with the task and Process queries. When the socket returns after a drop, it refetches every query.
- While realtime is down or not configured, the list and the open row poll every 30 seconds. The poll keeps the filters and the open cursor.
- `activity:list` and `activity:show` are `activity:*` commands, so those refetches are not broadcast.

## Rejected alternatives

- Broadcast the full Activity record: rejected because `properties` can pass Reverb's 10,000-byte limit and would send operation input to every subscriber without a request.
- Broadcast `activity:*` rows: rejected because the page's list and show would then broadcast, and the page would fetch again.
- Emit `updated` for a sampled read: rejected because that row is inserted once, with its outcome, so `created` already carries the outcome.
- Leave the page on the fleet poll and skip Activity events: rejected because an open page would then wait for the backoff, up to 5 minutes, to show a failure.
- One node filter that matches either the caller or the target: rejected because the log separates who called from what they changed. The two columns stay two filters.
- Leave status, command, and node filters off the web page: rejected because the page is where an operator reads the log, and those filters are how a long list stays readable.
- Drop filters, paging, or the detail on a phone: rejected because a phone opens the same app, and those controls are part of using the page.
- Offset paging: rejected because a new row changes which record sits at each offset. `before_id` keeps an older page on the same rows.
- A separate Activity channel: rejected because every `orbit` subscriber can already read every Activity over HTTP.

## Consequences

- An open Activity page shows a new or finished row on the next refetch while realtime is live. One burst of notices costs one list refetch, plus one show when a row is open.
- The page's own reads do not broadcast, so a refetch does not schedule another refetch.
- A successful read inside a sampling window stores no row and broadcasts nothing.
- A client that needs `properties`, the caller address, the subject, or the exit code calls `activity:show`.
- `activity:list` can page past the newest 200 rows and can limit the result to one status, one command, one caller, or one target. The web page sends the same limits. Changing a filter shows the newest match. A live refetch keeps the filters and any open older page.
- On a phone, the menu, list, filters, older rows, and detail stay usable in one column.
- The sweep and the finalizer broadcast once per row they end. A broadcast failure still leaves the row `failed` with `activity.interrupted`.
- Subscribers learn command names, status, Node ids, error codes, and durations they can already read over HTTP. They do not receive `properties`.
- While realtime is down, one open Activity page polls every 30 seconds.

## Affects

- Components: apps/gateway, apps/web, apps/cli, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) and [ADR 0158](/decisions/0158-end-interrupted-activity); keeps [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime) and [ADR 0152](/decisions/0152-sample-successful-read-activity)
- Detail: [Realtime events](/reference/events), [Web app](/reference/web-app), [activity](/cli/activity)
- Verify: Gateway tests for both notices, the `activity:*` omission, created-only reads, sweep and finalizer updates, and the list filters; web tests for the refetch, the open row, the 30-second poll, the filter query, the paging reset, and keeping filters on refetch; a desktop check and a phone-width check, at the width where the main navigation collapses into the header menu, that the menu, list, filters, older rows, and detail can each be used; CLI tests for the new options and their error codes
