---
title: "Web app"
description: "How the Gateway serves the Orbit web app at https://gateway.orbit, how bin/web-deploy releases it, and how to roll a release back. An installed iPhone app fills the screen below an opaque status bar."
---

# Web app

This page tells an operator how the Gateway serves the Orbit web app, how to release a new build, and how to roll a release back. It also states how an installed iPhone or iPad copy fills the screen and stays clear of the home indicator. [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin) records why the app shares the Gateway origin.

## Open the app

Open `https://gateway.orbit` from a machine on the Orbit WireGuard network. The browser must trust the Orbit root certificate, which `orbit gateway:trust` installs. The Gateway identifies the browser by its WireGuard address, so there is no login. Pages on other origins cannot call the API, as [Browser access to the Gateway](/reference/browser-access) describes.

## Installed app and safe areas

Add the web app to the home screen on an iPhone or iPad and it opens full screen. The installed app uses an opaque black status bar. The clock sits in that bar, not on the page. The web view starts below the bar and reaches the bottom edge of the screen. The top inset is 0. The shell is as tall as the web view, so the page fills that area. Padding from `env(safe-area-inset-bottom)` keeps the footer above the home indicator. A browser tab is unchanged.

The app shell keeps the page header, the main navigation, and the footer hint clear of the screen edges. On a narrow screen the header holds the menu button, and the navigation is the menu that button opens. The header, the navigation, and the footer hint stay tappable.

While the app is installed, the shell pads the top, the bottom, the left, and the right by the inset for that edge. A home screen launch uses standalone display mode. Turning the device updates the four insets, and the shell follows them. A normal browser tab receives no extra safe-area padding. Safari can report a non-zero inset in a tab, especially in landscape, so the shell does not add padding for a tab inset.

Only the shell applies the padding for the installed app, and it does so once. Pages do not add their own inset padding. A second pad would move page content in from an edge the shell already cleared.

While the app is installed, the shell uses these four insets:

| Edge | Inset |
| --- | --- |
| Top | `env(safe-area-inset-top)` |
| Bottom | `env(safe-area-inset-bottom)` |
| Left | `env(safe-area-inset-left)` |
| Right | `env(safe-area-inset-right)` |

On the installed app the top value resolves to 0, because the web view already starts below the status bar. The bottom value is the home indicator. The left and right values are whatever the device reports for those edges.

