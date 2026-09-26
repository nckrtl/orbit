---
title: "ADR 0159: Push Activity changes to the web app"
sidebarTitle: "0159 Push Activity changes to the web app"
description: "Proposed. The Gateway broadcasts short Activity notices on the orbit channel. The web app shows one Activity log, newest first, filters that log, and stays usable on a phone."
---

# ADR 0159: Push Activity changes to the web app

The Gateway broadcasts `activity.created` and `activity.updated` on the `orbit` channel as short notices, never including `properties`. The web app adds an Activity page to the main navigation that shows stored Activity as one continuous log, newest first, filters that log, and applies those notices to the cached pages. One open row still loads through `activity:show`. The same controls stay usable on a phone.

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
- On a phone, that navigation is the menu the header button opens, and Activity is one entry in it. The log and the open detail stack in one column. Each row can be reached by tap.
- The page is one continuous log, newest first. It loads 50 rows at a time with TanStack Query `useInfiniteQuery`, and it sends `limit=50`. The next page sets `before_id` to the id of the oldest loaded row. A page that returns fewer rows than that limit is the end of the log, and the page does not ask for another. TanStack Virtual renders the rows. The next page loads when the user scrolls within a few rows of the end.
- There is no Older control and no Newer control. `before_id` is the request cursor only. It never appears in the URL. The filters stay in the URL.
- The page has four filters. Each unused filter is omitted. The list endpoint's rules apply, including an empty result for an unknown command or Node id. Changing a filter drops the loaded pages and loads the newest 50 rows that match. The log shows the top of that result.

| Filter | Query |
| --- | --- |
| Status | `status` of `running`, `succeeded`, or `failed` |
| Command | `command`, the exact name, from 1 to 255 characters |
| Caller Node | `caller_node_id` |
| Target Node | `target_node_id` |

- Where the main navigation is inline, the four filters sit in a bar on the page. Where that navigation collapses into the header menu, the page header has one Filters button instead of the bar. The button shows the count of filters that are set, including 0. It opens a sheet with the four fields, Clear, and Done. The sheet edits the URL filters directly, the same filters the bar edits. Clear removes every filter. Done closes the sheet and does not keep a second copy of the filters.
- While the `orbit` socket is live, the page writes notices into the cached pages. A notice does not refetch the list. A burst of notices does not refetch the list.
- `activity.created` inserts the row at the top when the row matches every active filter. The notice carries every list column, and that is what the page compares. A null caller does not match a caller filter, and a null target does not match a target filter. An id already in the cache is not inserted again.
- `activity.updated` patches a loaded row in place, including when the new fields do not match the active filters. A row that is not loaded stays unloaded. The page does not remove a row because a notice arrived.
- A row added above the viewport leaves the rows on screen where they are. The new rows sit above them. When the list is scrolled away from the top, a control reads `N new`, and N is the count of rows added while the list is away from the top. Tapping the control scrolls to the top and clears the count. At the top, the new rows appear in the log and the control stays hidden. Returning to the top clears the control.
- The open row always comes from `activity:show`, including its first load. The list payload is not the detail view. `activity:show` is where the page reads `properties` and the other fields the notice omits. `activity.updated` for that row refetches it with `activity:show`. The page waits 100 milliseconds and then refetches that row once, so a sweep that ends many rows refetches the open row once when that row is one of them. A refetch that an event starts replaces a request already in flight.
- While the socket is live, the loaded log and the open row refetch every 5 minutes, as a safety net for a lost notice. While realtime is down or not configured, they poll every 30 seconds. Those timers keep the filters and do not write `before_id` into the URL. The open row refetches with the task and Process queries. A reconnect does not use this rebuild.
- The refresh rebuilds the loaded rows from the newest page and then replaces the cached pages. The first request omits `before_id`. Each request after that sets `before_id` to the oldest id on the page just returned. It does not reuse a `before_id` saved before the refresh. `useInfiniteQuery` does this when `getNextPageParam` runs on the new page. The requests continue until the rebuilt range passes the oldest id that was loaded before the refresh, or a short page ends the log. Passing that id means the oldest returned id is that id or an older one. The same id is not stored twice. The range has no hole between its newest id and its oldest id.
- The visible anchor is the row at the top of the viewport. When the scroll position is already at the top of the log, the viewport stays there. Otherwise the refresh leaves the anchor row on screen. Rows the refresh adds above the anchor sit above the viewport, and `N new` counts them. When the anchor row is absent from the rebuilt range, the next older remaining row takes that place. The next newer row takes it when no older row remains.
- An insertion is covered by that range. Pages `100..51` and `50..1`, then rows `110..101`, refresh as `110..61`, then `before_id=61` (`60..11`), then `before_id=11` (`10..1`). The cached log is `110..1`. It is not `110..61` followed by the stale page `50..1`.
- A filter-membership change is covered by that range. A loaded row whose new fields do not match is absent from the server pages, so the refresh drops it. The following page starts at the oldest id the new preceding page returned, so a row that backfills a page is not repeated from a stale cursor. A row that newly matches is placed once, in id order, when its id lies inside the rebuilt range.
- When the socket first subscribes, and when it returns after a drop, the page fetches the newest page only and merges those rows by id into the cached log. It does not run the infinite-query refetch, which would request every loaded page. The merge keeps every loaded row, including older pages. An id that is both fetched and cached is stored once, and the fetched fields replace the cached fields. Fetched `110..61` merged with cached `100..51` is `110..51`. Cached `50..1` stays. The log is `110..1`, with no missing id and no repeated id. A cached row the first page omits stays. The viewport anchor and `N new` behave as they do for a notice.
- Opening a row and going back returns to the same filters and the same scroll position.
- `activity:list` and `activity:show` are `activity:*` commands, so those refetches are not broadcast.