[index.html](https://github.com/nckrtl/orbit/blob/main/apps/web/index.html) sets the viewport to include `viewport-fit=cover`. It sets `apple-mobile-web-app-status-bar-style` to `black`. That opaque style places the web view below the status bar. The viewport value lets the web view reach the bottom edge, where the home indicator sits.

iOS reads the `apple-mobile-web-app-*` tags only when the icon is added to the home screen. Remove an existing icon and add the app again after a release changes those tags. An icon left in place keeps the tags from the install.

### Why the status bar is opaque on iOS 26

A translucent status bar does not fill an installed iPhone on iOS 26. [WebKit bug 301108](https://bugs.webkit.org/show_bug.cgi?id=301108) records the failure. With `apple-mobile-web-app-status-bar-style` set to `black-translucent` and `viewport-fit=cover`, the web view starts at y=0, under the status bar. Its height is the screen height minus the top safe-area inset.

`100%`, `100vh`, `100svh`, `100dvh`, and `innerHeight` each come out short by that inset. The missing strip is a dead band at the bottom, and page CSS cannot reach it. Published reports disagree on whether `100lvh` fills that band, so the app does not depend on it.

The translucent bar also lays the iOS 26 edge blur over the top of the page. In production the blur sits on the header. The short web view plus the shell's bottom padding leaves a large empty band under the footer.

The opaque `black` style avoids both faults. The web view starts below the status bar, the top inset is 0, and the view reaches the bottom edge. The shell's existing bottom padding keeps the footer above the home indicator.

### Viewport readout

The Menu drawer shows one line for support. Open it from the Menu button in the header on a narrow screen. The line lists the display mode, the `innerWidth` by `innerHeight` size, the `screen.width` by `screen.height` size, and the four resolved safe-area insets. The insets are top, right, bottom, then left.

## How the Gateway site routes requests

The Gateway's Caddy site sends each request to one of three places.

| Path | Destination |
| --- | --- |
| `/api/*`, `/mcp`, `/mcp/*`, `/up`, `/.well-known/*` | Laravel, as before. |
| `/grafana/*` | The `metrics.orbit` site, after the same WireGuard authorization. |
| Everything else | The current web release. |

A web path without a file returns the release's `index.html`, and the app's router shows the page. Files under `/assets/` carry content hashes, so browsers cache them as immutable. Every other web response must be revalidated, so a new release shows on the next load.

`/grafana/*` checks the browser's address through the Metrics authorization endpoint, removes the `/grafana` prefix, and forwards the request to `metrics.orbit` on the same Caddy. The Metrics publication owns that site and its Grafana upstream. When Metrics is disabled, `/grafana` returns an error and the app shows `—` for Node metrics.

The web app connects to Reverb at the URL that `GET /api/v1/realtime` returns. It needs no Gateway path for realtime.

## Live Node and Process state

The web app subscribes to `presence-node.{id}` for every active Node, next to the `orbit` channel. [Realtime events](/reference/events#node-agent-channels) defines those channels, and the [Node agent](/reference/node-agent) publishes on them.

| Agent state for a Node | Node shows | Process `runtime_status` for the Node's Processes |
| --- | --- | --- |
| Online: `agent.{id}` is a member and sent an event in the last 15 seconds | online | The agent's latest state. A polled value does not replace it. |
| Lost: the agent left, or sent nothing for 15 seconds | offline | The polled value from the Gateway. |
| Not seen since the page subscribed | Prometheus `up`, as before | The polled value from the Gateway. |

A Node without an agent, such as an operator client, always uses the last row. When realtime is not configured, the web app polls as before and shows every Node from Prometheus.

CPU and memory come from [`process.usage`](/reference/events#process-usage) events, which the Gateway sends every 15 seconds while a browser is subscribed. The web app writes each sample into its cached Process list. While realtime is live, it reloads the Process list only when no `process.usage` event arrived for 60 seconds. While realtime is down or not configured, it polls the list every 15 seconds. The Gateway answers that list from its [view of the agents](/reference/node-agent#gateway-view) when the view is fresh, so a reload causes no SSH even when Prometheus is down.

## Live tasks

The web app keeps the task board, each task group, its agent threads, its comments, and the extension status current from [task events](/reference/events#tasks) on the `orbit` channel. [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime) records the design.

| Event | The web app refetches |
| --- | --- |
| `task_group.created`, `task_group.updated` | The task list and that group |
| `task_comment.created` | That subtask's comments |
| `agent_thread.updated` | That group's agent threads and the group |
| `tasks.updated` | Nothing; it stores the new `enabled` value |

The web app waits 100 milliseconds after a task event and then refetches each named query once, so the notices of one Gateway change show a group once.

While realtime is live, these queries refetch every 5 minutes as a safety net for a lost notice, and a refetch that an event starts replaces a request already in flight. When the socket first subscribes, the web app refetches the task and Process queries. A change between that load and the subscription sent no event. When the socket returns after a drop, those queries refetch, because events sent during the drop are lost. Activity merges its refetched first page by id both times. [Live Activity](#live-activity) describes that merge.

While realtime is down or not configured, the task queries poll every 30 seconds. An active group's duration counts forward on the page between refetches. Token and line counts change with the next event for the group, and the agent thread stream shows live tokens for each thread.

## Live Activity

The web app lists Activity in the main navigation, after Tasks and before Quota. The page is `/activity`, and it is one continuous log, newest first. Opening a row goes to `/activity/{id}` and loads it with `activity:show`. [ADR 0159](/decisions/0159-push-activity-changes-to-the-web-app) records the design.

The log loads 50 rows at a time through TanStack Query `useInfiniteQuery`. The next page sets `before_id` to the id of the oldest loaded row. A page with fewer than 50 rows is the end, and the page stops. TanStack Virtual draws the rows. The next page loads when the user scrolls within a few rows of the end.

There is no Older control and no Newer control. `before_id` is not in the URL. The filters stay in the URL.

The page filters by status, command, caller Node, and target Node. An unused filter is omitted. Changing a filter drops the loaded rows and loads the newest rows that match.

| Filter | List query |
| --- | --- |
| Status | `status` of `running`, `succeeded`, or `failed` |
| Command | `command`, the exact name, from 1 to 255 characters |
| Caller Node | `caller_node_id` |
| Target Node | `target_node_id` |

Where the main navigation is inline, the filters sit in a bar on the page. Where that navigation collapses into the header menu, the page header has one Filters button instead. The button shows how many of the four filters are set, including 0. It opens a sheet with those fields, Clear, and Done. Clear removes every filter. Done closes the sheet. The sheet writes the same URL filters as the bar.

While the `orbit` socket is live, a notice is written into the cached pages. The list is not refetched for that notice. A burst of notices does not refetch the list either.

`activity.created` inserts the row at the top when the row matches the active filters. The notice carries every list column, which is enough to decide. A null caller or target does not match that Node filter. An id that is already loaded is not inserted again.

`activity.updated` patches a loaded row where it sits. The row stays in the loaded log when its new fields do not match the filters. A row that is not loaded is not added. The refresh drops that row when the server omits it.

A row added above the viewport leaves the rows on screen where they are. The page then shows a control that reads `N new`, where N is the count of those rows. Tapping the control scrolls to the top and clears it. At the top, the new row appears in the log and the control stays hidden. Scrolling back to the top clears it too.

`activity.updated` for the open row refetches that row with `activity:show`. The notice has no `properties`, so the list payload is not the detail. The page waits 100 milliseconds, then refetches that open row once. A refetch that an event starts replaces a request already in flight. `activity:show` returns the fields the [notice](/reference/events#activity) leaves out.

While realtime is live, the loaded log and the open row refetch every 5 minutes. That is a safety net for a lost notice. While realtime is down or not configured, they poll every 30 seconds. Those timers keep the filters. They do not write `before_id` into the URL. The open row refetches at those same times. A reconnect does not run this rebuild.

The refresh starts at the newest page, which omits `before_id`. The next page uses the oldest id on the page just returned. It does not reuse a cursor saved from the old pages. The requests continue until the rebuilt range passes the oldest id that was loaded before. Passing that id means the oldest returned id is that id or an older one. A short page also ends the refresh. Those pages replace the cached pages. Each id appears once, with no hole from the newest id to the oldest.

The row at the top of the viewport stays on screen. When the log is already scrolled to the top, it stays at the top. Rows the refresh adds above that row sit above the viewport, and `N new` counts them. When that row does not match the filters, the refresh omits it. The next older remaining row takes that place. The next newer row takes it when no older row remains.

For example, pages `100..51` and `50..1`, then rows `110..101`, refresh as `110..61`, then `60..11`, then `10..1`. Replacing the cached head with `110..61` and keeping `50..1` is not a result. A row the server omits leaves the range. The next page starts at the oldest id the new page returned, so a backfilled row is not repeated. A row that newly matches appears once, in id order, when it lies inside the range.

When the socket first subscribes, or comes back after a drop, the page fetches the newest page only and merges it by id. Cached rows stay, including older pages. Each id is stored once, and the fetched fields replace the cached fields. Fetched `110..61` merged with cached `100..51` is `110..51`, and cached `50..1` stays, so the log is `110..1`. A cached row the first page omits stays. The visible row stays on screen, and `N new` counts rows added above it. The open row refetches with the task and Process queries. This merge is one list request.

Opening a row and going back returns to the same filters and the same scroll position. The page's list and show are `activity:*` commands, so the Gateway does not broadcast them. A refetch does not repeat itself.

## Polling

Realtime events keep the fleet lists, the deployment history, and the annotation list current. While the page is subscribed to `orbit`, those views do not poll. When the page goes live after a period without realtime, it reloads those views once, because events sent while the socket was down are lost. That holds after a reconnect, and after a first connect that comes more than 5 seconds after page load.

When realtime is down, or the Gateway offers none, those views poll with a backoff. The first poll comes 30 seconds after the loss. After that, the delay grows with the time realtime has been down, so it doubles each time until it reaches 5 minutes. A reconnect resets it. The footer shows `live updates paused` next to the Gateway name while the page polls. Clicking it reloads every view at once and starts the backoff again.

A hidden tab never polls. When the tab is visible again, it reloads the views whose data is older than 30 seconds.

The task views, the Activity page, and the Process list's CPU and memory have events of their own, as [Live tasks](#live-tasks), [Live Activity](#live-activity), and [Live Node and Process state](#live-node-and-process-state) describe:

| View | While realtime is live | While it is down |
| --- | --- | --- |
| Task board, agents, comments, and task status | Every 5 minutes | Every 30 seconds |
| Activity list and the open Activity | Every 5 minutes, rebuilding the loaded range from recomputed cursors. A notice does not refetch the list. A reconnect merges the first page by id. | Every 30 seconds, using that same rebuild. |
| Process list, for CPU and memory | Only when no `process.usage` event arrived for 60 seconds | Every 15 seconds |

Some views have no event and poll on their own clock while the tab is visible:

| View | Interval |
| --- | --- |
| Database users, on a database page | 15 seconds |
| Live UFW rules, on a Node page | 15 seconds |
| Process logs, on a Process page | 10 seconds |
| Instance logs, queue, and analytics, on an Instance page | 10 seconds |
| Node metrics from Grafana, not the Gateway | 10 seconds |
| Quota (`proxycli`) status and provider pools | 60 seconds |

The firewall list reads every Node's rules with one request, `GET /api/v1/firewall-rules`. It returns only the Nodes that the browser's Node can reach. The CLI keeps the per-Node list.

## Live logs

The Instance log pane and the Process log pane follow their log through a [live log stream](/reference/live-logs) while realtime is live. The pane opens a stream with the lines it shows, 500 for an Instance and 100 for a Process, renews it every 20 seconds, and closes it when the pane closes. It shows `[orbit] N lines dropped` and `[orbit] N MiB skipped` where the stream reports them.

A pane does not poll while its stream runs. It polls the one-shot read every 10 seconds, as before, when the Gateway refuses the stream, when the stream ends with `agent_left`, `source_unavailable`, or `relay_behind`, or when realtime is down. None of these shows an error; a production Instance pane polls from the start. When the socket comes back, the pane opens a new stream.

For a reason that passes on its own, such as `agent_not_joined` during a `websocket` move, the pane also tries a new stream every 30 seconds while it polls. [Live logs](/reference/live-logs#when-the-live-path-is-not-available) lists these reasons. When the first renewal of a stream fails, the pane tries it again after one second, because the agent starts to read only after it.

## Web directory

The web directory is `/home/orbit/web` unless `ORBIT_GATEWAY_WEB` sets another path under `/home/orbit/`. It belongs to the `orbit` user and the `caddy` group.

| Path | Contents |
| --- | --- |
| `releases/<commit>` | One built release, named after the 12-character commit it was built from. |
| `current` | A link to the release that the Gateway serves. |

`orbit:bootstrap` creates the directory and publishes the site. After a Gateway deploy that changes the site, run `php artisan orbit:gateway-web` in the Gateway checkout. It installs Caddy from the [pinned source](/reference/node-provisioning#package-sources), creates the directory, publishes the Gateway certificate with the public root certificate, and publishes the site, without changing roles, VPN settings, or the Gateway Node. Gateway deploys do not change the releases or `current`.

## Release a build

Run `bin/web-deploy` from a clean checkout of the commit to release.

```bash
bin/web-deploy
```

The command refuses a working tree with uncommitted changes. It checks the commit out into a temporary worktree and builds it there with a minimal environment, so ignored files such as `apps/web/.env.local` and `VITE_*` shell variables never reach a release. It installs the locked dependencies of `packages/agent-annotation` and `apps/web`, then builds `apps/web`. It uploads the build to `releases/<commit>` and switches `current` to it in one rename. It keeps the five newest releases and never removes the current one. Releasing a commit that already exists replaces that release.

These environment variables change the target.

| Variable | Default | Meaning |
| --- | --- | --- |
| `ORBIT_WEB_DEPLOY_HOST` | `orbit@gateway` | SSH destination of the Gateway host. |
| `ORBIT_WEB_DEPLOY_SSH` | `ssh` | SSH command, including options such as `-i KEY`. |
| `ORBIT_WEB_DIR` | `/home/orbit/web` | Web directory on the Gateway host. |
| `ORBIT_WEB_GROUP` | `caddy` | Group that must read the release. |

## Roll back

Switch `current` to a retained release without building.

```bash
bin/web-deploy --switch <commit>
```

The command refuses a commit that has no retained release. The Gateway serves the older release on the next request.