## Rejected alternatives

- Broadcast the full Activity record: rejected because `properties` can pass Reverb's 10,000-byte limit and would send operation input to every subscriber without a request.
- Broadcast `activity:*` rows: rejected because the page's list and show would then broadcast, and the page would fetch again.
- Emit `updated` for a sampled read: rejected because that row is inserted once, with its outcome, so `created` already carries the outcome.
- Leave the page on the fleet poll and skip Activity events: rejected because an open page would then wait for the backoff, up to 5 minutes, to show a failure.
- One node filter that matches either the caller or the target: rejected because the log separates who called from what they changed. The two columns stay two filters.
- Leave status, command, and node filters off the web page: rejected because the page is where an operator reads the log, and those filters are how a long list stays readable.
- Drop filters, the log, or the detail on a phone: rejected because a phone opens the same app, and those controls are part of using the page.
- Older and Newer controls, with `before_id` in the URL: rejected because the operator reads one log by scrolling. The cursor is the next request, not a place the URL has to remember. Back from a row returns to the scroll position.
- Refetch the list on each notice, or once for a burst: rejected because the notice already carries every list column. A refetch would reload pages the operator is reading, and a burst would still cost a list request.
- Refetch each loaded page with the `before_id` it had before the refresh: rejected because a new head shifts the first page and the old cursor skips the ids in between. Pages `100..51` and `50..1` plus rows `110..101` would come back as `110..61` and `50..1`, and `60..51` would be missing. A filter that drops a row also backfills the first page, and the stale cursor then repeats that row.
- Replace the cached first page with the refetched page and leave the older pages untouched: rejected because that drops the cached head. Fetched `110..61` would replace `100..51`, and retained `50..1` would not meet it, so `60..51` would be missing. Merging by id keeps the fetched rows and the cached rows.
- Let an inserted row move the rows on screen: rejected because the operator would lose their place. The page keeps that place and offers `N new`.
- Leave the four filters inline on a phone: rejected because the bar takes the width the log needs. One header button opens a sheet.
- Apply the phone filters only when the sheet closes: rejected because the desktop bar updates the URL as the filters change, and the sheet edits that same URL.
- Offset paging: rejected because a new row changes which record sits at each offset. `before_id` keeps an older page on the same rows.
- A separate Activity channel: rejected because every `orbit` subscriber can already read every Activity over HTTP.

## Consequences

- An open Activity page inserts a matching new row into the cached log and patches a loaded row in place. A burst of notices does not refetch the list. An update to the open row still costs one `activity:show` refetch.
- The page's own reads do not broadcast, so a safety-net refetch or a poll does not schedule another refetch.
- Almost every request now broadcasts within the request. A Reverb call gives up after 2 seconds to connect or 3 seconds in total, and a failed broadcast never fails the request, so a slow or unreachable Reverb delays a request by at most a few seconds.
- A successful read inside a sampling window stores no row and broadcasts nothing.
- A client that needs `properties`, the caller address, the subject, or the exit code calls `activity:show`.
- `activity:list` can page past the newest 200 rows and can limit the result to one status, one command, one caller, or one target. The web page asks for 50 rows at a time and sends the same filters. `before_id` is the next request, not a URL field. Changing a filter shows the newest match.
- A patched row stays where it is when its new fields do not match the active filters, so a notice does not pull rows out from under the reader. The next refresh drops that row when the rebuilt range omits it, and it does not leave a duplicate from a stale page.
- A new row does not move the rows the operator is reading. Away from the top, the page shows `N new` and scrolls to the top only when they use that control. A refresh keeps that anchor row on screen.
- The 5-minute safety net and the 30-second poll rebuild the loaded range with recomputed cursors through the oldest id that was already loaded. New head rows add pages instead of opening a hole. A row the server omits leaves the range. A row that newly matches appears once, in id order.
- A reconnect, and the first subscribe, fetch the newest page only and merge it by id. Loaded rows and older pages stay. The same id is stored once. The visible row stays on screen. That path is one list request, not one request per loaded page.
- On a phone, the menu, the continuous log, the Filters sheet, and the detail stay usable. The desktop width keeps the inline filter bar. Back from a row returns to the same scroll position.
- The sweep and the finalizer broadcast once per row they end. A broadcast failure still leaves the row `failed` with `activity.interrupted`.
- Subscribers learn command names, status, Node ids, error codes, and durations they can already read over HTTP. They do not receive `properties`.
- While realtime is down, one open Activity page polls every 30 seconds.

## Affects

- Components: apps/gateway, apps/web, apps/cli, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) and [ADR 0158](/decisions/0158-end-interrupted-activity); keeps [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime) and [ADR 0152](/decisions/0152-sample-successful-read-activity)
- Detail: [Realtime events](/reference/events), [Web app](/reference/web-app), [activity](/cli/activity)
- Verify: Gateway tests for both notices, the `activity:*` omission, created-only reads, sweep and finalizer updates, and the list filters; web tests for inserting a matching `activity.created` into the cache, patching `activity.updated` in place, no list refetch on a notice burst, the open-row show refetch, the 30-second poll, the 5-minute safety net, the filter query, reloading from the newest rows when a filter changes, `before_id` absent from the URL, keeping the rows on screen when a row arrives away from the top, `N new` scrolling to the top, back from a row restoring that scroll position, a periodic refresh after rows `110..101` that rebuilds `110..61`, then `60..11`, then `10..1`, a periodic refresh that omits a row whose status left the filter without repeating a backfilled neighbor, a reconnect that merges fetched `110..61` with cached `100..51` and keeps `50..1` in one list request, and the phone Filters sheet against the desktop filter bar; a desktop check and a phone-width check, at the width where the main navigation collapses into the header menu, that the menu, the log, the Filters button and sheet, and the detail can each be used; CLI tests for the filter options and their error codes
